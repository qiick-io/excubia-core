<?php

declare(strict_types=1);

use Qiick\Excubia\Exceptions\DataSourceUnavailableException;
use Qiick\Excubia\Services\EndOfLifeApiService;
use Qiick\Excubia\Services\GitHubRepositoryApiService;
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
