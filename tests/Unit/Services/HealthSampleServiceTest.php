<?php

declare(strict_types=1);

use Qiick\Excubia\Data\CheckResultData;
use Qiick\Excubia\Data\FindingData;
use Qiick\Excubia\Enums\FindingStatusEnum;
use Qiick\Excubia\Enums\SeverityEnum;
use Qiick\Excubia\Services\HealthSampleService;
use Qiick\Excubia\Services\RuntimeBufferService;

function healthSampleFinding(string $subject, FindingStatusEnum $status, SeverityEnum $severity): FindingData
{
    return new FindingData('laravel-queue-backlog', $severity, $status, $subject, 'Queue '.$subject.' has a backlog.', 'Start a worker.', null);
}

/**
 * One `health` aggregate as the buffer stores it.
 *
 * @param  list<string>  $fingerprints
 * @return array<string, mixed>
 */
function healthSampleAggregate(string $status, string $severity = 'info', int $findingCount = 0, array $fingerprints = []): array
{
    return [
        'type' => 'health',
        'bucket' => '2026-11-06T10:15:00Z',
        'sampleRate' => 1.0,
        'server' => 'web-1',
        'checkId' => 'laravel-database',
        'count' => 1,
        'errorCount' => $status === 'fail' ? 1 : 0,
        'status' => $status,
        'severity' => $severity,
        'findingCount' => $findingCount,
        'fingerprints' => $fingerprints,
    ];
}

it('keeps the status, the most serious severity and the sorted fingerprints of warn and fail findings only', function () {
    $result = CheckResultData::fromFindings('laravel-queue-backlog', 'Queue backlog', [
        healthSampleFinding('emails', FindingStatusEnum::Warn, SeverityEnum::Medium),
        healthSampleFinding('default', FindingStatusEnum::Fail, SeverityEnum::High),
        healthSampleFinding('reports', FindingStatusEnum::Pass, SeverityEnum::Info),
        healthSampleFinding('imports', FindingStatusEnum::Skipped, SeverityEnum::Info),
    ]);
    $fingerprints = [healthSampleFinding('emails', FindingStatusEnum::Warn, SeverityEnum::Medium)->fingerprint(), healthSampleFinding('default', FindingStatusEnum::Fail, SeverityEnum::High)->fingerprint()];
    sort($fingerprints);

    $aggregate = HealthSampleService::aggregate('laravel-queue-backlog', $result, 'web-1', 1.0, 1793960105);

    expect($aggregate)->toBe([
        'type' => 'health',
        'bucket' => '2026-11-06T10:15:00Z',
        'sampleRate' => 1.0,
        'server' => 'web-1',
        'checkId' => 'laravel-queue-backlog',
        'count' => 1,
        'errorCount' => 1,
        'status' => 'fail',
        'severity' => 'high',
        'findingCount' => 2,
        'fingerprints' => $fingerprints,
    ])
        ->and(json_encode($aggregate, JSON_THROW_ON_ERROR))->not->toContain('emails')
        ->and(json_encode($aggregate, JSON_THROW_ON_ERROR))->not->toContain('backlog.');
});

it('sends a passing check as info with no fingerprints, and caps the fingerprints but not the count', function () {
    $findings = array_map(static fn (int $index): FindingData => healthSampleFinding('queue-'.$index, FindingStatusEnum::Warn, SeverityEnum::Low), range(1, 25));
    $many = HealthSampleService::aggregate('laravel-queue-backlog', CheckResultData::fromFindings('laravel-queue-backlog', 'Queue backlog', $findings), 'web-1', 1.0, 0);

    expect(HealthSampleService::aggregate('laravel-cache', CheckResultData::fromFindings('laravel-cache', 'Cache', []), 'web-1', 1.0, 0))
        ->toMatchArray(['status' => 'pass', 'severity' => 'info', 'errorCount' => 0, 'findingCount' => 0, 'fingerprints' => []])
        ->and($many)->toMatchArray(['status' => 'warn', 'severity' => 'low', 'findingCount' => 25])
        ->and($many['fingerprints'])->toHaveCount(HealthSampleService::MAX_FINGERPRINTS);
});

