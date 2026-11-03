<?php

declare(strict_types=1);

namespace Qiick\Excubia\Laravel;

use Illuminate\Database\QueryException;
use Throwable;

/**
 * Turns a captured exception into one exception group of
 * `docs/exception-schema.json`, and merges groups. Pure functions: nothing
 * here touches the container, the buffer or the network.
 *
 * Masking (D17) runs before anything is written to the buffer: the message
 * is scrubbed, stack frames keep only a project-relative file, a line and a
 * `Class::method` name (never arguments or local variables), and absolute
 * paths become relative to the project (or a bare file name outside it).
 *
 * Fingerprint: sha256 of the exception class and the in-app frames as
 * `file|function` lines, without line numbers, so an edit that only moves
 * code keeps the group. Vendor frames are ignored; with no in-app frame the
 * top frame is used.
 */
final class ExceptionPayloadService
{
    public const MAX_MESSAGE_LENGTH = 1000;

    public const MAX_FRAMES = 50;

    public const MAX_PREVIOUS = 5;

    public const MASKED = '[masked]';

    /**
     * Built-in masks, in order. Placeholders contain no digits, so a later
     * rule never matches an earlier placeholder.
     *
     * @var array<string, string>
     */
    private const MASKS = [
        // Database connection details that Laravel appends to query errors.
        '/\b(Host|Socket|Database|Port): [^,)]*/' => '$1: [masked]',
        '/\beyJ[\w-]+\.[\w-]+\.[\w-]*/' => '[jwt]',
        '/\b(Bearer|Basic)\s+[^\s,;\'"]+/i' => '$1 [token]',
        // `key=value`, `key: value` and `'key' => 'value'`; a quoted value is masked whole, spaces included.
        '/\b([\w-]*(?:pass|pwd|secret|token|api[_-]?key|access[_-]?key|private[_-]?key|auth|signature|credentials?|cookie|session|dsn)[\w-]*)(["\']?\s*(?:=>|[=:])\s*)(?:(["\'])(?![\[?])[^"\'\n]*\3?|(?!(?:Bearer|Basic)\b|\[|\?)[^\s"\',;&)]+)/i' => '$1$2$3[masked]$3',
        '/[\w.%+-]+@[\w-]+(?:\.[\w-]+)+/' => '[email]',
        '/(?<![\d.])(?:\d{1,3}\.){3}\d{1,3}(?![\d.])/' => '[ip]',
        '/(?<![\w:])(?:(?:[0-9a-f]{1,4}:){7}[0-9a-f]{1,4}|(?:[0-9a-f]{1,4}:){0,6}[0-9a-f]{0,4}::(?:[0-9a-f]{1,4}:){0,6}[0-9a-f]{0,4})(?![\w:(])/i' => '[ip]',
        '/\b[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\b/i' => '[uuid]',
        '/\b[0-9a-f]{32,}\b/i' => '[token]',
        // API-key-like: 20+ letters and digits mixed (Stripe, AWS, GitHub, base64 segments).
        '/(?<![\w+-])(?=[\w+-]*\d)(?=[\w+-]*[a-z])[\w+-]{20,}={0,2}(?![\w+=-])/i' => '[token]',
        // Card-like: 13 to 19 digits, optionally grouped by spaces or dashes.
        '/(?<!\d)(?:\d[ -]?){12,18}\d(?!\d)/' => '[number]',
        // Any other number longer than four digits (ids, phone numbers, amounts).
        '/(?<!SQLSTATE\[)\d{5,}/' => '[number]',
    ];

