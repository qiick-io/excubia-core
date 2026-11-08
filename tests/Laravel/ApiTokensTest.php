<?php

declare(strict_types=1);

use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\GenericUser;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use JsonSchema\Validator;
use Laravel\Passport\Passport;
use Laravel\Sanctum\Events\TokenAuthenticated;
use Qiick\Excubia\Checks\LaravelApiTokensCheck;
use Qiick\Excubia\Contracts\HttpClientContract;
use Qiick\Excubia\Data\CheckResultData;
use Qiick\Excubia\Data\ExcubiaConfigurationData;
use Qiick\Excubia\Data\ProjectSnapshotData;
use Qiick\Excubia\Enums\FindingStatusEnum;
use Qiick\Excubia\Enums\SeverityEnum;
use Qiick\Excubia\Laravel\ExcubiaServiceProvider;
use Qiick\Excubia\Laravel\LaravelPostureService;
use Qiick\Excubia\Services\RuntimeBufferService;
use Qiick\Excubia\Tests\Support\FakeHttpClient;

require_once dirname(__DIR__).'/Support/FakeApiTokenClasses.php';

/*
 * Laravel Sanctum and Passport (#219): the `laravel-api-tokens` check and
 * the `auth` runtime type, with both packages faked under their real names
 * (tests/Support/FakeApiTokenClasses.php) and their tables created by their
 * own migrations (tests/Fixtures/Migrations): neither is a dependency.
 */
const API_TOKENS_MARKER = 'excubiaapitokenmarker5d1e';

beforeEach(function () {
    Passport::$tokensExpireIn = null;
    Passport::$refreshTokensExpireIn = null;
    Passport::$personalAccessTokensExpireIn = null;
    Passport::$keyPath = null;
});

// Testbench rolls the fixture migrations back at teardown, which asks for confirmation in production.
afterEach(function () {
    $this->app['env'] = 'testing';
});

/**
 * `laravel-api-tokens` on the booted test app, with these `sanctum` and
 * `passport` configurations (null leaves one unset).
 *
 * @param  array<string, mixed>|null  $sanctum
 * @param  array<string, mixed>|null  $passport
 */
function apiTokensCheckResult(?array $sanctum, ?array $passport, string $environment = 'production', bool $isBooted = true): CheckResultData
{
    $snapshot = new ProjectSnapshotData(null, null, [], [], null);
    config(['sanctum' => $sanctum, 'passport' => $passport]);
    app()['env'] = $environment;

    if ($isBooted) {
        $snapshot = $snapshot->withLaravelPosture((new LaravelPostureService)->collect(app(), checkIds: ['laravel-api-tokens']));
    }

    return (new LaravelApiTokensCheck)->run($snapshot, ExcubiaConfigurationData::defaults());
}

/**
 * @return array<string, array{severity: SeverityEnum, status: FindingStatusEnum}>
 */
function apiTokensFindingsBySubject(CheckResultData $result): array
{
    $findings = [];

    foreach ($result->findings as $finding) {
        $findings[$finding->subject] = ['severity' => $finding->severity, 'status' => $finding->status];
    }

    ksort($findings);

    return $findings;
}

/**
 * Sanctum's configuration as its config file ships it, with these changes.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function sanctumConfiguration(array $overrides = []): array
{
    return [
        'stateful' => ['localhost', 'app.'.API_TOKENS_MARKER.'.test'],
        'guard' => ['web'],
        'expiration' => null,
        'token_prefix' => '',
        ...$overrides,
    ];
}

/**
 * A Sanctum token row: the token hash, name and abilities carry the marker.
 */
function insertSanctumToken(string $createdAt, ?string $lastUsedAt, ?string $expiresAt = null): void
{
    static $id = 0;
    $id++;

    DB::table('personal_access_tokens')->insert([
        'tokenable_type' => 'App\\Models\\User',
        'tokenable_id' => 4242 + $id,
        'name' => API_TOKENS_MARKER.' token '.$id,
        'token' => hash('sha256', API_TOKENS_MARKER.$id),
        'abilities' => json_encode(['orders:'.API_TOKENS_MARKER]),
        'last_used_at' => $lastUsedAt,
        'expires_at' => $expiresAt,
        'created_at' => $createdAt,
        'updated_at' => $createdAt,
    ]);
}

