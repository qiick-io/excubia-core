<?php

declare(strict_types=1);

use JsonSchema\Constraints\Constraint;
use JsonSchema\Validator;
use Qiick\Excubia\Configuration\ExcubiaConfigurationLoader;
use Qiick\Excubia\Console\CheckCommand;
use Qiick\Excubia\Data\EnvExampleSyncConfigurationData;
use Qiick\Excubia\Exceptions\InvalidConfigurationException;
use Qiick\Excubia\Tests\Support\FakeHttpClient;
use Symfony\Component\Console\Tester\CommandTester;

const ENV_SECRET_SENTINEL = 'sentinel-value-from-dot-env-7f3a';

const ENV_KEY_SENTINEL = 'ONLY_IN_DOT_ENV_SENTINEL';

/**
 * Records every path the `file://` stream wrapper opens or stats while a
 * callback runs, so a test can prove that `.env` is never touched. Each
 * operation is delegated to the native wrapper.
 */
final class FileAccessRecordingStreamWrapper
{
    /** @var list<string> */
    public static array $paths = [];

    /** @var resource|null */
    public $context;

    /** @var resource|false */
    private $handle = false;

    /**
     * @return list<string>
     */
    public static function record(callable $callback): array
    {
        self::$paths = [];
        stream_wrapper_unregister('file');
        stream_wrapper_register('file', self::class);

        try {
            $callback();
        } finally {
            stream_wrapper_restore('file');
        }

        return self::$paths;
    }

    private static function native(callable $operation): mixed
    {
        stream_wrapper_restore('file');

        try {
            return $operation();
        } finally {
            stream_wrapper_unregister('file');
            stream_wrapper_register('file', self::class);
        }
    }

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        self::$paths[] = $path;
        $this->handle = self::native(fn () => file_exists($path) || ! str_starts_with($mode, 'r') ? fopen($path, $mode) : false);

        return $this->handle !== false;
    }

    public function stream_read(int $count): string|false
    {
        return $this->handle === false ? false : fread($this->handle, max(1, $count));
    }

    public function stream_write(string $data): int|false
    {
        return $this->handle === false ? false : fwrite($this->handle, $data);
    }

    public function stream_eof(): bool
    {
        return $this->handle === false || feof($this->handle);
    }

    public function stream_tell(): int|false
    {
        return $this->handle === false ? false : ftell($this->handle);
    }

    public function stream_seek(int $offset, int $whence): bool
    {
        return $this->handle !== false && fseek($this->handle, $offset, $whence) === 0;
    }

    /**
     * @return array<int|string, int>|false
     */
    public function stream_stat(): array|false
    {
        return $this->handle === false ? false : fstat($this->handle);
    }

    public function stream_close(): void
    {
        if ($this->handle !== false) {
            fclose($this->handle);
        }
    }

    public function stream_set_option(int $option, int $firstArgument, ?int $secondArgument): bool
    {
        return false;
    }

    /**
     * @return array<int|string, int>|false
     */
    public function url_stat(string $path, int $flags): array|false
    {
        self::$paths[] = $path;

        return self::native(function () use ($path, $flags): array|false {
            if (($flags & STREAM_URL_STAT_LINK) !== 0) {
                return is_link($path) || file_exists($path) ? lstat($path) : false;
            }

            return file_exists($path) ? stat($path) : false;
        });
    }
}

/**
 * A Laravel-like project: `config/app.php` and `config/services.php` read
 * keys, `.env.example` lists some of them, and a `.env` holds a sentinel
 * key and value that must never reach the report. `null` drops a file.
 *
 * @param  array<string, string|null>  $files
 */
