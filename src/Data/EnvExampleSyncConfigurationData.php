<?php

declare(strict_types=1);

namespace Qiick\Excubia\Data;

use Qiick\Excubia\Exceptions\InvalidConfigurationException;

/**
 * The `checks.env-example-sync` keys of `excubia.json` (#13).
 *
 * A key that matches an ignore glob (`*` any characters, `?` exactly one,
 * case-insensitive, whole name) is left out in both directions: it is
 * neither reported as missing from `.env.example` nor as unused.
 * `ignoreKeys` extends `DEFAULT_IGNORE_KEYS`; with `useDefaultIgnoreKeys`
 * false it replaces them.
 */
final readonly class EnvExampleSyncConfigurationData
{
    /**
     * Optional keys the Laravel 12 and 13 config files read (published or
     * merged by the framework) that a fresh application's `.env.example`
     * does not list, plus the keys that `.env.example` lists for tools other
     * than `config/` (Vite, `php artisan serve`) or for config files a fresh
     * application does not publish (`hashing.php`, `broadcasting.php`).
     * With these defaults a fresh Laravel application reports nothing.
     */
    public const DEFAULT_IGNORE_KEYS = [
        'ABLY_*',
        'APP_PREVIOUS_KEYS',
        'ARGON_*',
        'ASSET_URL',
        'AUTH_*',
        'AWS_*',
        'BCRYPT_LIMIT',
        'BCRYPT_ROUNDS',
        'BEANSTALKD_*',
        'BROADCAST_CONNECTION',
        'CACHE_STORAGE_*',
        'CONCURRENCY_DRIVER',
        'DB_CACHE_*',
        'DB_CHARSET',
        'DB_COLLATION',
        'DB_DIRECT_*',
        'DB_FOREIGN_KEYS',
        'DB_MASK_BINDINGS',
        'DB_POOLED',
        'DB_QUEUE*',
        'DB_SOCKET',
        'DB_SSLMODE',
        'DB_URL',
        'DYNAMODB_*',
        'FRONTEND_URL',
        'HASH_DRIVER',
        'HASH_VERIFY',
        'IMAGE_DRIVER',
        'LOG_DAILY_DAYS',
        'LOG_DEPRECATIONS_TRACE',
        'LOG_PAPERTRAIL_*',
        'LOG_SLACK_*',
        'LOG_STDERR_*',
        'LOG_SYSLOG_*',
        'MAIL_EHLO_DOMAIN',
        'MAIL_LOG_CHANNEL',
        'MAIL_MARKDOWN_THEME',
        'MAIL_SENDMAIL_PATH',
        'MAIL_URL',
        'MEMCACHED_*',
        'MERCURE_*',
        'MYSQL_ATTR_SSL_CA',
        'PAPERTRAIL_*',
        'PHP_CLI_SERVER_WORKERS',
        'POSTMARK_*',
        'PUSHER_*',
        'QUEUE_FAILED_DRIVER',
        'REDIS_BACKOFF_*',
        'REDIS_CACHE_*',
        'REDIS_CLIENT',
        'REDIS_CLUSTER',
        'REDIS_COMMAND_RETRIES',
        'REDIS_DB',
        'REDIS_MAX_RETRIES',
        'REDIS_PERSISTENT',
        'REDIS_PREFIX',
        'REDIS_QUEUE*',
        'REDIS_URL',
        'REDIS_USERNAME',
        'RESEND_*',
        'REVERB_*',
        'SESSION_CACHE_KEY',
        'SESSION_CONNECTION',
        'SESSION_COOKIE',
        'SESSION_EXPIRE_ON_CLOSE',
        'SESSION_HTTP_ONLY',
        'SESSION_PARTITIONED_COOKIE',
        'SESSION_SAME_SITE',
        'SESSION_SECURE_COOKIE',
        'SESSION_STORE',
        'SESSION_TABLE',
        'SLACK_BOT_USER_*',
        'SQS_*',
        'VIEW_COMPILED_PATH',
        'VITE_*',
    ];

    private const KEY_PATTERN_SYNTAX = '#^[A-Za-z0-9_*?]+$#';

    /**
     * @param  list<string>  $ignoreKeys  `checks.env-example-sync.ignoreKeys`
     *
     * @throws InvalidConfigurationException
     */
    public function __construct(
        public array $ignoreKeys = [],
        public bool $shouldUseDefaultIgnoreKeys = true,
    ) {
        foreach ($this->ignoreKeys as $index => $pattern) {
            if (preg_match(self::KEY_PATTERN_SYNTAX, $pattern) !== 1) {
                throw new InvalidConfigurationException('checks.env-example-sync.ignoreKeys['.$index.'] must be an environment key name or glob: letters, digits, _ and the wildcards * and ?.');
            }
        }
    }

    public function isIgnored(string $key): bool
    {
        foreach ($this->shouldUseDefaultIgnoreKeys ? [...self::DEFAULT_IGNORE_KEYS, ...$this->ignoreKeys] : $this->ignoreKeys as $pattern) {
            if (ExcubiaConfigurationData::matchesGlob($pattern, $key)) {
                return true;
            }
        }

        return false;
    }
}