/**
 * Passport keys in a fresh directory, the private one with this mode.
 */
function passportKeys(int $privateKeyMode): void
{
    $directory = temporaryProject([
        'oauth-private.key' => '-----BEGIN PRIVATE KEY-----'.API_TOKENS_MARKER,
        'oauth-public.key' => '-----BEGIN PUBLIC KEY-----'.API_TOKENS_MARKER,
    ]);
    chmod($directory.'/oauth-private.key', $privateKeyMode);
    Passport::$keyPath = $directory;
}

function tokenTimestamp(string $modifier): string
{
    return (new DateTimeImmutable($modifier))->format('Y-m-d H:i:s');
}

describe('laravel-api-tokens', function () {
    it('is skipped without a posture, and when neither Sanctum nor Passport is configured', function () {
        expect(apiTokensCheckResult(null, null)->findings[0]->message)->toBe('Neither laravel/sanctum nor laravel/passport is installed.')
            ->and(apiTokensCheckResult(sanctumConfiguration(), null, isBooted: false)->status)->toBe(FindingStatusEnum::Skipped);
    });

    it('counts unexpired Sanctum tokens, stale and never used ones, and warns that tokens never expire', function () {
        $this->loadMigrationsFrom(dirname(__DIR__).'/Fixtures/Migrations/ApiTokens');
        insertSanctumToken(tokenTimestamp('-200 days'), tokenTimestamp('-1 day'));
        insertSanctumToken(tokenTimestamp('-200 days'), tokenTimestamp('-120 days'));
        insertSanctumToken(tokenTimestamp('-200 days'), null);
        insertSanctumToken(tokenTimestamp('-2 days'), null);
        insertSanctumToken(tokenTimestamp('-2 days'), null, tokenTimestamp('+30 days'));
        insertSanctumToken(tokenTimestamp('-200 days'), null, tokenTimestamp('-1 day'));

        $result = apiTokensCheckResult(sanctumConfiguration(), null);
        $local = apiTokensCheckResult(sanctumConfiguration(), null, environment: 'local');
        $json = json_encode($result, JSON_THROW_ON_ERROR);

        expect(apiTokensFindingsBySubject($result))->toBe([
            'sanctum.expiration' => ['severity' => SeverityEnum::Medium, 'status' => FindingStatusEnum::Warn],
            'sanctum.stale-tokens' => ['severity' => SeverityEnum::Low, 'status' => FindingStatusEnum::Warn],
        ])
            ->and($result->findings[0]->details)->toBe([
                'provider' => 'sanctum',
                'expirationMinutes' => null,
                'hasStatefulWildcard' => false,
                'isStatefulEmpty' => false,
                'tokenCount' => 5,
                'nonExpiringTokenCount' => 4,
                'staleTokenCount' => 2,
                'neverUsedTokenCount' => 3,
            ])
            ->and($result->findings[0]->message)->toContain('4 tokens have no expires_at')
            ->and($result->findings[1]->message)->toContain('2 Sanctum tokens have not been used for 90 days or more (3 never used)')
            ->and(apiTokensFindingsBySubject($local)['sanctum.expiration']['severity'])->toBe(SeverityEnum::Low)
            ->and($json)->not->toContain(API_TOKENS_MARKER)
            ->and($json)->not->toContain('4243')
            ->and($json)->not->toContain('orders:')
            ->and($json)->not->toContain('localhost');
    });

    it('drops tokens older than sanctum.expiration and passes when every token expires', function () {
        $this->loadMigrationsFrom(dirname(__DIR__).'/Fixtures/Migrations/ApiTokens');
        insertSanctumToken(tokenTimestamp('-2 days'), tokenTimestamp('-1 day'));
        insertSanctumToken(tokenTimestamp('-10 minutes'), null);
        insertSanctumToken(tokenTimestamp('-10 minutes'), null, tokenTimestamp('+1 day'));

        $expiring = apiTokensCheckResult(sanctumConfiguration(['expiration' => '1440']), null);
        DB::table('personal_access_tokens')->whereNull('expires_at')->update(['expires_at' => tokenTimestamp('+1 day')]);
        $perToken = apiTokensCheckResult(sanctumConfiguration(), null);

        expect($expiring->status)->toBe(FindingStatusEnum::Pass)
            ->and($expiring->findings[0]->message)->toBe('Sanctum tokens expire after 1440 minutes; 2 active.')
            ->and($expiring->findings[0]->details)->toMatchArray(['expirationMinutes' => 1440, 'tokenCount' => 2, 'nonExpiringTokenCount' => 1, 'neverUsedTokenCount' => 2])
            ->and(apiTokensFindingsBySubject($perToken))->toBe(['sanctum' => ['severity' => SeverityEnum::Info, 'status' => FindingStatusEnum::Pass]]);
    });

    it('skips the token counts without the table, and checks sanctum.stateful in production only', function (array $stateful, ?array $expected) {
        $production = apiTokensCheckResult(sanctumConfiguration(['stateful' => $stateful, 'expiration' => 60]), null);
        $local = apiTokensCheckResult(sanctumConfiguration(['stateful' => $stateful, 'expiration' => 60]), null, environment: 'local');

        expect($production->findings[0]->details)->toMatchArray(['tokenCount' => null, 'staleTokenCount' => null, 'neverUsedTokenCount' => null])
            ->and(apiTokensFindingsBySubject($production)['sanctum.stateful'] ?? null)->toBe($expected)
            ->and(apiTokensFindingsBySubject($local))->toBe(['sanctum' => ['severity' => SeverityEnum::Info, 'status' => FindingStatusEnum::Pass]]);
    })->with([
        'a wildcard' => [['localhost', '*.'.API_TOKENS_MARKER.'.test'], ['severity' => SeverityEnum::Medium, 'status' => FindingStatusEnum::Warn]],
        'no domain' => [[''], ['severity' => SeverityEnum::Low, 'status' => FindingStatusEnum::Warn]],
        'exact domains' => [['localhost', 'app.example.test'], null],
    ]);

    it('fails in production without Passport keys, and with a private key others can read', function () {
        $missing = apiTokensCheckResult(null, ['private_key' => null, 'public_key' => null]);
        $missingLocal = apiTokensCheckResult(null, ['private_key' => null, 'public_key' => null], environment: 'local');
        passportKeys(0644);
        $open = apiTokensCheckResult(null, ['private_key' => null, 'public_key' => null]);
        $json = json_encode($open, JSON_THROW_ON_ERROR);

        expect(apiTokensFindingsBySubject($missing)['passport.keys'])->toBe(['severity' => SeverityEnum::High, 'status' => FindingStatusEnum::Fail])
            ->and(apiTokensFindingsBySubject($missingLocal)['passport.keys'])->toBe(['severity' => SeverityEnum::Medium, 'status' => FindingStatusEnum::Warn])
            ->and($missing->findings[0]->details)->toMatchArray(['hasKeys' => false, 'isPrivateKeyTooOpen' => null, 'personalAccessClientCount' => null])
            ->and(apiTokensFindingsBySubject($open))->toBe([
                'passport.key-permissions' => ['severity' => SeverityEnum::High, 'status' => FindingStatusEnum::Fail],
                'passport.token-lifetimes' => ['severity' => SeverityEnum::Low, 'status' => FindingStatusEnum::Warn],
            ])
            ->and($open->findings[0]->details)->toMatchArray(['hasKeys' => true, 'isPrivateKeyTooOpen' => true, 'accessTokenLifetimeMinutes' => 525600])
            ->and($open->findings[1]->message)->toStartWith('Passport access tokens live very long')
            ->and($json)->not->toContain(API_TOKENS_MARKER)
            ->and($json)->not->toContain((string) Passport::$keyPath);
    })->skip(PHP_OS_FAMILY === 'Windows', 'file modes');

    it('passes with 0600 keys or keys from the configuration and bounded lifetimes, counting active personal access clients (Passport 13)', function () {
        $this->loadMigrationsFrom(dirname(__DIR__).'/Fixtures/Migrations/ApiTokens');
        $client = ['owner_type' => null, 'owner_id' => null, 'secret' => null, 'provider' => 'users', 'redirect_uris' => '[]', 'created_at' => tokenTimestamp('now'), 'updated_at' => tokenTimestamp('now')];
        DB::table('oauth_clients')->insert([
            [...$client, 'id' => 'a1', 'name' => API_TOKENS_MARKER, 'grant_types' => '["personal_access"]', 'revoked' => false],
            [...$client, 'id' => 'a2', 'name' => API_TOKENS_MARKER, 'grant_types' => '["personal_access"]', 'revoked' => true],
            [...$client, 'id' => 'a3', 'name' => API_TOKENS_MARKER, 'grant_types' => '["authorization_code","refresh_token"]', 'revoked' => false],
        ]);
        Passport::$tokensExpireIn = new DateInterval('PT1H');
        Passport::$refreshTokensExpireIn = new DateInterval('P30D');
        passportKeys(0600);

        $files = apiTokensCheckResult(null, ['private_key' => null, 'public_key' => null, 'connection' => null]);
        Passport::$keyPath = '/nonexistent';
        $configured = apiTokensCheckResult(null, ['private_key' => "-----BEGIN PRIVATE KEY-----\n".API_TOKENS_MARKER, 'public_key' => "-----BEGIN PUBLIC KEY-----\n".API_TOKENS_MARKER]);
        Passport::$personalAccessTokensExpireIn = new DateInterval('P5Y');
        $long = apiTokensCheckResult(null, ['private_key' => null, 'public_key' => null]);

        expect($files->status)->toBe(FindingStatusEnum::Pass)
            ->and($files->findings[0]->message)->toBe('Passport keys are in place and token lifetimes are bounded; 1 personal access client.')
            ->and($files->findings[0]->details)->toBe([
                'provider' => 'passport',
                'hasKeys' => true,
                'isPrivateKeyTooOpen' => false,
                'accessTokenLifetimeMinutes' => 60,
                'refreshTokenLifetimeMinutes' => 43200,
                'personalAccessTokenLifetimeMinutes' => 525600,
                'personalAccessClientCount' => 1,
            ])
            ->and($configured->status)->toBe(FindingStatusEnum::Pass)
            ->and($configured->findings[0]->details)->toMatchArray(['hasKeys' => true, 'isPrivateKeyTooOpen' => null])
            ->and(apiTokensFindingsBySubject($long))->toBe([
                'passport.keys' => ['severity' => SeverityEnum::High, 'status' => FindingStatusEnum::Fail],
                'passport.token-lifetimes' => ['severity' => SeverityEnum::Low, 'status' => FindingStatusEnum::Warn],
            ])
            ->and($long->findings[1]->message)->toStartWith('Passport personal access tokens live very long');
    })->skip(PHP_OS_FAMILY === 'Windows', 'file modes');

    it('counts personal access clients on the Passport 12 schema', function () {
        $this->loadMigrationsFrom(dirname(__DIR__).'/Fixtures/Migrations/Passport12');
        $client = ['user_id' => null, 'secret' => null, 'provider' => null, 'redirect' => 'http://localhost', 'password_client' => false, 'created_at' => tokenTimestamp('now'), 'updated_at' => tokenTimestamp('now')];
        DB::table('oauth_clients')->insert([
            [...$client, 'name' => API_TOKENS_MARKER, 'personal_access_client' => true, 'revoked' => false],
            [...$client, 'name' => API_TOKENS_MARKER, 'personal_access_client' => true, 'revoked' => false],
            [...$client, 'name' => API_TOKENS_MARKER, 'personal_access_client' => false, 'revoked' => false],
        ]);

        $result = apiTokensCheckResult(null, ['private_key' => 'a', 'public_key' => 'b']);

        expect($result->findings[0]->details['personalAccessClientCount'])->toBe(2);
    });

    it('reports Sanctum and Passport side by side, valid against the report schema', function () {
        $result = apiTokensCheckResult(sanctumConfiguration(['expiration' => 60]), ['private_key' => 'a', 'public_key' => 'b']);
        $validator = new Validator;
        $schema = json_decode((string) file_get_contents(dirname(__DIR__, 2).'/docs/report-schema.json'), false, 512, JSON_THROW_ON_ERROR);

        foreach ($result->findings as $finding) {
            $details = json_decode(json_encode($finding->details, JSON_THROW_ON_ERROR), false, 512, JSON_THROW_ON_ERROR);
            $validator->validate($details, (object) ['$ref' => 'file://'.dirname(__DIR__, 2).'/docs/report-schema.json#/$defs/laravelApiTokensDetails']);
        }

        expect(array_column(array_map(fn ($finding) => $finding->details, $result->findings), 'provider'))->toBe(['sanctum', 'passport'])
            ->and($validator->getErrors())->toBe([])
            ->and($schema->{'$defs'}->laravelApiTokensDetails->oneOf)->toHaveCount(2);
    });
});