function envSyncProject(array $files = []): string
{
    $defaults = [
        'composer.lock' => '{"packages": [], "packages-dev": [], "platform": {"php": "8.3.0"}}',
        'config/app.php' => <<<'PHP'
            <?php

            return [
                'name' => env('APP_NAME', 'Laravel'),
                'env' => \env('APP_ENV', 'production'),
                'key' => Illuminate\Support\Env::get('APP_KEY'),
                'url' => env("APP_URL"),
                // env('COMMENTED_OUT')
                'previous_keys' => explode(',', (string) env('APP_PREVIOUS_KEYS', '')),
            ];
            PHP,
        'config/services.php' => <<<'PHP'
            <?php

            use Illuminate\Support\Env;

            return [
                'stripe' => ['secret' => env('STRIPE_SECRET')],
                'mailgun' => ['domain' => Env::getOrFail('MAILGUN_DOMAIN')],
                'app' => ['name' => env('APP_NAME')],
                'computed' => env('PREFIX_'.'KEY'),
            ];
            PHP,
        '.env.example' => <<<'ENV'
            # Application
            APP_NAME="Laravel"
            export APP_ENV=local
            APP_KEY=
            # APP_URL=http://localhost

            LEGACY_FLAG='one
            NOT_A_KEY=still inside the quoted value'
            VITE_APP_NAME="${APP_NAME}"
            ENV,
        '.env' => "APP_NAME=Real\nAPP_KEY=".ENV_SECRET_SENTINEL."\n".ENV_KEY_SENTINEL.'='.ENV_SECRET_SENTINEL."\n",
    ];

    return temporaryProject(array_filter([...$defaults, ...$files], fn (?string $contents): bool => $contents !== null));
}

/**
 * @param  array<string, mixed>  $options
 * @return array{exitCode: int, display: string}
 */
function envSyncCheck(string $project, array $options = []): array
{
    $client = new FakeHttpClient([]);
    $tester = new CommandTester(new CheckCommand($client));
    $exitCode = $tester->execute([
        'path' => $project,
        '--only' => 'env-example-sync',
        '--no-banner' => true,
        '--no-cache' => true,
        ...$options,
    ]);

    expect($client->calls)->toBe(0);

    return ['exitCode' => $exitCode, 'display' => $tester->getDisplay()];
}

/**
 * @return array<string, mixed>
 */
function envSyncJsonReport(string $project, array $options = []): array
{
    return json_decode(envSyncCheck($project, ['--format' => 'json'] + $options)['display'], true, 512, JSON_THROW_ON_ERROR);
}

/**
 * The rows as `subject => finding`.
 *
 * @param  array<string, mixed>  $report
 * @return array<string, array<string, mixed>>
 */
function envSyncRows(array $report): array
{
    return array_column($report['checks'][0]['findings'], null, 'subject');
}

it('warns at low for each key read in config but missing from .env.example', function () {
    $report = envSyncJsonReport(envSyncProject());
    $rows = envSyncRows($report);

    expect($report['checks'][0]['checkId'])->toBe('env-example-sync')
        ->and($report['checks'][0]['status'])->toBe('warn')
        ->and(array_keys($rows))->toBe(['MAILGUN_DOMAIN', 'STRIPE_SECRET', 'unused keys'])
        ->and($rows['STRIPE_SECRET']['status'])->toBe('warn')
        ->and($rows['STRIPE_SECRET']['severity'])->toBe('low')
        ->and($rows['STRIPE_SECRET']['message'])->toBe('STRIPE_SECRET is read by config/services.php but missing from .env.example.')
        ->and($rows['STRIPE_SECRET']['details'])->toBe(['key' => 'STRIPE_SECRET', 'configFiles' => ['config/services.php']])
        ->and($rows['MAILGUN_DOMAIN']['details'])->toBe(['key' => 'MAILGUN_DOMAIN', 'configFiles' => ['config/services.php']]);
});

it('lists the keys no config file reads in one info row', function () {
    $rows = envSyncRows(envSyncJsonReport(envSyncProject()));

    expect($rows['unused keys']['status'])->toBe('warn')
        ->and($rows['unused keys']['severity'])->toBe('info')
        ->and($rows['unused keys']['details'])->toBe(['keys' => ['LEGACY_FLAG']])
        ->and($rows['unused keys']['message'])->toBe('1 key in .env.example not read by config/*.php: LEGACY_FLAG.');
});

it('passes when every key is listed and read', function () {
    $project = envSyncProject([
        'config/services.php' => null,
        '.env.example' => "APP_NAME=\nAPP_ENV=\nAPP_KEY=\nAPP_URL=\n",
    ]);
    $report = envSyncJsonReport($project);

    expect($report['checks'][0]['status'])->toBe('pass')
        ->and($report['checks'][0]['findings'])->toBe([])
        ->and(envSyncCheck($project, ['--fail-on' => 'low'])['exitCode'])->toBe(0);
});

