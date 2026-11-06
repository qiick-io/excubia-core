<?php

declare(strict_types=1);

use Qiick\Excubia\Checks\NpmAdvisoriesCheck;
use Qiick\Excubia\Data\ExcubiaConfigurationData;
use Qiick\Excubia\Data\LockedPackageData;
use Qiick\Excubia\Data\ProjectSnapshotData;
use Qiick\Excubia\Enums\FindingStatusEnum;
use Qiick\Excubia\Enums\SeverityEnum;
use Qiick\Excubia\Services\OsvApiService;
use Qiick\Excubia\Services\ResponseCacheService;
use Qiick\Excubia\Tests\Support\FakeHttpClient;

function npmAdvisoriesCheck(array $document, bool $offline = false): NpmAdvisoriesCheck
{
    $directory = sys_get_temp_dir().'/excubia-npm-'.bin2hex(random_bytes(4));
    $client = new FakeHttpClient([
        'https://api.osv.dev/v1/querybatch' => $document,
    ]);

    return new NpmAdvisoriesCheck(new OsvApiService($client, new ResponseCacheService($directory, 24, $offline, false)));
}

function npmSnapshot(bool $hasLockfile, array $packages = []): ProjectSnapshotData
{
    return new ProjectSnapshotData(null, null, [], $packages, null, $hasLockfile);
}

it('skips when package-lock.json is absent', function () {
    $result = npmAdvisoriesCheck(['results' => []])->run(npmSnapshot(false), ExcubiaConfigurationData::defaults());

    expect($result->status)->toBe(FindingStatusEnum::Skipped)
        ->and($result->findings[0]->message)->toContain('package-lock.json');
});

it('fails with a CVSS score and defaults a vector to medium', function () {
    $result = npmAdvisoriesCheck([
        'results' => [
            ['vulns' => [['id' => 'GHSA-high', 'severity' => [['score' => '9.8']]]]],
            ['vulns' => [['id' => 'GHSA-vector', 'severity' => [['score' => 'CVSS:3.1/AV:N']]]]],
            ['vulns' => [['id' => 'GHSA-seven', 'severity' => [['score' => '7.5']]]]],
            ['vulns' => [['id' => 'GHSA-four', 'severity' => [['score' => '4.0']]]]],
            ['vulns' => [['id' => 'GHSA-one', 'severity' => [['score' => '1.0']]]]],
        ],
    ])->run(npmSnapshot(true, [
        new LockedPackageData('left-pad', '1.0.0', true, false),
        new LockedPackageData('lodash', '4.17.20', true, false),
        new LockedPackageData('a', '1.0.0', false, false),
        new LockedPackageData('b', '1.0.0', false, false),
        new LockedPackageData('c', '1.0.0', false, false),
    ]), ExcubiaConfigurationData::defaults());

    expect($result->status)->toBe(FindingStatusEnum::Fail)
        ->and($result->findings[0]->severity)->toBe(SeverityEnum::Critical)
        ->and($result->findings[0]->advisoryId)->toBe('GHSA-high')
        ->and($result->findings[0]->fixHint)->toBe('Update left-pad to ≥ fixed version.')
        ->and($result->findings[1]->severity)->toBe(SeverityEnum::Medium)
        ->and($result->findings[2]->severity)->toBe(SeverityEnum::High)
        ->and($result->findings[3]->severity)->toBe(SeverityEnum::Medium)
        ->and($result->findings[4]->severity)->toBe(SeverityEnum::Low);
});

it('passes an empty lockfile and skips an outage', function () {
    $empty = npmAdvisoriesCheck(['results' => []])->run(npmSnapshot(true), ExcubiaConfigurationData::defaults());
    $offline = npmAdvisoriesCheck(['results' => []], offline: true)->run(npmSnapshot(true, [
        new LockedPackageData('lodash', '4.17.20', true, false),
    ]), ExcubiaConfigurationData::defaults());

    expect($empty->status)->toBe(FindingStatusEnum::Pass)
        ->and($offline->status)->toBe(FindingStatusEnum::Skipped);
});

it('puts the package and locked version in details, with no fixed version from the OSV batch', function () {
    $result = npmAdvisoriesCheck([
        'results' => [['vulns' => [['id' => 'GHSA-lodash']]]],
    ])->run(npmSnapshot(true, [new LockedPackageData('lodash', '4.17.20', true, false)]), ExcubiaConfigurationData::defaults());

    expect($result->findings[0]->details)->toBe(['package' => 'lodash', 'lockedVersion' => '4.17.20', 'fixedVersions' => [], 'lockfile' => 'package-lock.json']);
});
