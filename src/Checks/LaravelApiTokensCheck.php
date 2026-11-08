<?php

declare(strict_types=1);

namespace Qiick\Excubia\Checks;

use Qiick\Excubia\Contracts\HealthCheckContract;
use Qiick\Excubia\Data\CheckResultData;
use Qiick\Excubia\Data\ExcubiaConfigurationData;
use Qiick\Excubia\Data\FindingData;
use Qiick\Excubia\Data\LaravelPostureData;
use Qiick\Excubia\Data\ProjectSnapshotData;
use Qiick\Excubia\Enums\FindingStatusEnum;
use Qiick\Excubia\Enums\SeverityEnum;

/**
 * API token posture of Laravel Sanctum (`laravel/sanctum`) and Laravel
 * Passport (`laravel/passport`) (#219). Artisan only, skipped when neither is
 * installed (neither is a dependency).
 *
 * Sanctum: tokens that never expire (`sanctum.expiration` null while some
 * token has no `expires_at`: low, medium in production), tokens unused for
 * 90 days (low), and in production a `stateful` list with a wildcard
 * (medium) or no domain (low). Passport: missing keys (high in production,
 * medium elsewhere), a private key file readable by others (high in
 * production, low elsewhere), and very long token lifetimes (low). One info
 * row per package when there is no problem. Counts and booleans only: never
 * a token, a name, an ability, a user id, a key, a path or a domain.
 */
final class LaravelApiTokensCheck implements HealthCheckContract
{
    /**
     * A Sanctum token not used for this many days counts as stale.
     */
    public const STALE_TOKEN_DAYS = 90;

    /**
     * Longest lifetimes, in minutes, before a Passport lifetime counts as
     * very long: 30 days for access tokens (the OAuth access token is meant
     * to be short-lived; Passport's default is one year), a year for
     * refresh and personal access tokens (Passport's defaults stay under).
     */
    public const MAX_ACCESS_TOKEN_LIFETIME_MINUTES = 30 * 24 * 60;

    public const MAX_LONG_LIVED_TOKEN_LIFETIME_MINUTES = 366 * 24 * 60;

    private const SANCTUM_EVIDENCE_URL = 'https://laravel.com/docs/sanctum#token-expiration';

    private const PASSPORT_EVIDENCE_URL = 'https://laravel.com/docs/passport#deploying-passport';

    public function id(): string
    {
        return 'laravel-api-tokens';
    }

    public function name(): string
    {
        return 'API tokens';
    }

    public function run(ProjectSnapshotData $projectSnapshot, ExcubiaConfigurationData $configuration): CheckResultData
    {
        $posture = $projectSnapshot->laravelPosture;

        if ($posture === null) {
            return CheckResultData::skipped($this->id(), $this->name(), 'api-tokens', LaravelPostureData::REQUIRES_ARTISAN_MESSAGE);
        }

        if ($posture->apiTokens === null) {
            return CheckResultData::skipped($this->id(), $this->name(), 'api-tokens', 'Neither laravel/sanctum nor laravel/passport is installed.');
        }

        $isProduction = $posture->environment === 'production';
        $findings = [];

        foreach ($posture->apiTokens as $details) {
            $problems = $details['provider'] === 'sanctum'
                ? $this->sanctumProblems($details, $isProduction)
                : $this->passportProblems($details, $isProduction);

            $findings = [
                ...$findings,
                ...($problems !== [] ? $problems : [$this->finding(SeverityEnum::Info, FindingStatusEnum::Pass, $details['provider'], $this->summary($details), '', null, $details)]),
            ];
        }

        return CheckResultData::fromFindings($this->id(), $this->name(), $findings);
    }

    /**
     * @param  array<string, bool|int|string|null>  $details
     * @return list<FindingData>
     */
    private function sanctumProblems(array $details, bool $isProduction): array
    {
        $problems = [];
        $nonExpiring = $details['nonExpiringTokenCount'];

        if ($details['expirationMinutes'] === null && $nonExpiring !== 0) {
            $problems[] = $this->finding(
                $isProduction ? SeverityEnum::Medium : SeverityEnum::Low,
                FindingStatusEnum::Warn,
                'sanctum.expiration',
                'Sanctum tokens never expire: sanctum.expiration is null'.(is_int($nonExpiring) ? ', and '.$nonExpiring.' '.($nonExpiring === 1 ? 'token has' : 'tokens have').' no expires_at.' : '.').' A leaked token works until it is revoked.',
                'Set expiration in config/sanctum.php (minutes), or pass an expiry to createToken(), and schedule sanctum:prune-expired.',
                self::SANCTUM_EVIDENCE_URL,
                $details,
            );
        }

        $stale = $details['staleTokenCount'];

        if (is_int($stale) && $stale > 0) {
            $problems[] = $this->finding(
                SeverityEnum::Low,
                FindingStatusEnum::Warn,
                'sanctum.stale-tokens',
                $stale.' Sanctum '.($stale === 1 ? 'token has' : 'tokens have').' not been used for '.self::STALE_TOKEN_DAYS.' days or more ('.$details['neverUsedTokenCount'].' never used): forgotten tokens are credentials nobody watches.',
                'Revoke the tokens nobody uses, and set an expiration so they lapse on their own.',
                self::SANCTUM_EVIDENCE_URL,
                $details,
            );
        }

        if ($isProduction && $details['hasStatefulWildcard'] === true) {
            $problems[] = $this->finding(SeverityEnum::Medium, FindingStatusEnum::Warn, 'sanctum.stateful', 'sanctum.stateful has a wildcard: Sanctum authenticates requests from matching origins with the session cookie.', 'List the exact SPA domains in SANCTUM_STATEFUL_DOMAINS.', 'https://laravel.com/docs/sanctum#configuring-your-first-party-domains', $details);
        } elseif ($isProduction && $details['isStatefulEmpty'] === true) {
            $problems[] = $this->finding(SeverityEnum::Low, FindingStatusEnum::Warn, 'sanctum.stateful', 'sanctum.stateful names no domain: SPA cookie authentication is off. Ignore this if the app only uses API tokens.', 'Set SANCTUM_STATEFUL_DOMAINS to your SPA domains, or leave it empty on purpose for token-only APIs.', 'https://laravel.com/docs/sanctum#configuring-your-first-party-domains', $details);
        }

        return $problems;
    }