/**
 * Boot Excubia with `runtime.auth` on (or these runtime keys).
 *
 * @param  array<string, mixed>|null  $runtime
 */
function bootAuthRuntime(Application $app, ?array $runtime = null): void
{
    $app->setBasePath(temporaryProject(['excubia.json' => json_encode(['runtime' => $runtime ?? ['enabled' => true, 'auth' => ['enabled' => true]]], JSON_THROW_ON_ERROR)]));
    $app->useStoragePath(temporaryProject([]));
    $app->register(ExcubiaServiceProvider::class, true);
}

/**
 * @return Collection<string, array<string, mixed>>
 */
function bufferedAuth(Application $app): Collection
{
    return bufferedOfType($app, 'auth')->keyBy(fn (array $entry): string => $entry['guard']);
}

/**
 * A user and credentials full of personal data carrying the marker.
 */
function markedUser(): GenericUser
{
    return new GenericUser(['id' => 987654321, 'email' => API_TOKENS_MARKER.'@example.test', 'name' => API_TOKENS_MARKER, 'password' => 'secret-'.API_TOKENS_MARKER]);
}

/**
 * @return array<string, string>
 */
function markedCredentials(): array
{
    return ['email' => API_TOKENS_MARKER.'@example.test', 'password' => 'hunter2-'.API_TOKENS_MARKER];
}

