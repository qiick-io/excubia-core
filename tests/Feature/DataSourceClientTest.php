<?php

declare(strict_types=1);

use Qiick\Excubia\Exceptions\DataSourceUnavailableException;
use Qiick\Excubia\Services\EndOfLifeApiService;
use Qiick\Excubia\Services\GitHubRepositoryApiService;
use Qiick\Excubia\Services\NotifyingHttpClientService;
use Qiick\Excubia\Services\OsvApiService;
use Qiick\Excubia\Services\PackagistApiService;
use Qiick\Excubia\Services\ResponseCacheService;
use Qiick\Excubia\Services\SymfonyHttpClientService;
use Qiick\Excubia\Tests\Support\FakeHttpClient;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

function recorded(string $name): array
{
    $decoded = json_decode(
        (string) file_get_contents(dirname(__DIR__).'/Fixtures/Responses/'.$name),
        true,
        512,
        JSON_THROW_ON_ERROR,
    );

    if (! is_array($decoded)) {
        throw new RuntimeException($name.' is not a JSON object or list.');
    }

    return $decoded;
}

function cacheIn(string $directory, int $ttlHours = 24, bool $isOffline = false, bool $isBypassed = false): ResponseCacheService
{
    return new ResponseCacheService($directory, $ttlHours, $isOffline, $isBypassed);
}

it('reads a recorded end-of-life cycle without a second http call', function () {
    $directory = sys_get_temp_dir().'/excubia-cache-'.bin2hex(random_bytes(4));
    $client = new FakeHttpClient([
        'https://endoflife.date/api/php.json' => recorded('endoflife-php.json'),
    ]);
    $service = new EndOfLifeApiService($client, cacheIn($directory));

    $first = $service->cycles('php');
    $second = $service->cycles('php');

    expect($client->calls)->toBe(1)
        ->and($first[0]->cycle)->toBe('8.3')
        ->and($first[0]->securitySupportEndsOn)->toBe('2027-12-31')
        ->and($second[1]->cycle)->toBe('8.1');
});

it('skips the network when offline and the cache is empty', function () {
    $directory = sys_get_temp_dir().'/excubia-cache-'.bin2hex(random_bytes(4));
    $client = new FakeHttpClient([]);
    $service = new EndOfLifeApiService($client, cacheIn($directory, isOffline: true));

    expect(fn () => $service->cycles('php'))->toThrow(DataSourceUnavailableException::class);
    expect($client->calls)->toBe(0);
});

it('asks Packagist for every package in one request', function () {
    $directory = sys_get_temp_dir().'/excubia-cache-'.bin2hex(random_bytes(4));
    $client = new FakeHttpClient([
        'https://packagist.org/api/security-advisories/' => recorded('packagist-advisories.json'),
        'https://repo.packagist.org/p2/swiftmailer/swiftmailer.json' => recorded('packagist-p2-swiftmailer.json'),
    ]);
    $service = new PackagistApiService($client, cacheIn($directory));

    $advisories = $service->securityAdvisories(['guzzlehttp/guzzle', 'monolog/monolog']);
    $queriedPackages = $client->lastQuery['packages'] ?? null;
    $versions = $service->versions('swiftmailer/swiftmailer');

    expect($queriedPackages)->toBe(['guzzlehttp/guzzle', 'monolog/monolog'])
        ->and($advisories[0]->advisoryId)->toBe('PKSA-gcrk-3vtt-1r14')
        ->and($advisories[0]->severity)->toBe('high')
        ->and($versions[0]->abandoned)->toBe('symfony/mailer')
        ->and($versions[0]->sourceUrl)->toBe('https://github.com/swiftmailer/swiftmailer.git');
});

it('reads an OSV batch result', function () {
    $directory = sys_get_temp_dir().'/excubia-cache-'.bin2hex(random_bytes(4));
    $client = new FakeHttpClient([
        'https://api.osv.dev/v1/querybatch' => recorded('osv-querybatch.json'),
    ]);
    $vulnerabilities = (new OsvApiService($client, cacheIn($directory)))->vulnerabilities([
        ['name' => 'lodash', 'version' => '4.17.20'],
    ]);

    expect($vulnerabilities[0]->id)->toBe('GHSA-29mw-wpgm-hmr9')
        ->and($vulnerabilities[0]->packageName)->toBe('lodash')
        ->and($vulnerabilities[0]->severity)->toContain('CVSS:3.1');
});

