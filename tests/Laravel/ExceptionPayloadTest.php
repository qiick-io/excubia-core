<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Qiick\Excubia\Laravel\ExceptionPayloadService;

const PAYLOAD_PROJECT_ROOT = '/var/www/shop';

// Fake keys, split so secret scanners do not flag the test file.
const FAKE_STRIPE_KEY = 'sk_'.'live_'.'51HqLyjWDarjtT1zdp7dcXlm2';
const FAKE_GITHUB_TOKEN = 'ghp_'.'A1b2C3d4E5f6G7h8I9j0K1l2M3n4O5p6Q7r8';

/**
 * The repository root plays the project: tests/ is in-app, vendor/ is not.
 */
function repositoryRoot(): string
{
    return dirname(__DIR__, 2);
}

function exceptionFromFirstLine(): RuntimeException
{
    return new RuntimeException('first');
}

function exceptionFromLaterLine(): RuntimeException
{

    return new RuntimeException('second');
}

/**
 * Same function, two throw sites on different lines.
 */
function exceptionFromSameFunction(bool $isLater): RuntimeException
{
    if ($isLater) {

        return new RuntimeException('later');
    }

    return new RuntimeException('earlier');
}

function exceptionFromOtherPath(): RuntimeException
{
    return exceptionFromSameFunction(false);
}

/**
 * @return array<string, mixed>
 */
function capturedGroup(Throwable $exception): array
{
    return ExceptionPayloadService::group($exception, repositoryRoot(), [], ['kind' => 'unknown', 'name' => 'unknown'], 'testing', '2026-11-03T10:00:00Z');
}

it('masks a corpus of real-looking messages so no secret survives', function (string $message, array $secrets, array $kept) {
    $masked = ExceptionPayloadService::mask($message, PAYLOAD_PROJECT_ROOT);

    foreach ($secrets as $secret) {
        expect($masked)->not->toContain($secret);
    }

    foreach ($kept as $fragment) {
        expect($masked)->toContain($fragment);
    }
})->with([
    'email' => ['Customer mario.rossi+shop@example-mail.it already exists', ['mario.rossi', 'example-mail.it'], ['[email]', 'already exists']],
    'JWT' => ['Invalid token eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJzdWIiOiIxMjM0NTY3ODkwIn0.dozjgNryP4J3jVmNHl0w5N_XgL0n3I9PlFUP0THsR8U', ['eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9', 'dozjgNryP4J3jVmNHl0w5N_XgL0n3I9PlFUP0THsR8U'], ['[jwt]']],
    'bearer header' => ['Request failed with Authorization: Bearer 9f8e7d6c5b4a-live-token', ['9f8e7d6c5b4a-live-token'], ['Bearer [token]']],
    'Stripe key' => ['No such customer for key '.FAKE_STRIPE_KEY, [FAKE_STRIPE_KEY], ['[token]']],
    'GitHub token' => ['Bad credentials '.FAKE_GITHUB_TOKEN, [FAKE_GITHUB_TOKEN], ['[token]']],
    'AWS key' => ['The AWS Access Key Id AKIAIOSFODNN7EXAMPLE you provided does not exist', ['AKIAIOSFODNN7EXAMPLE'], ['[token]']],
    'hex secret' => ['Signature mismatch for 3f786850e387550fdab836ed7e6dc881de23001b', ['3f786850e387550fdab836ed7e6dc881de23001b'], ['[token]']],
    'key=value secrets' => ['Login failed: password=hunter2 api_key: abc-def client_secret="s3cr3t"', ['hunter2', 'abc-def', 's3cr3t'], ['password=[masked]', 'api_key: [masked]']],
    'card number' => ['Card 4242 4242 4242 4242 was declined, also 5555-5555-5555-4444 and 378282246310005', ['4242 4242 4242 4242', '5555-5555-5555-4444', '378282246310005'], ['[number]', 'was declined']],
    'long numbers' => ['Order 12345678 for phone 3471234567 not found', ['12345678', '3471234567'], ['Order [number]']],
    'IPv4 and IPv6' => ['Blocked 203.0.113.77 and 2001:db8:85a3::8a2e:370:7334 and fe80::1', ['203.0.113.77', '2001:db8:85a3::8a2e:370:7334', 'fe80::1'], ['[ip]']],
    'UUID' => ['Model not found: 3b241101-e2bb-4255-8caf-4136c566a962', ['3b241101-e2bb-4255-8caf-4136c566a962'], ['[uuid]']],
    'URL with credentials and token' => ['cURL error 28 for https://admin:pa55word@api.example.test/v1/charges?api_key=query-secret&user=7#frag', ['admin', 'pa55word', 'query-secret', 'frag'], ['https://api.example.test/v1/charges?[query]', 'cURL error 28']],
    'SQL with values' => ["SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry 'luigi@example.test' for key 'users_email_unique' (Connection: mysql, Host: db.internal, Port: 3306, Database: shop_prod, SQL: insert into users (name) values ('Luigi Verdi'))", ['luigi', 'Luigi Verdi', 'db.internal', 'shop_prod', '3306'], ['SQLSTATE[23000]', 'Duplicate entry ? for key ?', 'Connection: mysql']],
    'absolute paths' => ['file_get_contents(/var/www/shop/storage/app/private/invoice.pdf): Failed to open stream; see /etc/ssl/private/server.key', ['/var/www/shop', '/etc/ssl/private'], ['storage/app/private/invoice.pdf', 'server.key']],
]);