    /**
     * @param  array<string, bool|int|string|null>  $details
     * @return list<FindingData>
     */
    private function passportProblems(array $details, bool $isProduction): array
    {
        $problems = [];

        if ($details['hasKeys'] !== true) {
            $problems[] = $this->finding(
                $isProduction ? SeverityEnum::High : SeverityEnum::Medium,
                $isProduction ? FindingStatusEnum::Fail : FindingStatusEnum::Warn,
                'passport.keys',
                'Passport has no encryption keys: it cannot issue or verify tokens.',
                'Run php artisan passport:keys, or set PASSPORT_PRIVATE_KEY and PASSPORT_PUBLIC_KEY.',
                self::PASSPORT_EVIDENCE_URL,
                $details,
            );
        }

        if ($details['isPrivateKeyTooOpen'] === true) {
            $problems[] = $this->finding(
                $isProduction ? SeverityEnum::High : SeverityEnum::Low,
                $isProduction ? FindingStatusEnum::Fail : FindingStatusEnum::Warn,
                'passport.key-permissions',
                'Passport\'s private key file is readable or writable beyond its owner and group: whoever reads it can sign valid tokens.',
                'chmod 600 the oauth-private.key file (passport:keys writes it so).',
                self::PASSPORT_EVIDENCE_URL,
                $details,
            );
        }

        $longLifetimes = array_keys(array_filter([
            'access tokens' => $details['accessTokenLifetimeMinutes'] > self::MAX_ACCESS_TOKEN_LIFETIME_MINUTES,
            'refresh tokens' => $details['refreshTokenLifetimeMinutes'] > self::MAX_LONG_LIVED_TOKEN_LIFETIME_MINUTES,
            'personal access tokens' => $details['personalAccessTokenLifetimeMinutes'] > self::MAX_LONG_LIVED_TOKEN_LIFETIME_MINUTES,
        ]));

        if ($longLifetimes !== []) {
            $problems[] = $this->finding(
                SeverityEnum::Low,
                FindingStatusEnum::Warn,
                'passport.token-lifetimes',
                'Passport '.implode(', ', $longLifetimes).' live very long (access tokens over 30 days, refresh and personal access tokens over a year): a leaked token stays valid.',
                'Shorten them in AppServiceProvider with Passport::tokensExpireIn(), refreshTokensExpireIn() and personalAccessTokensExpireIn().',
                'https://laravel.com/docs/passport#token-lifetimes',
                $details,
            );
        }

        return $problems;
    }

    /**
     * @param  array<string, bool|int|string|null>  $details
     */
    private function summary(array $details): string
    {
        if ($details['provider'] === 'sanctum') {
            return 'Sanctum tokens '.($details['expirationMinutes'] === null ? 'carry their own expiry' : 'expire after '.$details['expirationMinutes'].' minutes')
                .(is_int($details['tokenCount']) ? '; '.$details['tokenCount'].' active.' : '.');
        }

        return 'Passport keys are in place and token lifetimes are bounded'
            .(is_int($details['personalAccessClientCount']) ? '; '.$details['personalAccessClientCount'].' personal access '.($details['personalAccessClientCount'] === 1 ? 'client.' : 'clients.') : '.');
    }

    /**
     * @param  array<string, bool|int|string|null>  $details
     */
    private function finding(SeverityEnum $severity, FindingStatusEnum $status, string $subject, string $message, string $fixHint, ?string $evidenceUrl, array $details): FindingData
    {
        return new FindingData(
            checkId: $this->id(),
            severity: $severity,
            status: $status,
            subject: $subject,
            message: $message,
            fixHint: $fixHint,
            evidenceUrl: $evidenceUrl,
            details: $details,
        );
    }
}
