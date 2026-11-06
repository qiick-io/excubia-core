<?php

declare(strict_types=1);

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Qiick\Excubia\Checks\LaravelBackupsCheck;
use Qiick\Excubia\Configuration\ExcubiaConfigurationLoader;
use Qiick\Excubia\Data\CheckResultData;
use Qiick\Excubia\Data\ExcubiaConfigurationData;
use Qiick\Excubia\Data\LaravelPostureData;
use Qiick\Excubia\Data\ProjectSnapshotData;
use Qiick\Excubia\Exceptions\InvalidConfigurationException;
use Qiick\Excubia\Laravel\LaravelPostureService;

/**
 * Stand-in for spatie/laravel-backup's `Backup`: a date and a size.
 */
final class FakeBackupFile
{
    public function __construct(private readonly int $timestamp, private readonly float $sizeInBytes) {}

    public function date(): DateTimeImmutable
    {
        return new DateTimeImmutable('@'.$this->timestamp);
    }

    public function sizeInBytes(): float
    {
        return $this->sizeInBytes;
    }

    public function path(): string
    {
        return '/srv/path-sentinel/backup.zip';
    }
}

/**
 * Stand-in for spatie/laravel-backup's `BackupDestination` (the package is
 * not a dependency), aliased under its real class name. `$disks` holds the
 * backups per disk name (in no particular order), `unreachable` for a disk
 * that is not reachable, or `throws` for one whose driver throws.
 */
final class FakeBackupDestination
{
    /**
     * @var array<string, list<FakeBackupFile>|string>
     */
    public static array $disks = [];

    /**
     * @var list<array{0: string, 1: string}>
     */
    public static array $created = [];

    private function __construct(private readonly string $diskName) {}

    public static function create(string $diskName, string $backupName): self
    {
        self::$created[] = [$diskName, $backupName];

        if ((self::$disks[$diskName] ?? null) === 'throws') {
            throw new RuntimeException('Error executing "ListObjects" on "https://bucket-sentinel.s3.amazonaws.com"; secret-sentinel');
        }

        return new self($diskName);
    }

    public function isReachable(): bool
    {
        return is_array(self::$disks[$this->diskName] ?? []);
    }

    /**
     * @return Collection<int, FakeBackupFile>
     */
    public function backups(): Collection
    {
        $backups = self::$disks[$this->diskName] ?? [];

        return new Collection(is_array($backups) ? $backups : []);
    }
}

const BACKUP_DESTINATION_CLASS = 'Spatie\Backup\BackupDestination\BackupDestination';

if (! class_exists(BACKUP_DESTINATION_CLASS)) {
    class_alias(FakeBackupDestination::class, BACKUP_DESTINATION_CLASS);
}

/**
 * spatie/laravel-backup config monitoring the given disks, with disk roots,
 * a bucket and credentials that must never reach the report.
 *
 * @param  array<string, list<FakeBackupFile>|string>  $disks
 */
function backupApp(array $disks): void
{
    FakeBackupDestination::$disks = $disks;
    FakeBackupDestination::$created = [];
    app()->setBasePath(temporaryProject([
        'composer.json' => '{}',
        'composer.lock' => '{"packages": [], "packages-dev": []}',
    ]));
    config([
        'filesystems.disks.local-backups' => ['driver' => 'local', 'root' => '/srv/path-sentinel/backups'],
        'filesystems.disks.s3-backups' => ['driver' => 's3', 'bucket' => 'bucket-sentinel', 'key' => 'key-sentinel', 'secret' => 'secret-sentinel'],
        'backup.backup' => ['name' => 'app-name-sentinel', 'destination' => ['disks' => ['local-backups']]],
        'backup.monitor_backups' => [['name' => 'app-name-sentinel', 'disks' => array_keys($disks), 'health_checks' => []]],
    ]);
}

function backupFile(int $hoursAgo, float $megabytes): FakeBackupFile
{
    return new FakeBackupFile(time() - $hoursAgo * 3600 - 60, $megabytes * 1_048_576);
}

function backupsResult(?ExcubiaConfigurationData $configuration = null, bool $isBooted = true): CheckResultData
{
    $configuration ??= ExcubiaConfigurationData::defaults();
    $snapshot = new ProjectSnapshotData(null, null, [], [], null);

    if ($isBooted) {
        $snapshot = $snapshot->withLaravelPosture((new LaravelPostureService)->collect(app(), configuration: $configuration));
    }

    return (new LaravelBackupsCheck)->run($snapshot, $configuration);
}

function backupsConfiguration(int $maxAgeHours): ExcubiaConfigurationData
{
    return (new ExcubiaConfigurationLoader)->load(null, temporaryProject([
        'excubia.json' => json_encode(['checks' => ['laravel-backups' => ['maxAgeHours' => $maxAgeHours]]], JSON_THROW_ON_ERROR),
    ]));
}