it('keeps class names, method calls and short numbers readable', function () {
    $message = 'Call to undefined method App\\Models\\Feed::add() on line 1234 with status 404';

    expect(ExceptionPayloadService::mask($message, PAYLOAD_PROJECT_ROOT))->toBe($message);
});

it('applies the user mask patterns before the built-in masks', function () {
    expect(ExceptionPayloadService::mask('Order ORD-77 failed for VIP Luigi', PAYLOAD_PROJECT_ROOT, ['/ORD-[0-9]+/', '/VIP \\w+/']))
        ->toBe('Order [masked] failed for [masked]');
});

it('truncates long messages', function () {
    expect(mb_strlen(ExceptionPayloadService::mask(str_repeat('word ', 1000), PAYLOAD_PROJECT_ROOT)))->toBe(ExceptionPayloadService::MAX_MESSAGE_LENGTH);
});

it('rebuilds query errors from the SQL without bindings and without connection details', function () {
    $exception = new QueryException('mysql', 'select * from users where email = ? and token = ?', ['anna@example.test', 'binding-secret'], new PDOException('SQLSTATE[HY000]: General error'));

    $message = capturedGroup($exception)['message'];

    expect($message)->toBe('SQLSTATE[HY000]: General error (Connection: mysql, SQL: select * from users where email = ? and token = ?)');
});

it('keeps the fingerprint across line-number changes in the same code path', function () {
    $first = capturedGroup(exceptionFromSameFunction(false));
    $later = capturedGroup(exceptionFromSameFunction(true));

    expect($first['frames'][0]['line'])->not->toBe($later['frames'][0]['line'])
        ->and($first['fingerprint'])->toBe($later['fingerprint'])
        ->and($first['fingerprint'])->toMatch('/^[0-9a-f]{64}$/');
});

it('gives different code paths and different classes different fingerprints', function () {
    $direct = capturedGroup(exceptionFromSameFunction(false));
    $viaOtherPath = capturedGroup(exceptionFromOtherPath());
    $otherFunction = capturedGroup(exceptionFromFirstLine());
    $laterFunction = capturedGroup(exceptionFromLaterLine());
    $frames = ExceptionPayloadService::frames(exceptionFromFirstLine(), repositoryRoot());

    expect($viaOtherPath['fingerprint'])->not->toBe($direct['fingerprint'])
        ->and($otherFunction['fingerprint'])->not->toBe($laterFunction['fingerprint'])
        ->and(ExceptionPayloadService::fingerprint(LogicException::class, $frames))->not->toBe(ExceptionPayloadService::fingerprint(RuntimeException::class, $frames));
});

