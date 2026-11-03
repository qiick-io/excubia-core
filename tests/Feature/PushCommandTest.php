<?php

declare(strict_types=1);

use Qiick\Excubia\Console\PushCommand;
use Qiick\Excubia\Exceptions\DataSourceUnavailableException;
use Qiick\Excubia\Laravel\ExcubiaPushCommand;
use Qiick\Excubia\Tests\Support\FakeHttpClient;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;

const PUSH_ENDPOINT = 'https://excubia.example.test/api/reports';
const PUSH_TOKEN = 'project-token-secret';

/**
 * @param  array<string, string>  $environment
 * @param  array<string, mixed>  $app
 * @return array{tester: CommandTester, input: array<string, string>}
 */
function pushTester(FakeHttpClient $client, array $environment, array $app = []): array
{
    $document = ['cache' => ['directory' => temporaryProject([])]];

    if ($app !== []) {
        $document['app'] = $app;
    }

    $config = temporaryProject(['excubia.json' => json_encode($document, JSON_THROW_ON_ERROR)]).'/excubia.json';
    $command = new PushCommand($client, new DateTimeImmutable('2026-11-02 08:00:00', new DateTimeZone('UTC')), $environment);

    return [
        'tester' => new CommandTester($command),
        'input' => ['path' => dirname(__DIR__).'/Fixtures/Projects/outdated-laravel-10', '--config' => $config],
    ];
}

function appEnvironment(string $url = 'https://excubia.example.test/'): array
{
    return ['EXCUBIA_APP_URL' => $url, 'EXCUBIA_APP_TOKEN' => PUSH_TOKEN];
}

it('posts the envelope with the bearer token and exits 0', function () {
    $client = new FakeHttpClient([PUSH_ENDPOINT => ['stored' => true]]);
    ['tester' => $tester, 'input' => $input] = pushTester($client, appEnvironment());

    $exitCode = $tester->execute($input);
    $payload = $client->postedPayloads[0];

    expect($exitCode)->toBe(0)
        ->and($client->lastPostUrl)->toBe(PUSH_ENDPOINT)
        ->and($client->lastHeaders)->toBe(['Authorization' => 'Bearer '.PUSH_TOKEN])
        ->and(array_keys($payload))->toBe(['protocolVersion', 'batchId', 'sentAt', 'report'])
        ->and($payload['protocolVersion'])->toBe(1)
        ->and($payload['batchId'])->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/')
        ->and($payload['sentAt'])->toBe('2026-11-02T08:00:00Z')
        ->and(array_keys($payload['report']))->toBe(['schemaVersion', 'checks', 'upgradePlan'])
        ->and($payload['report']['schemaVersion'])->toBe(1)
        ->and(json_encode($payload, JSON_THROW_ON_ERROR))->not->toContain(PUSH_TOKEN)
        ->and($tester->getDisplay())->toContain($payload['batchId'])
        ->and($tester->getDisplay())->not->toContain(PUSH_TOKEN);
});

it('uses a new batch id on every run', function () {
    $client = new FakeHttpClient([PUSH_ENDPOINT => ['stored' => true]]);
    ['tester' => $tester, 'input' => $input] = pushTester($client, appEnvironment());

    $tester->execute($input);
    $tester->execute($input);

    expect($client->postedPayloads)->toHaveCount(2)
        ->and($client->postedPayloads[0]['batchId'])->not->toBe($client->postedPayloads[1]['batchId']);
});

it('reads the app keys from excubia.json and lets the environment win', function () {
    $fromFile = new FakeHttpClient(['https://file.example.test/api/reports' => ['stored' => true]]);
    ['tester' => $tester, 'input' => $input] = pushTester($fromFile, [], ['url' => 'https://file.example.test', 'token' => 'file-token']);

    expect($tester->execute($input))->toBe(0)
        ->and($fromFile->lastHeaders)->toBe(['Authorization' => 'Bearer file-token']);

    $fromEnvironment = new FakeHttpClient([PUSH_ENDPOINT => ['stored' => true]]);
    ['tester' => $tester, 'input' => $input] = pushTester($fromEnvironment, appEnvironment(), ['url' => 'https://file.example.test', 'token' => 'file-token']);

    expect($tester->execute($input))->toBe(0)
        ->and($fromEnvironment->lastPostUrl)->toBe(PUSH_ENDPOINT)
        ->and($fromEnvironment->lastHeaders)->toBe(['Authorization' => 'Bearer '.PUSH_TOKEN]);
});

