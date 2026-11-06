<?php

declare(strict_types=1);

namespace Qiick\Excubia\Laravel;

use Illuminate\Console\Application as ArtisanApplication;

/**
 * The name and the stable id of a scheduled task, shared by the runtime
 * `scheduledTask` aggregates (#109) and the report `schedule` inventory
 * (#146), so the app can match each run to its schedule entry.
 *
 * The name is the task description, else `artisan <command name>` for an
 * Artisan command, else the executable's file name for an `exec()` task,
 * else `Closure`. Arguments are dropped: they can carry secrets in forms no
 * mask recognises (`-pSECRET`, a positional token) and every distinct value
 * would be a new aggregate. The developer's description keeps its text,
 * with paths made relative and the exception message masks applied.
 */
final class ScheduledTaskIdentityService
{
    /**
     * @param  object  $task  an `Illuminate\Console\Scheduling\Event`; read through its public properties
     */
    public static function name(object $task, string $basePath): string
    {
        $description = $task->description ?? null;

        if (is_string($description) && $description !== '') {
            return ExceptionPayloadService::mask(self::relative($description, $basePath), $basePath);
        }

        $command = $task->command ?? null;

        if (! is_string($command) || $command === '') {
            return 'Closure';
        }

        $artisanPrefix = ArtisanApplication::formatCommandString('');

        if (str_starts_with($command, $artisanPrefix)) {
            return 'artisan '.self::firstWord(substr($command, strlen($artisanPrefix)));
        }

        // Leading environment assignments (`APP_KEY=… backup.sh`) are not the executable.
        $executable = self::firstWord(preg_replace('/^\s*(?:\w+=(?:\'[^\']*\'|"[^"]*"|\S*)\s+)*/', '', $command) ?? '');

        return basename(str_replace('\\', '/', $executable));
    }

    /**
     * `taskId`: `sha256(task + "\0" + expression)` as lowercase hex. Two
     * entries with the same name and cron expression share an id.
     */
    public static function id(string $name, string $expression): string
    {
        return hash('sha256', $name."\0".$expression);
    }

    /**
     * `$value` with the base path removed and any other absolute path cut
     * down to its file name.
     */
    public static function relative(string $value, string $basePath): string
    {
        $withoutBasePath = str_replace([$basePath.DIRECTORY_SEPARATOR, $basePath], '', $value);

        return preg_replace('#(?<![\w.-])/(?:[^\s\'"/]+/)+([^\s\'"/]+)#', '$1', $withoutBasePath) ?? $withoutBasePath;
    }

    /**
     * The first shell word, without its quotes (`'/opt/my tools/run.sh' --x`
     * gives `/opt/my tools/run.sh`). An empty command gives `unknown`.
     */
    private static function firstWord(string $command): string
    {
        $command = ltrim($command);

        if (preg_match('/^(?:\'([^\']*)\'|"([^"]*)"|(\S+))/', $command, $matches) !== 1) {
            return 'unknown';
        }

        $word = $matches[3] ?? $matches[2] ?? $matches[1];

        return $word === '' ? 'unknown' : $word;
    }
}