describe('laravel-backups', function () {
    it('is skipped without a posture', function () {
        $result = backupsResult(isBooted: false);

        expect($result->status->value)->toBe('skipped')
            ->and($result->findings[0]->message)->toContain('requires Artisan');
    });

    it('is skipped when spatie/laravel-backup is not installed', function () {
        $posture = new LaravelPostureData('production', false, true, true, true, false, [], 0, null, true, null, new DateTimeImmutable);
        $snapshot = (new ProjectSnapshotData(null, null, [], [], null))->withLaravelPosture($posture);
        $result = (new LaravelBackupsCheck)->run($snapshot, ExcubiaConfigurationData::defaults());

        expect($result->status->value)->toBe('skipped')
            ->and($result->findings[0]->message)->toBe('spatie/laravel-backup is not installed.');
    });

    it('passes when the newest backup of every monitored destination is fresh, reading them by disk and backup name', function () {
        backupApp(['local-backups' => [backupFile(30, 10), backupFile(2, 10)], 's3-backups' => [backupFile(1, 12)]]);
        $result = backupsResult();

        expect($result->status->value)->toBe('pass')
            ->and($result->findings)->toBe([])
            ->and(FakeBackupDestination::$created)->toBe([['local-backups', 'app-name-sentinel'], ['s3-backups', 'app-name-sentinel']]);
    });

    it('fails at high when the newest backup is older than 26 hours, with disk, age, size and count', function () {
        backupApp(['local-backups' => [backupFile(27, 5.5), backupFile(51, 5)]]);
        $result = backupsResult();

        expect($result->status->value)->toBe('fail')
            ->and($result->findings[0]->severity->value)->toBe('high')
            ->and($result->findings[0]->subject)->toBe('local-backups')
            ->and($result->findings[0]->message)->toBe('The newest backup on local-backups is 27 hours old; the limit is 26 hours.')
            ->and($result->findings[0]->details)->toBe(['disk' => 'local-backups', 'ageHours' => 27, 'sizeMb' => 5.5, 'count' => 2]);
    });

    it('fails at high for a destination without backups, an unreachable one and one that throws', function () {
        backupApp(['local-backups' => [], 's3-backups' => 'unreachable', 'other-backups' => 'throws']);
        $result = backupsResult();

        expect($result->status->value)->toBe('fail')
            ->and(array_map(fn ($finding) => $finding->severity->value, $result->findings))->toBe(['high', 'high', 'high'])
            ->and(array_map(fn ($finding) => $finding->message, $result->findings))->toBe([
                'Backup destination local-backups has no backup.',
                'Backup destination s3-backups could not be reached.',
                'Backup destination other-backups could not be reached.',
            ])
            ->and(array_map(fn ($finding) => $finding->details, $result->findings))->toBe([
                ['disk' => 'local-backups', 'ageHours' => null, 'sizeMb' => null, 'count' => 0],
                ['disk' => 's3-backups', 'ageHours' => null, 'sizeMb' => null, 'count' => null],
                ['disk' => 'other-backups', 'ageHours' => null, 'sizeMb' => null, 'count' => null],
            ]);
    });

    it('warns at medium when the newest backup is less than half the size of the previous one', function () {
        backupApp(['local-backups' => [backupFile(26, 10), backupFile(2, 4.99)], 's3-backups' => [backupFile(2, 5), backupFile(26, 10)]]);
        $result = backupsResult();

        expect($result->status->value)->toBe('warn')
            ->and($result->findings)->toHaveCount(1)
            ->and($result->findings[0]->severity->value)->toBe('medium')
            ->and($result->findings[0]->subject)->toBe('local-backups')
            ->and($result->findings[0]->message)->toBe('The newest backup on local-backups is less than half the size of the previous one.')
            ->and($result->findings[0]->details)->toBe(['disk' => 'local-backups', 'ageHours' => 2, 'sizeMb' => 4.99, 'count' => 2]);
    });

    it('reads checks.laravel-backups.maxAgeHours', function () {
        backupApp(['local-backups' => [backupFile(30, 1)]]);

        expect(backupsResult()->status->value)->toBe('fail')
            ->and(backupsResult(backupsConfiguration(48))->status->value)->toBe('pass')
            ->and(ExcubiaConfigurationData::defaults()->backupsMaxAgeHours)->toBe(26)
            ->and(fn () => backupsConfiguration(0))->toThrow(InvalidConfigurationException::class, 'checks.laravel-backups.maxAgeHours must be at least 1.');
    });

    it('falls back to the backup destination disks when nothing is monitored', function () {
        backupApp(['local-backups' => [backupFile(1, 1)]]);
        config(['backup.monitor_backups' => []]);

        expect(backupsResult()->status->value)->toBe('pass')
            ->and(FakeBackupDestination::$created)->toBe([['local-backups', 'app-name-sentinel']]);
    });

    it('is skipped when no destination is configured or the monitor configuration is not a list', function () {
        backupApp([]);
        config(['backup.monitor_backups' => [], 'backup.backup' => null]);
        $none = backupsResult();
        config(['backup.monitor_backups' => 'local-backups']);
        $invalid = backupsResult();

        expect($none->status->value)->toBe('skipped')
            ->and($none->findings[0]->message)->toContain('has no backup destination configured')
            ->and($invalid->status->value)->toBe('skipped');
    });

    it('keeps paths, buckets, credentials and the backup name out of the report', function () {
        backupApp(['local-backups' => [backupFile(40, 1)], 's3-backups' => 'throws']);

        Artisan::call('excubia:check', ['--format' => 'json', '--no-banner' => true, '--fail-on' => 'none', '--only' => 'laravel-backups']);
        $output = Artisan::output();

        expect($output)->toContain('"disk": "local-backups"', '"disk": "s3-backups"')
            ->not->toContain('sentinel')
            ->not->toContain('/srv');
    });
});