it('leaves out ignored keys in both directions, with and without the defaults', function () {
    $extended = envSyncRows(envSyncJsonReport(envSyncProject([
        'excubia.json' => '{"checks": {"env-example-sync": {"ignoreKeys": ["stripe_*", "LEGACY_FLA?"]}}}',
    ])));
    $replaced = envSyncRows(envSyncJsonReport(envSyncProject([
        'excubia.json' => '{"checks": {"env-example-sync": {"ignoreKeys": ["MAILGUN_DOMAIN"], "useDefaultIgnoreKeys": false}}}',
    ])));

    expect(array_keys($extended))->toBe(['MAILGUN_DOMAIN'])
        ->and(array_keys($replaced))->toBe(['APP_PREVIOUS_KEYS', 'STRIPE_SECRET', 'unused keys'])
        ->and($replaced['unused keys']['details'])->toBe(['keys' => ['LEGACY_FLAG', 'VITE_APP_NAME']]);
});

it('lets an ignore rule accept one key', function () {
    $rows = envSyncRows(envSyncJsonReport(envSyncProject([
        'excubia.json' => '{"ignore": [{"checkId": "env-example-sync", "subject": "STRIPE_SECRET", "reason": "Set by the platform."}]}',
    ])));

    expect(array_keys($rows))->toBe(['MAILGUN_DOMAIN', 'unused keys']);
});

it('is skipped as not-applicable without .env.example or config', function (array $files, string $subject, string $message) {
    $report = envSyncJsonReport(envSyncProject($files));
    $finding = $report['checks'][0]['findings'][0];

    expect($report['checks'][0]['status'])->toBe('skipped')
        ->and($finding['subject'])->toBe($subject)
        ->and($finding['message'])->toBe($message)
        ->and($finding['skipReason'])->toBe('not-applicable');
})->with([
    'no .env.example' => [['.env.example' => null], '.env.example', 'No .env.example in the project.'],
    'no config directory' => [['config/app.php' => null, 'config/services.php' => null], 'config', 'No config directory in the project.'],
]);

it('reports an empty config directory as all keys unused', function () {
    $project = envSyncProject(['config/app.php' => null, 'config/services.php' => null]);
    mkdir($project.'/config');

    $rows = envSyncRows(envSyncJsonReport($project));

    expect(array_keys($rows))->toBe(['unused keys'])
        ->and($rows['unused keys']['details'])->toBe(['keys' => ['APP_ENV', 'APP_KEY', 'APP_NAME', 'APP_URL', 'LEGACY_FLAG']]);
});

it('never exits 1 below the low threshold and exits 1 at --fail-on=low', function () {
    expect(envSyncCheck(envSyncProject())['exitCode'])->toBe(0)
        ->and(envSyncCheck(envSyncProject(), ['--fail-on' => 'low'])['exitCode'])->toBe(1);
});

it('emits a schema-valid report and points SARIF results at .env.example', function () {
    $report = envSyncJsonReport(envSyncProject());
    $schema = json_decode((string) file_get_contents(dirname(__DIR__, 2).'/docs/report-schema.json'), false, 512, JSON_THROW_ON_ERROR);
    $schema->{'$schema'} = 'http://json-schema.org/draft-07/schema#';
    $validator = new Validator;
    $document = json_decode(json_encode($report, JSON_THROW_ON_ERROR), false, 512, JSON_THROW_ON_ERROR);
    $validator->validate($document, $schema, Constraint::CHECK_MODE_STRICT);

    $sarif = json_decode(envSyncCheck(envSyncProject(), ['--format' => 'sarif'])['display'], true, 512, JSON_THROW_ON_ERROR);
    $locations = array_unique(array_map(
        fn (array $result): string => $result['locations'][0]['physicalLocation']['artifactLocation']['uri'],
        $sarif['runs'][0]['results'],
    ));

    expect($validator->getErrors())->toBe([])
        ->and(array_values($locations))->toBe(['.env.example'])
        ->and(array_column($sarif['runs'][0]['results'], 'level'))->toBe(['note', 'note', 'note']);
});