it('sends the GitHub token as a header and does not cache it', function () {
    $directory = sys_get_temp_dir().'/excubia-cache-'.bin2hex(random_bytes(4));
    mkdir($directory);
    putenv('GITHUB_TOKEN=super-secret-token');
    $client = new FakeHttpClient([
        'https://api.github.com/repos/jenssegers/agent' => recorded('github-jenssegers-agent.json'),
    ]);

    try {
        $repository = (new GitHubRepositoryApiService($client, cacheIn($directory)))->repository('jenssegers', 'agent');
        $cached = (string) file_get_contents((string) glob($directory.'/*.json')[0]);
    } finally {
        putenv('GITHUB_TOKEN');
    }

    expect($repository->isArchived)->toBeFalse()
        ->and($repository->pushedAt)->toBe('2024-08-05T12:34:44Z')
        ->and($client->lastHeaders['Authorization'] ?? null)->toBe('Bearer super-secret-token')
        ->and($cached)->not->toContain('super-secret-token');
});

it('uses a five second idle timeout and a fifteen second total', function () {
    $options = SymfonyHttpClientService::defaultOptions();

    expect($options['timeout'])->toBe(5.0)
        ->and($options['max_duration'])->toBe(15.0);
});

it('refuses a data source that is not https', function () {
    $client = new SymfonyHttpClientService(new MockHttpClient);

    expect(fn () => $client->getJson('http://example.test/php.json'))
        ->toThrow(DataSourceUnavailableException::class, 'HTTPS');
});

it('decodes a symfony mock response', function () {
    $mock = new MockHttpClient([
        new MockResponse('{"cycle":"8.3"}', ['http_code' => 200]),
    ]);
    $client = new SymfonyHttpClientService($mock);

    expect($client->getJson('https://endoflife.date/api/php.json'))->toBe(['cycle' => '8.3']);
});

it('allows plain http to loopback hosts only', function () {
    expect(SymfonyHttpClientService::isSecureUrl('https://excubia.example.test'))->toBeTrue()
        ->and(SymfonyHttpClientService::isSecureUrl('http://localhost:8000'))->toBeTrue()
        ->and(SymfonyHttpClientService::isSecureUrl('http://127.0.0.1'))->toBeTrue()
        ->and(SymfonyHttpClientService::isSecureUrl('http://[::1]:8000'))->toBeTrue()
        ->and(SymfonyHttpClientService::isSecureUrl('http://localhost.example.test'))->toBeFalse()
        ->and(SymfonyHttpClientService::isSecureUrl('ftp://localhost'))->toBeFalse()
        ->and(SymfonyHttpClientService::isSecureUrl('excubia.example.test'))->toBeFalse();
});

it('keeps the http status when a response is not successful', function () {
    $client = new SymfonyHttpClientService(new MockHttpClient([
        new MockResponse('{"message":"Unauthenticated."}', ['http_code' => 401]),
    ]));

    try {
        $client->postJson('https://excubia.example.test/api/reports', ['protocolVersion' => 1]);
        $status = null;
    } catch (DataSourceUnavailableException $exception) {
        $status = $exception->httpStatus;
    }

    expect($status)->toBe(401);
});

it('does not follow redirects when posting, so a 307 cannot resend the payload elsewhere', function () {
    $maxRedirects = [];
    $client = new SymfonyHttpClientService(new MockHttpClient(function (string $method, string $url, array $options) use (&$maxRedirects): MockResponse {
        $maxRedirects[$method] = $options['max_redirects'] ?? null;

        return $method === 'POST'
            ? new MockResponse('{}', ['http_code' => 307, 'response_headers' => ['Location' => 'http://elsewhere.example.test/collect']])
            : new MockResponse('{}', ['http_code' => 200]);
    }));

    expect(fn () => $client->postJson('https://excubia.example.test/api/reports', ['protocolVersion' => 1]))
        ->toThrow(DataSourceUnavailableException::class, 'HTTP 307');

    $client->getJson('https://endoflife.date/api/php.json');

    expect($maxRedirects['POST'])->toBe(0)
        ->and($maxRedirects['GET'])->not->toBe(0);
});

it('removes URL credentials from URLs shown in messages', function () {
    expect(SymfonyHttpClientService::redactedUrl('https://user:secret-password@excubia.example.test/api/reports'))
        ->toBe('https://excubia.example.test/api/reports')
        ->and(SymfonyHttpClientService::redactedUrl('https://excubia.example.test/api/reports?x=a@b'))
        ->toBe('https://excubia.example.test/api/reports?x=a@b')
        ->and(SymfonyHttpClientService::redactedUrl('http://localhost:8000'))->toBe('http://localhost:8000');
});

