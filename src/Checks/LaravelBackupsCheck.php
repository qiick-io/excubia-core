<?php

declare(strict_types=1);

namespace Qiick\Excubia\Checks;

use DateTimeImmutable;
use Qiick\Excubia\Contracts\HealthCheckContract;
use Qiick\Excubia\Data\CheckResultData;
use Qiick\Excubia\Data\ExcubiaConfigurationData;
use Qiick\Excubia\Data\FindingData;
use Qiick\Excubia\Data\LaravelPostureData;
use Qiick\Excubia\Data\ProjectSnapshotData;
use Qiick\Excubia\Enums\FindingStatusEnum;
use Qiick\Excubia\Enums\SeverityEnum;

/**
 * Reports the backups spatie/laravel-backup makes (#171), like
 * spatie/laravel-health's `BackupsCheck`. Artisan only, and skipped when
 * spatie/laravel-backup is not installed (it is not a dependency).
 *
 * One row per monitored destination (subject the disk name): no backup, an
 * unreachable destination or a newest backup older than
 * `checks.laravel-backups.maxAgeHours` (default 26) fails at high; a newest
 * backup less than half the size of the previous one warns at medium.
 * Disk names, ages, sizes and counts only: never a path, bucket or
 * credential.
 */
final class LaravelBackupsCheck implements HealthCheckContract
{
    private const BYTES_PER_MEGABYTE = 1_048_576;

    public function id(): string
    {
        return 'laravel-backups';
    }

    public function name(): string
    {
        return 'Backups';
    }

    public function run(ProjectSnapshotData $projectSnapshot, ExcubiaConfigurationData $configuration): CheckResultData
    {
        $posture = $projectSnapshot->laravelPosture;

        if ($posture === null) {
            return CheckResultData::skipped($this->id(), $this->name(), 'backups', LaravelPostureData::REQUIRES_ARTISAN_MESSAGE);
        }

        if (! $posture->isBackupPackageInstalled) {
            return CheckResultData::skipped($this->id(), $this->name(), 'backups', 'spatie/laravel-backup is not installed.');
        }

        if ($posture->backupDestinations === null || $posture->backupDestinations === []) {
            return CheckResultData::skipped($this->id(), $this->name(), 'backups', 'spatie/laravel-backup has no backup destination configured (backup.monitor_backups or backup.backup.destination.disks).');
        }

        $findings = [];

        foreach ($posture->backupDestinations as $destination) {
            $finding = $this->finding($destination, $posture, $configuration->backupsMaxAgeHours);

            if ($finding !== null) {
                $findings[] = $finding;
            }
        }

        return CheckResultData::fromFindings($this->id(), $this->name(), $findings);
    }

    /**
     * @param  array{disk: string, isReachable: bool, count: int, newestAt: DateTimeImmutable|null, newestSizeBytes: int|null, previousSizeBytes: int|null}  $destination
     */
    private function finding(array $destination, LaravelPostureData $posture, int $maxAgeHours): ?FindingData
    {
        $disk = $destination['disk'];
        $ageSeconds = $destination['newestAt'] === null
            ? null
            : max(0, $posture->collectedAt->getTimestamp() - $destination['newestAt']->getTimestamp());
        $ageHours = $ageSeconds === null ? null : intdiv($ageSeconds, 3600);
        $details = [
            'disk' => $disk,
            'ageHours' => $ageHours,
            'sizeMb' => $destination['newestSizeBytes'] === null ? null : round($destination['newestSizeBytes'] / self::BYTES_PER_MEGABYTE, 2),
            'count' => $destination['isReachable'] ? $destination['count'] : null,
        ];

        $message = match (true) {
            ! $destination['isReachable'] => 'Backup destination '.$disk.' could not be reached.',
            $ageSeconds === null || $ageHours === null => 'Backup destination '.$disk.' has no backup.',
            $ageSeconds > $maxAgeHours * 3600 => 'The newest backup on '.$disk.' is '.$ageHours.' hours old; the limit is '.$maxAgeHours.' hours.',
            default => null,
        };

        if ($message !== null) {
            return $this->backupFinding(SeverityEnum::High, FindingStatusEnum::Fail, $disk, $message, 'Check that php artisan backup:run is scheduled and succeeds, and that the disk is reachable.', $details);
        }

        $previousSizeBytes = $destination['previousSizeBytes'];

        if ($previousSizeBytes === null || $destination['newestSizeBytes'] === null || $previousSizeBytes <= $destination['newestSizeBytes'] * 2) {
            return null;
        }

        return $this->backupFinding(SeverityEnum::Medium, FindingStatusEnum::Warn, $disk, 'The newest backup on '.$disk.' is less than half the size of the previous one.', 'Check that the newest backup holds every database and file it should.', $details);
    }

    /**
     * @param  array{disk: string, ageHours: int|null, sizeMb: float|null, count: int|null}  $details
     */
    private function backupFinding(SeverityEnum $severity, FindingStatusEnum $status, string $disk, string $message, string $fixHint, array $details): FindingData
    {
        return new FindingData(
            checkId: $this->id(),
            severity: $severity,
            status: $status,
            subject: $disk,
            message: $message,
            fixHint: $fixHint,
            evidenceUrl: 'https://spatie.be/docs/laravel-backup/v9/monitoring-the-health-of-all-backups/overview',
            details: $details,
        );
    }
}
