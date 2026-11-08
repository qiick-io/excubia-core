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
 * Laravel Cashier's webhook and key posture (#217), for Cashier (Stripe,
 * `laravel/cashier`) and Cashier Paddle (`laravel/cashier-paddle`). Artisan
 * only, skipped when neither is installed (neither is a dependency).
 *
 * Per installed package, one row per problem: no webhook secret (Cashier
 * then skips the signature check, so anyone can post a forged webhook:
 * high in production, low elsewhere), test keys in production (high: no
 * real payment is taken), and no route to Cashier's webhook controller
 * (medium: subscriptions and failed payments never reach the app). One
 * info row when there is none. Booleans only: never a key, a secret or
 * a prefix of either.
 */
final class LaravelCashierCheck implements HealthCheckContract
{
    private const EVIDENCE_URLS = [
        'stripe' => 'https://laravel.com/docs/billing#handling-stripe-webhooks',
        'paddle' => 'https://laravel.com/docs/cashier-paddle#handling-paddle-webhooks',
    ];

    private const LABELS = [
        'stripe' => 'Cashier (Stripe)',
        'paddle' => 'Cashier Paddle',
    ];

    private const SECRET_FIX_HINTS = [
        'stripe' => 'Set STRIPE_WEBHOOK_SECRET to the signing secret of your Stripe webhook endpoint.',
        'paddle' => 'Set PADDLE_WEBHOOK_SECRET to the secret key of your Paddle notification destination.',
    ];

    private const KEYS_FIX_HINTS = [
        'stripe' => 'Set STRIPE_KEY and STRIPE_SECRET to your live keys in production.',
        'paddle' => 'Set PADDLE_SANDBOX=false and use your live API key and client-side token in production.',
    ];

    public function id(): string
    {
        return 'laravel-cashier';
    }

    public function name(): string
    {
        return 'Cashier billing';
    }

    public function run(ProjectSnapshotData $projectSnapshot, ExcubiaConfigurationData $configuration): CheckResultData
    {
        $posture = $projectSnapshot->laravelPosture;

        if ($posture === null) {
            return CheckResultData::skipped($this->id(), $this->name(), 'cashier', LaravelPostureData::REQUIRES_ARTISAN_MESSAGE);
        }

        if ($posture->cashier === null) {
            return CheckResultData::skipped($this->id(), $this->name(), 'cashier', 'Neither laravel/cashier nor laravel/cashier-paddle is installed.');
        }

        $isProduction = $posture->environment === 'production';
        $findings = [];

        foreach ($posture->cashier as $details) {
            $provider = $details['provider'];
            $label = self::LABELS[$provider] ?? 'Cashier';
            $evidenceUrl = self::EVIDENCE_URLS[$provider] ?? null;
            $problems = [];

            if (! $details['isWebhookSecretConfigured']) {
                $problems[] = $isProduction
                    ? $this->finding(SeverityEnum::High, FindingStatusEnum::Fail, $provider.'.webhook-secret', $label.' has no webhook secret: Cashier skips the signature check, so anyone who knows the URL can post a forged webhook.', self::SECRET_FIX_HINTS[$provider] ?? '', $evidenceUrl, $details)
                    : $this->finding(SeverityEnum::Low, FindingStatusEnum::Warn, $provider.'.webhook-secret', $label.' has no webhook secret: Cashier skips the signature check of incoming webhooks.', self::SECRET_FIX_HINTS[$provider] ?? '', $evidenceUrl, $details);
            }

            if ($isProduction && $details['hasTestKeys']) {
                $problems[] = $this->finding(SeverityEnum::High, FindingStatusEnum::Fail, $provider.'.keys', $label.' uses test keys in production: no real payment is taken.', self::KEYS_FIX_HINTS[$provider] ?? '', $evidenceUrl, $details);
            }

            if (! $details['isWebhookRouteRegistered']) {
                $problems[] = $this->finding(SeverityEnum::Medium, FindingStatusEnum::Warn, $provider.'.webhook-route', 'No route reaches the webhook controller of '.$label.': renewals, cancellations and failed payments never update your app.', 'Keep Cashier\'s routes (no Cashier::ignoreRoutes()), or route the webhook to a controller that extends Cashier\'s WebhookController.', $evidenceUrl, $details);
            }

            $findings = [
                ...$findings,
                ...($problems !== [] ? $problems : [$this->finding(SeverityEnum::Info, FindingStatusEnum::Pass, $provider, $label.' webhooks are signed and routed.', '', null, $details)]),
            ];
        }

        return CheckResultData::fromFindings($this->id(), $this->name(), $findings);
    }

    /**
     * @param  array{provider: string, isWebhookSecretConfigured: bool, isWebhookRouteRegistered: bool, hasTestKeys: bool}  $details
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