it('lists requests without URL credentials', function () {
    $lines = [];
    $url = 'https://user:secret-password@excubia.example.test/api/reports';
    $client = new NotifyingHttpClientService(new FakeHttpClient([$url => ['stored' => true]]), function (string $line) use (&$lines): void {
        $lines[] = $line;
    });

    $client->postJson($url, []);
    $client->getJson($url);

    expect($lines)->toBe(['POST https://excubia.example.test/api/reports', 'GET https://excubia.example.test/api/reports']);
});

it('refuses GitHub owners and names that walk the API path', function (string $owner, string $name) {
    $client = new FakeHttpClient([]);
    $service = new GitHubRepositoryApiService($client, cacheIn(sys_get_temp_dir().'/excubia-cache-'.bin2hex(random_bytes(4))));

    expect(fn () => $service->repository($owner, $name))->toThrow(DataSourceUnavailableException::class, 'not a GitHub owner')
        ->and($client->calls)->toBe(0);
})->with([
    'parent owner' => ['..', 'user'],
    'current owner' => ['.', 'agent'],
    'parent name' => ['jenssegers', '..'],
]);

/**
 * A fresh base standing in for the shared temporary directory, and the
 * default cache directory the service derives inside it.
 *
 * @return array{base: string, cache: string}
 */
function sharedTemporaryDirectory(): array
{
    $base = temporaryProject([]);
    $user = function_exists('posix_geteuid') ? (string) posix_geteuid() : get_current_user();

    return ['base' => $base, 'cache' => $base.'/qiick-excubia-'.preg_replace('/[^A-Za-z0-9_.-]/', '_', $user)];
}

it('creates the default cache directory for the current user only and uses it', function () {
    ['base' => $base, 'cache' => $cache] = sharedTemporaryDirectory();
    $client = new FakeHttpClient(['https://endoflife.date/api/php.json' => recorded('endoflife-php.json')]);
    $service = new EndOfLifeApiService($client, new ResponseCacheService(null, 24, false, false, $base));

    $service->cycles('php');
    $service->cycles('php');

    expect($client->calls)->toBe(1)
        ->and(fileperms($cache) & 0o777)->toBe(0o700);
});

it('skips a default cache directory that other users can write to, so planted responses are not read', function () {
    ['base' => $base, 'cache' => $cache] = sharedTemporaryDirectory();
    mkdir($cache);
    chmod($cache, 0o777);
    $planted = $cache.'/'.hash('sha256', 'https://endoflife.date/api/php.json').'.json';
    file_put_contents($planted, json_encode(['storedAt' => gmdate(DATE_ATOM), 'body' => [['cycle' => 'planted']]], JSON_THROW_ON_ERROR));
    $client = new FakeHttpClient(['https://endoflife.date/api/php.json' => recorded('endoflife-php.json')]);
    $responseCache = new ResponseCacheService(null, 24, false, false, $base);

    $cycles = (new EndOfLifeApiService($client, $responseCache))->cycles('php');
    $responseCache->clear();

    expect($client->calls)->toBe(1)
        ->and($cycles[0]->cycle)->toBe('8.3')
        ->and(glob($cache.'/*.json'))->toBe([$planted]);
});

it('skips a default cache directory that is a symlink and never clears its target', function () {
    ['base' => $base, 'cache' => $cache] = sharedTemporaryDirectory();
    $target = temporaryProject(['composer.json' => '{}', 'excubia.json' => '{}']);
    symlink($target, $cache);
    $client = new FakeHttpClient(['https://endoflife.date/api/php.json' => recorded('endoflife-php.json')]);
    $responseCache = new ResponseCacheService(null, 24, false, false, $base);

    (new EndOfLifeApiService($client, $responseCache))->cycles('php');
    $responseCache->clear();

    expect($client->calls)->toBe(1)
        ->and(array_map('basename', (array) glob($target.'/*.json')))->toBe(['composer.json', 'excubia.json']);
});

it('clears only files named like cache entries', function () {
    $directory = temporaryProject(['composer.json' => '{}']);
    $client = new FakeHttpClient(['https://endoflife.date/api/php.json' => recorded('endoflife-php.json')]);
    $responseCache = cacheIn($directory);
    (new EndOfLifeApiService($client, $responseCache))->cycles('php');

    expect(glob($directory.'/*.json'))->toHaveCount(2);

    $responseCache->clear();

    expect(array_map('basename', (array) glob($directory.'/*.json')))->toBe(['composer.json']);
});