function markedRequest(): Request
{
    return Request::create('/login?user='.API_TOKENS_MARKER, 'POST', ['email' => API_TOKENS_MARKER.'@example.test'], server: ['REMOTE_ADDR' => '203.0.113.77']);
}

it('keeps the auth type off by default and listens to authentication only when switched on', function (array $runtime, int $added) {
    $before = count(Event::getListeners(Login::class));
    bootAuthRuntime($this->app, $runtime);

    expect(count(Event::getListeners(Login::class)))->toBe($before + $added)
        ->and(count(Event::getListeners(TokenAuthenticated::class)))->toBe($added);
})->with([
    'runtime on, auth not switched on' => [['enabled' => true], 0],
    'runtime off' => [['enabled' => false, 'auth' => ['enabled' => true]], 0],
    'auth on' => [['enabled' => true, 'auth' => ['enabled' => true]], 1],
]);

it('counts logins, failed logins, lockouts and Sanctum token authentications per guard', function () {
    config(['auth.defaults.guard' => 'web']);
    bootAuthRuntime($this->app);

    event(new Login('web', markedUser(), false));
    event(new Login('web', markedUser(), true));
    event(new Failed('web', markedUser(), markedCredentials()));
    event(new Failed('admin', null, markedCredentials()));
    event(new Lockout(markedRequest()));
    event(new Login('weird guard!', markedUser(), false));
    event(new TokenAuthenticated((object) ['id' => 4242, 'name' => API_TOKENS_MARKER, 'abilities' => ['*']]));
    event(new TokenAuthenticated((object) ['id' => 4243]));
    $this->app->terminate();
    $entries = bufferedAuth($this->app);
    $validator = new Validator;
    $document = json_decode(json_encode(['bucketSeconds' => 60, 'aggregates' => bufferedRuntime($this->app)], JSON_THROW_ON_ERROR), false, 512, JSON_THROW_ON_ERROR);
    $validator->validate($document, (object) ['$ref' => 'file://'.dirname(__DIR__, 2).'/docs/runtime-schema.json']);

    expect($entries->keys()->sort()->values()->all())->toBe(['admin', 'sanctum', 'unknown', 'web'])
        ->and($entries['web'])->toMatchArray(['count' => 4, 'errorCount' => 1, 'loginCount' => 2, 'lockoutCount' => 1, 'tokenAuthenticationCount' => 0])
        ->and($entries['admin'])->toMatchArray(['count' => 1, 'errorCount' => 1, 'loginCount' => 0])
        ->and($entries['unknown'])->toMatchArray(['count' => 1, 'loginCount' => 1])
        ->and($entries['sanctum'])->toMatchArray(['count' => 2, 'errorCount' => 0, 'tokenAuthenticationCount' => 2])
        ->and($validator->getErrors())->toBe([]);
});