    /**
     * Mask a message: user patterns first (they see the original text), then
     * absolute paths, URL credentials and query strings, then the built-in
     * masks; quoted literals too when the message carries SQL.
     *
     * @param  list<string>  $extraPatterns  validated by ExceptionConfigurationData
     */
    public static function mask(string $message, string $basePath, array $extraPatterns = []): string
    {
        foreach ($extraPatterns as $pattern) {
            $message = preg_replace($pattern, self::MASKED, $message) ?? self::MASKED;
        }

        $message = self::relativePaths($message, $basePath);
        $message = preg_replace_callback(
            '#\b([a-z][a-z0-9+.-]*://)(?:[^\s/@\'"]+@)?([^\s?\#\'"]*)([?\#][^\s\'"]*)?#i',
            static fn (array $matches): string => $matches[1].$matches[2].(isset($matches[3]) && $matches[3] !== '' ? '?[query]' : ''),
            $message,
        ) ?? self::MASKED;

        if (preg_match('/SQLSTATE|\bSQL:/', $message) === 1) {
            $message = preg_replace("/'(?:[^'\\\\]|\\\\.|'')*'/s", '?', $message) ?? self::MASKED;
        }

        foreach (self::MASKS as $pattern => $replacement) {
            $message = preg_replace($pattern, $replacement, $message) ?? self::MASKED;
        }

        return mb_strimwidth($message, 0, self::MAX_MESSAGE_LENGTH, '…');
    }

    /**
     * The stack, innermost first: the throw site, then each caller. Each
     * frame is the file and line where the code ran and the function it
     * ran in; no arguments, no local variables.
     *
     * @return list<array{file: string|null, line: int|null, function: string|null, inApp: bool}>
     */
    public static function frames(Throwable $throwable, string $basePath): array
    {
        $trace = $throwable->getTrace();
        $locations = [['file' => $throwable->getFile(), 'line' => $throwable->getLine()], ...$trace];
        $frames = [];

        foreach (array_slice($locations, 0, self::MAX_FRAMES) as $index => $location) {
            $caller = $trace[$index] ?? null;
            $function = null;

            if ($caller !== null) {
                $class = isset($caller['class']) ? self::className($caller['class']).'::' : '';
                $function = self::functionName($class.$caller['function'], $basePath);
            }

            $absoluteFile = isset($location['file']) && $location['file'] !== '' ? $location['file'] : null;
            $file = $absoluteFile === null ? null : self::relativeFile($absoluteFile, $basePath);

            $frames[] = [
                'file' => $file,
                'line' => $location['line'] ?? null,
                'function' => $function,
                'inApp' => $absoluteFile !== null && self::isInsideProject($absoluteFile, $basePath) && ! str_starts_with((string) $file, 'vendor/'),
            ];
        }

        return $frames;
    }

    /**
     * @param  list<array{file: string|null, line: int|null, function: string|null, inApp: bool}>  $frames
     */
    public static function fingerprint(string $class, array $frames): string
    {
        $inAppFrames = array_values(array_filter($frames, static fn (array $frame): bool => $frame['inApp']));
        $grouping = $inAppFrames === [] ? array_slice($frames, 0, 1) : $inAppFrames;
        $lines = array_map(static fn (array $frame): string => ($frame['file'] ?? '').'|'.($frame['function'] ?? ''), $grouping);

        return hash('sha256', $class."\n".implode("\n", $lines));
    }

    /**
     * One occurrence as an exception group with `count` 1.
     *
     * @param  list<string>  $extraPatterns
     * @param  array{kind: string, name: string}  $context
     * @return array<string, mixed>
     */
    public static function group(Throwable $throwable, string $basePath, array $extraPatterns, array $context, string $environment, string $seenAt): array
    {
        $class = self::className($throwable::class);
        $frames = self::frames($throwable, $basePath);
        $previous = [];

        for ($cause = $throwable->getPrevious(); $cause !== null && count($previous) < self::MAX_PREVIOUS; $cause = $cause->getPrevious()) {
            $previous[] = self::className($cause::class);
        }

        return [
            'class' => $class,
            'fingerprint' => self::fingerprint($class, $frames),
            'message' => self::mask(self::rawMessage($throwable), $basePath, $extraPatterns),
            'frames' => $frames,
            'previous' => $previous,
            'context' => ['kind' => $context['kind'], 'name' => self::mask($context['name'], $basePath, $extraPatterns)],
            'environment' => $environment,
            'count' => 1,
            'firstSeen' => $seenAt,
            'lastSeen' => $seenAt,
        ];
    }

    /**
     * Groups with the same fingerprint, context and environment become one:
     * counts add up, `firstSeen` / `lastSeen` widen, and the first group's
     * message and frames are kept. Entries that are not groups are skipped.
     *
     * @param  list<array<string, mixed>>  $groups
     * @return list<array<string, mixed>>
     */
    public static function merge(array $groups): array
    {
        $merged = [];

        foreach ($groups as $group) {
            if (! self::isGroup($group)) {
                continue;
            }

            $key = self::mergeKey($group);
            $merged[$key] = isset($merged[$key]) ? self::combine($merged[$key], $group) : $group;
        }

        return array_values($merged);
    }