it('runs a check only while a whole check budget is left of the total budget, from the start of the run', function () {
    $now = 0.0;
    $ran = [];
    $sampler = new HealthSampleService(100, 250, static function () use (&$now): float {
        return $now;
    });

    $aggregates = $sampler->aggregates(['first', 'second', 'third'], function (string $checkId) use (&$now, &$ran): CheckResultData {
        $ran[] = $checkId;
        $now += 120.0;

        return CheckResultData::fromFindings($checkId, $checkId, []);
    }, 10.0, 'web-1', 1.0, 0);

    expect($ran)->toBe(['first', 'second'])
        ->and(array_column($aggregates, 'status', 'checkId'))->toBe(['first' => 'pass', 'second' => 'pass', 'third' => 'skipped']);
});

it('sends a check that throws as skipped and runs the next one', function () {
    $sampler = new HealthSampleService(1000, 10000, static fn (): float => 0.0);

    $aggregates = $sampler->aggregates(['broken', 'fine'], static function (string $checkId): CheckResultData {
        if ($checkId === 'broken') {
            throw new Error('Redis went away');
        }

        return CheckResultData::fromFindings($checkId, $checkId, []);
    }, 0.0, 'web-1', 1.0, 0);

    expect(array_column($aggregates, 'status', 'checkId'))->toBe(['broken' => 'skipped', 'fine' => 'pass'])
        ->and($aggregates[0])->toMatchArray(['severity' => 'info', 'findingCount' => 0, 'fingerprints' => [], 'errorCount' => 0]);
});

it('merges health samples of one check, server and minute with the worst status: fail, warn, pass, then skipped', function (array $statuses, string $expected) {
    $merged = RuntimeBufferService::merge(array_map(static fn (string $status): array => healthSampleAggregate($status), $statuses));
    $reversed = RuntimeBufferService::merge(array_map(static fn (string $status): array => healthSampleAggregate($status), array_reverse($statuses)));

    expect($merged)->toHaveCount(1)
        ->and($merged[0]['status'])->toBe($expected)
        ->and($merged[0]['count'])->toBe(count($statuses))
        ->and($reversed[0]['status'])->toBe($expected);
})->with([
    'fail beats warn' => [['warn', 'fail'], 'fail'],
    'warn beats pass' => [['pass', 'warn'], 'warn'],
    'pass beats skipped' => [['skipped', 'pass'], 'pass'],
    'skipped alone' => [['skipped', 'skipped'], 'skipped'],
    'all four' => [['pass', 'skipped', 'fail', 'warn'], 'fail'],
]);

it('merges severity, finding count and fingerprints, and keeps checks and servers apart', function () {
    $first = healthSampleAggregate('warn', 'medium', 1, [str_repeat('b', 64)]);
    $second = healthSampleAggregate('fail', 'critical', 2, [str_repeat('a', 64), str_repeat('b', 64)]);
    $buffer = new RuntimeBufferService(temporaryProject([]), 65536);
    $buffer->append([$first, $second, [...$first, 'checkId' => 'laravel-cache'], [...$first, 'server' => 'web-2']]);

    $aggregates = $buffer->read($buffer->claimBatches()[0]['path']);

    expect($aggregates)->toHaveCount(3)
        ->and($aggregates[0])->toMatchArray(['count' => 2, 'errorCount' => 1, 'status' => 'fail', 'severity' => 'critical', 'findingCount' => 2, 'fingerprints' => [str_repeat('a', 64), str_repeat('b', 64)]])
        ->and(RuntimeBufferService::merge([$second, $first])[0]['severity'])->toBe('critical');
});

it('skips health lines with an unknown status or severity, or fingerprints that are not strings', function (array $fields) {
    $buffer = new RuntimeBufferService(temporaryProject([]), 65536);
    $buffer->append([healthSampleAggregate('pass'), [...healthSampleAggregate('pass'), 'checkId' => 'laravel-cache', ...$fields]]);

    expect($buffer->read($buffer->claimBatches()[0]['path']))->toHaveCount(1);
})->with([
    'status' => [['status' => 'broken']],
    'status not a string' => [['status' => 1]],
    'severity' => [['severity' => 'urgent']],
    'severity not a string' => [['severity' => null]],
    'fingerprints' => [['fingerprints' => [1]]],
    'fingerprints not a list' => [['fingerprints' => 'abc']],
]);