it('never writes or pushes usernames, emails, user ids, credentials, tokens or IP addresses', function () {
    putenv('EXCUBIA_APP_URL=https://excubia.example.test/');
    putenv('EXCUBIA_APP_TOKEN=runtime-token');
    $client = new FakeHttpClient(['https://excubia.example.test/api/runtime' => ['stored' => true]]);
    bootAuthRuntime($this->app);
    $this->app->instance(HttpClientContract::class, $client);

    event(new Login('web', markedUser(), true));
    event(new Failed('web', markedUser(), markedCredentials()));
    event(new Lockout(markedRequest()));
    event(new TokenAuthenticated((object) ['id' => 987654321, 'token' => hash('sha256', API_TOKENS_MARKER), 'name' => API_TOKENS_MARKER]));
    $this->app->terminate();

    $raw = (string) file_get_contents($this->app->storagePath('excubia/runtime/'.RuntimeBufferService::BUFFER_FILE));
    $keys = bufferedOfType($this->app, 'auth')->flatMap(fn (array $entry): array => array_keys($entry))->unique()->sort()->values()->all();
    $this->artisan('excubia:push-runtime')->assertExitCode(0);
    $pushed = json_encode($client->postedPayloads, JSON_THROW_ON_ERROR);
    putenv('EXCUBIA_APP_URL');
    putenv('EXCUBIA_APP_TOKEN');

    expect($pushed)->toContain('"auth"')
        ->and($keys)->toBe(['bucket', 'count', 'errorCount', 'guard', 'lockoutCount', 'loginCount', 'sampleRate', 'tokenAuthenticationCount', 'type']);

    foreach ([$raw, $pushed] as $written) {
        expect($written)->not->toContain(API_TOKENS_MARKER)
            ->and($written)->not->toContain('@')
            ->and($written)->not->toContain('987654321')
            ->and($written)->not->toContain('hunter2')
            ->and($written)->not->toContain('203.0.113.77')
            ->and($written)->not->toContain(hash('sha256', API_TOKENS_MARKER));
    }
});

it('records nothing at sample rate 0', function () {
    bootAuthRuntime($this->app, ['enabled' => true, 'auth' => ['enabled' => true, 'sampleRate' => 0]]);

    event(new Login('web', markedUser(), false));
    event(new Failed('web', markedUser(), markedCredentials()));
    $this->app->terminate();

    expect(bufferedAuth($this->app))->toHaveCount(0);
});