it('exits 2 without sending when the url or token is missing', function (array $environment, string $message) {
    $client = new FakeHttpClient([PUSH_ENDPOINT => ['stored' => true]]);
    ['tester' => $tester, 'input' => $input] = pushTester($client, $environment);

    expect($tester->execute($input, ['capture_stderr_separately' => true]))->toBe(2)
        ->and($tester->getErrorOutput())->toContain($message)
        ->and($client->postedPayloads)->toBe([]);
})->with([
    'no url' => [['EXCUBIA_APP_TOKEN' => PUSH_TOKEN], 'EXCUBIA_APP_URL'],
    'no token' => [['EXCUBIA_APP_URL' => 'https://excubia.example.test'], 'EXCUBIA_APP_TOKEN'],
    'empty values' => [['EXCUBIA_APP_URL' => '', 'EXCUBIA_APP_TOKEN' => ''], 'EXCUBIA_APP_URL'],
]);

it('refuses plain http unless the host is localhost', function () {
    $client = new FakeHttpClient(['http://localhost:8000/api/reports' => ['stored' => true]]);
    ['tester' => $tester, 'input' => $input] = pushTester($client, appEnvironment('http://excubia.example.test'));

    expect($tester->execute($input, ['capture_stderr_separately' => true]))->toBe(2)
        ->and($tester->getErrorOutput())->toContain('HTTPS')
        ->and($client->postedPayloads)->toBe([]);

    ['tester' => $tester, 'input' => $input] = pushTester($client, appEnvironment('http://localhost:8000'));

    expect($tester->execute($input))->toBe(0)
        ->and($client->lastPostUrl)->toBe('http://localhost:8000/api/reports');
});

it('exits 3 with a clear message when the app rejects the token or is unreachable', function () {
    $rejecting = new FakeHttpClient([], [
        PUSH_ENDPOINT => new DataSourceUnavailableException('http', 'The data source returned HTTP 401.', httpStatus: 401),
    ]);
    ['tester' => $tester, 'input' => $input] = pushTester($rejecting, appEnvironment());

    expect($tester->execute($input, ['capture_stderr_separately' => true]))->toBe(3)
        ->and($tester->getErrorOutput())->toContain('rejected the project token (HTTP 401)')
        ->and($tester->getErrorOutput())->not->toContain(PUSH_TOKEN);

    ['tester' => $tester, 'input' => $input] = pushTester(new FakeHttpClient([]), appEnvironment());

    expect($tester->execute($input, ['capture_stderr_separately' => true]))->toBe(3)
        ->and($tester->getErrorOutput())->toContain('could not be reached at '.PUSH_ENDPOINT);
});

it('does not print the token when requests are listed', function () {
    $client = new FakeHttpClient([PUSH_ENDPOINT => ['stored' => true]]);
    ['tester' => $tester, 'input' => $input] = pushTester($client, appEnvironment());

    $tester->execute($input, ['verbosity' => OutputInterface::VERBOSITY_VERBOSE]);

    expect($tester->getDisplay())->toContain('POST '.PUSH_ENDPOINT)
        ->and($tester->getDisplay())->not->toContain(PUSH_TOKEN);
});

it('exits 2 when composer.lock is missing', function () {
    ['tester' => $tester, 'input' => $input] = pushTester(new FakeHttpClient([]), appEnvironment());
    $input['path'] = temporaryProject([]);

    expect($tester->execute($input, ['capture_stderr_separately' => true]))->toBe(2)
        ->and($tester->getErrorOutput())->toContain('composer.lock');
});

it('names the artisan alias', function () {
    expect((new ExcubiaPushCommand)->getName())->toBe('excubia:push');
});

it('does not send and exits 3 with --strict when a data source failure skipped a check', function () {
    $client = new FakeHttpClient([PUSH_ENDPOINT => ['stored' => true]]);
    ['tester' => $tester, 'input' => $input] = pushTester($client, appEnvironment());

    expect($tester->execute($input + ['--strict' => true], ['capture_stderr_separately' => true]))->toBe(3)
        ->and($client->postedPayloads)->toBe([])
        ->and($tester->getErrorOutput())->toContain('endoflife.date did not respond.')
        ->and($tester->getErrorOutput())->toContain('The report was not sent.');

    ['tester' => $tester, 'input' => $input] = pushTester($client, appEnvironment());

    expect($tester->execute($input))->toBe(0)
        ->and($client->postedPayloads)->toHaveCount(1)
        ->and(json_encode($client->postedPayloads[0]['report'], JSON_THROW_ON_ERROR))->toContain('"skipReason":"data-source-failure"');
});