it('ignores vendor frames in the fingerprint and falls back to the top frame without in-app frames', function () {
    $appFrame = ['file' => 'app/Service.php', 'line' => 10, 'function' => 'App\\Service::run', 'inApp' => true];
    $vendorFrame = ['file' => 'vendor/laravel/framework/src/Pipeline.php', 'line' => 20, 'function' => 'Illuminate\\Pipeline::handle', 'inApp' => false];
    $otherVendorFrame = ['file' => 'vendor/laravel/framework/src/Router.php', 'line' => 30, 'function' => 'Illuminate\\Router::dispatch', 'inApp' => false];

    expect(ExceptionPayloadService::fingerprint('E', [$appFrame, $vendorFrame]))->toBe(ExceptionPayloadService::fingerprint('E', [$appFrame, $otherVendorFrame]))
        ->and(ExceptionPayloadService::fingerprint('E', [$vendorFrame, $otherVendorFrame]))->toBe(ExceptionPayloadService::fingerprint('E', [$vendorFrame]))
        ->and(ExceptionPayloadService::fingerprint('E', [$vendorFrame]))->not->toBe(ExceptionPayloadService::fingerprint('E', [$otherVendorFrame]));
});

it('sends frames with relative paths, lines and function names only', function () {
    $secretArgument = 'argument-secret-'.bin2hex(random_bytes(4));
    $localSecret = 'local-secret-'.bin2hex(random_bytes(4));
    $throwing = function (string $argument) use ($localSecret): RuntimeException {
        $local = $localSecret;

        return new RuntimeException('failed', previous: new LogicException('previous-message-secret'));
    };
    $group = capturedGroup($throwing($secretArgument));
    $encoded = json_encode($group, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    $first = $group['frames'][0];

    expect($first['file'])->toBe('tests/Laravel/ExceptionPayloadTest.php')
        ->and($first['inApp'])->toBeTrue()
        ->and($first['line'])->toBeInt()
        ->and($group['previous'])->toBe([LogicException::class]);

    foreach ($group['frames'] as $frame) {
        expect(array_keys($frame))->toBe(['file', 'line', 'function', 'inApp']);

        if (is_string($frame['file']) && str_starts_with($frame['file'], 'vendor/')) {
            expect($frame['inApp'])->toBeFalse();
        }
    }

    foreach ([repositoryRoot(), $secretArgument, $localSecret, 'previous-message-secret', '"args"'] as $forbidden) {
        expect($encoded)->not->toContain($forbidden);
    }
});

it('strips absolute paths from anonymous classes, closures and files outside the project', function () {
    $anonymous = new class('anonymous') extends RuntimeException {};
    $group = capturedGroup($anonymous);
    $outside = ExceptionPayloadService::frames(new RuntimeException('x'), '/somewhere/else');

    expect($group['class'])->toBe('RuntimeException@anonymous')
        ->and(json_encode($group, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES))->not->toContain(repositoryRoot())
        ->and($outside[0])->toMatchArray(['file' => 'ExceptionPayloadTest.php', 'inApp' => false]);

    foreach ($group['frames'] as $frame) {
        expect((string) $frame['function'])->not->toMatch('/\{closure:[^}]*:\d+\}/');
    }
});

it('merges groups with the same fingerprint, context and environment', function () {
    $first = capturedGroup(exceptionFromFirstLine());
    $again = [...$first, 'count' => 2, 'firstSeen' => '2026-11-03T09:00:00Z', 'lastSeen' => '2026-11-03T11:00:00Z', 'message' => 'later message'];
    $otherContext = [...$first, 'context' => ['kind' => 'job', 'name' => 'App\\Jobs\\Sync']];

    $merged = ExceptionPayloadService::merge([$first, $again, $otherContext, ['not' => 'a group']]);

    expect($merged)->toHaveCount(2)
        ->and($merged[0])->toMatchArray(['count' => 3, 'firstSeen' => '2026-11-03T09:00:00Z', 'lastSeen' => '2026-11-03T11:00:00Z', 'message' => 'first'])
        ->and($merged[1]['context'])->toBe(['kind' => 'job', 'name' => 'App\\Jobs\\Sync']);
});