it('never opens or stats .env and never reports a value', function (string $format) {
    $project = envSyncProject();
    envSyncCheck($project, ['--format' => $format]);

    $display = '';
    $paths = FileAccessRecordingStreamWrapper::record(function () use ($project, $format, &$display): void {
        $display = envSyncCheck($project, ['--format' => $format])['display'];
    });
    $projectPaths = array_values(array_filter($paths, fn (string $path): bool => str_starts_with($path, $project)));

    expect($projectPaths)->toContain($project.DIRECTORY_SEPARATOR.'.env.example')
        ->and($projectPaths)->toContain($project.DIRECTORY_SEPARATOR.'config'.DIRECTORY_SEPARATOR.'services.php')
        ->and(array_filter($projectPaths, fn (string $path): bool => basename($path) === '.env'))->toBe([])
        ->and($display)->not->toContain(ENV_SECRET_SENTINEL)
        ->and($display)->not->toContain(ENV_KEY_SENTINEL)
        ->and($display)->not->toContain('still inside the quoted value')
        ->and($display)->not->toContain('NOT_A_KEY')
        ->and($display)->not->toContain('http://localhost')
        ->and($display)->not->toContain($project);
})->with(['json', 'table', 'markdown', 'sarif']);

it('loads checks.env-example-sync and defaults to the documented ignore list', function () {
    $loader = new ExcubiaConfigurationLoader;
    $defaults = $loader->load(null, temporaryProject([]))->envExampleSync;
    $loaded = $loader->load(null, temporaryProject([
        'excubia.json' => '{"checks": {"env-example-sync": {"ignoreKeys": ["STRIPE_*"], "useDefaultIgnoreKeys": false}}}',
    ]))->envExampleSync;

    expect($defaults->ignoreKeys)->toBe([])
        ->and($defaults->shouldUseDefaultIgnoreKeys)->toBeTrue()
        ->and($defaults->isIgnored('AWS_BUCKET'))->toBeTrue()
        ->and($defaults->isIgnored('vite_app_name'))->toBeTrue()
        ->and($defaults->isIgnored('APP_NAME'))->toBeFalse()
        ->and($loaded->ignoreKeys)->toBe(['STRIPE_*'])
        ->and($loaded->isIgnored('STRIPE_SECRET'))->toBeTrue()
        ->and($loaded->isIgnored('AWS_BUCKET'))->toBeFalse();
});

it('rejects invalid checks.env-example-sync settings', function (string $document, string $message) {
    expect(fn () => (new ExcubiaConfigurationLoader)->load(null, temporaryProject(['excubia.json' => $document])))
        ->toThrow(InvalidConfigurationException::class, $message);
})->with([
    'not an object' => ['{"checks": {"env-example-sync": true}}', 'checks.env-example-sync must be an object.'],
    'unknown key' => ['{"checks": {"env-example-sync": {"enabled": false}}}', 'Unknown checks.env-example-sync key [enabled].'],
    'ignoreKeys not a list' => ['{"checks": {"env-example-sync": {"ignoreKeys": "AWS_*"}}}', 'checks.env-example-sync.ignoreKeys must be a list of strings.'],
    'glob with a dash' => ['{"checks": {"env-example-sync": {"ignoreKeys": ["AWS-*"]}}}', 'checks.env-example-sync.ignoreKeys[0] must be an environment key name or glob'],
    'useDefaultIgnoreKeys not a boolean' => ['{"checks": {"env-example-sync": {"useDefaultIgnoreKeys": "no"}}}', 'checks.env-example-sync.useDefaultIgnoreKeys must be a boolean.'],
]);

it('reports nothing for a fresh Laravel application with the default ignore list', function () {
    $skeleton = dirname(__DIR__, 2).'/vendor/orchestra/testbench-core/laravel';
    $files = ['.env.example' => (string) file_get_contents($skeleton.'/.env.example')];

    foreach (glob(dirname(__DIR__, 2).'/vendor/laravel/framework/config/*.php') ?: [] as $configFile) {
        $files['config/'.basename($configFile)] = (string) file_get_contents($configFile);
    }

    $report = envSyncJsonReport(envSyncProject($files));

    expect($report['checks'][0]['findings'])->toBe([])
        ->and(count(EnvExampleSyncConfigurationData::DEFAULT_IGNORE_KEYS))->toBeGreaterThan(10);
});
