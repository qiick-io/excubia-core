<?php

declare(strict_types=1);

use Qiick\Excubia\Checks\EnvExampleSyncCheck;
use Qiick\Excubia\Data\ExcubiaConfigurationData;
use Qiick\Excubia\Data\ProjectSnapshotData;
use Qiick\Excubia\Enums\FindingStatusEnum;
use Qiick\Excubia\Parsers\ConfigEnvironmentCallParser;
use Qiick\Excubia\Parsers\EnvironmentExampleParser;

it('finds literal env() keys with the tokenizer and ignores look-alikes', function () {
    $source = <<<'PHP'
        <?php

        namespace App\Config;

        use Illuminate\Support\Env;

        function env($key) { return $key; }

        return [
            'a' => env('PLAIN'),
            'b' => ENV( 'UPPER_CASE_CALL' , 'default'),
            'c' => \env('FULLY_QUALIFIED'),
            'd' => Env::get('ENV_CLASS'),
            'e' => \Illuminate\Support\Env::getOrFail('ENV_CLASS_FQ'),
            'f' => env('OUTER', env('NESTED')),
            'g' => $this->env('METHOD_CALL'),
            'h' => $object?->env('NULLSAFE_CALL'),
            'i' => Other::env('STATIC_METHOD'),
            'j' => env('COMPUTED_'.$suffix),
            'k' => env("INTERPOLATED_{$suffix}"),
            'l' => env($variable),
            'm' => env('has-dash'),
            'n' => Env::other('OTHER_METHOD'),
            'o' => App\env('NAMESPACED_FUNCTION'),
            // env('IN_A_COMMENT')
            /** env('IN_A_DOC_COMMENT') */
            'p' => 'env(\'IN_A_STRING\')',
            'q' => env('PLAIN'),
            'r' => env("DOUBLE_QUOTED"),
        ];
        PHP;

    expect((new ConfigEnvironmentCallParser)->keyNames($source))->toBe([
        'PLAIN',
        'UPPER_CASE_CALL',
        'FULLY_QUALIFIED',
        'ENV_CLASS',
        'ENV_CLASS_FQ',
        'OUTER',
        'NESTED',
        'DOUBLE_QUOTED',
    ]);
});

it('does not execute the config file', function () {
    $source = "<?php\nthrow new RuntimeException('executed');\nreturn ['a' => env('STILL_FOUND')];\n";

    expect((new ConfigEnvironmentCallParser)->keyNames($source))->toBe(['STILL_FOUND'])
        ->and((new ConfigEnvironmentCallParser)->keyNames('<?php return [env('))->toBe([]);
});

it('reads the key names of .env.example and drops the values', function () {
    $contents = "# Comment line\r\n"
        ."APP_NAME=Laravel\r\n"
        ."export APP_ENV=local\n"
        ."  SPACED_KEY = value\n"
        ."#DB_HOST=127.0.0.1\n"
        ."# DB_PORT=3306\n"
        ."# Just a comment = not a key\n"
        ."\n"
        ."MULTI_LINE=\"first\n"
        ."INSIDE_DOUBLE=\\\"escaped quote stays open\n"
        ."last\"\n"
        ."SINGLE='one\n"
        ."INSIDE_SINGLE=two'\n"
        ."CLOSED_ON_ONE_LINE=\"done\"\n"
        ."ESCAPED_BACKSLASH=\"ends with \\\\\"\n"
        ."AFTER_ESCAPE=yes\n"
        ."not a line\n"
        ."1INVALID=x\n"
        ."APP_NAME=duplicate\n";

    expect((new EnvironmentExampleParser)->keyNames($contents))->toBe([
        'AFTER_ESCAPE',
        'APP_ENV',
        'APP_NAME',
        'CLOSED_ON_ONE_LINE',
        'DB_HOST',
        'DB_PORT',
        'ESCAPED_BACKSLASH',
        'MULTI_LINE',
        'SINGLE',
        'SPACED_KEY',
    ]);
});

it('skips env-example-sync for a snapshot not read from a project directory', function () {
    $result = (new EnvExampleSyncCheck)->run(new ProjectSnapshotData('8.3.0', null, [], [], null), ExcubiaConfigurationData::defaults());

    expect($result->status)->toBe(FindingStatusEnum::Skipped)
        ->and($result->findings[0]->subject)->toBe('.env.example');
});