    /**
     * @param  array<string, mixed>  $group
     */
    public static function mergeKey(array $group): string
    {
        return json_encode([$group['fingerprint'] ?? null, $group['context'] ?? null, $group['environment'] ?? null], JSON_THROW_ON_ERROR);
    }

    /**
     * @param  array<string, mixed>  $into
     * @param  array<string, mixed>  $from
     * @return array<string, mixed>
     */
    public static function combine(array $into, array $from): array
    {
        $into['count'] = (is_int($into['count'] ?? null) ? $into['count'] : 0) + (is_int($from['count'] ?? null) ? $from['count'] : 0);
        $into['firstSeen'] = min((string) ($into['firstSeen'] ?? ''), (string) ($from['firstSeen'] ?? ''));
        $into['lastSeen'] = max((string) ($into['lastSeen'] ?? ''), (string) ($from['lastSeen'] ?? ''));

        return $into;
    }

    /**
     * @param  array<string, mixed>  $group
     */
    private static function isGroup(array $group): bool
    {
        return is_string($group['class'] ?? null)
            && is_string($group['fingerprint'] ?? null)
            && is_string($group['message'] ?? null)
            && is_array($group['frames'] ?? null)
            && is_array($group['context'] ?? null)
            && is_int($group['count'] ?? null)
            && is_string($group['firstSeen'] ?? null)
            && is_string($group['lastSeen'] ?? null);
    }

    /**
     * Query errors carry the SQL with its bindings and the database host in
     * their message; rebuild it from the driver message, the connection name
     * and the SQL without bindings.
     */
    private static function rawMessage(Throwable $throwable): string
    {
        $message = $throwable->getMessage();

        if (! $throwable instanceof QueryException) {
            return $message;
        }

        $position = strpos($message, ' (Connection: ');
        $driverMessage = $position === false ? '' : substr($message, 0, $position);

        return $driverMessage.' (Connection: '.$throwable->getConnectionName().', SQL: '.RuntimeCollectorService::maskSql($throwable->getSql()).')';
    }

    /**
     * Anonymous classes carry the absolute file path in their name; keep
     * only `Parent@anonymous`.
     */
    private static function className(string $class): string
    {
        $position = strpos($class, '@anonymous');

        return $position === false ? $class : substr($class, 0, $position + strlen('@anonymous'));
    }

    /**
     * PHP 8.4 closure names hold a file or method and a line
     * (`{closure:App\Foo::bar():12}`); the line goes, a path becomes relative.
     */
    private static function functionName(string $function, string $basePath): string
    {
        return preg_replace_callback(
            '/\{closure:(.*):\d+\}/',
            static fn (array $matches): string => '{closure:'.(str_contains($matches[1], '/') ? self::relativeFile($matches[1], $basePath) : $matches[1]).'}',
            $function,
        ) ?? $function;
    }

    private static function relativeFile(string $file, string $basePath): string
    {
        $file = preg_replace("/\\(\\d+\\) : eval\\(\\)'d code$/", '', $file) ?? $file;
        $prefix = rtrim($basePath, '/\\').DIRECTORY_SEPARATOR;

        return str_starts_with($file, $prefix) ? substr($file, strlen($prefix)) : basename($file);
    }

    private static function isInsideProject(string $file, string $basePath): bool
    {
        return str_starts_with($file, rtrim($basePath, '/\\').DIRECTORY_SEPARATOR);
    }

    /**
     * The project root is removed; any other absolute path becomes its file
     * name. Slashes after a scheme (`https://`) or a host are left alone.
     */
    private static function relativePaths(string $message, string $basePath): string
    {
        $base = rtrim($basePath, '/\\');
        $withoutBasePath = $base === '' ? $message : str_replace([$base.DIRECTORY_SEPARATOR, $base], '', $message);

        return preg_replace('#(?<![\w.:/-])/(?:[^\s\'"/]+/)+([^\s\'"/]+)#', '$1', $withoutBasePath) ?? $withoutBasePath;
    }
}
