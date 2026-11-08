<?php

declare(strict_types=1);

use Illuminate\Foundation\Application;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use JsonSchema\Validator;
use Laravel\Cashier\Events\WebhookHandled as StripeWebhookHandled;
use Laravel\Cashier\Events\WebhookReceived as StripeWebhookReceived;
use Laravel\Cashier\Http\Controllers\WebhookController as StripeWebhookController;
use Laravel\Paddle\Events\WebhookReceived as PaddleWebhookReceived;
use Laravel\Paddle\Http\Controllers\WebhookController as PaddleWebhookController;
use Qiick\Excubia\Checks\LaravelCashierCheck;
use Qiick\Excubia\Contracts\HttpClientContract;
use Qiick\Excubia\Data\CheckResultData;
use Qiick\Excubia\Data\ExcubiaConfigurationData;
use Qiick\Excubia\Data\ProjectSnapshotData;
use Qiick\Excubia\Enums\FindingStatusEnum;
use Qiick\Excubia\Enums\SeverityEnum;
use Qiick\Excubia\Laravel\ExcubiaServiceProvider;
use Qiick\Excubia\Laravel\LaravelPostureService;
use Qiick\Excubia\Services\RuntimeBufferService;
use Qiick\Excubia\Tests\Support\FakeHttpClient;

require_once dirname(__DIR__).'/Support/FakeCashierClasses.php';

/*
 * Laravel Cashier (#217): the `billingWebhook` runtime type and the
 * `laravel-cashier` check, with Cashier (Stripe) and Cashier Paddle faked
 * under their real names (tests/Support/FakeCashierClasses.php): neither
 * package is a dependency.
 */
const CASHIER_MARKER = 'excubiacashiermarker7f2c';

/**
 * An app's own webhook controller, extending Cashier Paddle's as its docs
 * suggest.
 */
final class AppPaddleWebhookController extends PaddleWebhookController {}

/**
 * Boot Excubia with `runtime.billing` on (or these runtime keys), and the
 * two webhook routes as Cashier's service providers register them.
 *
 * @param  array<string, mixed>|null  $runtime
 */
function bootCashierRuntime(Application $app, ?array $runtime = null): void
{
    $app->setBasePath(temporaryProject(['excubia.json' => json_encode(['runtime' => $runtime ?? ['enabled' => true, 'billing' => ['enabled' => true]]], JSON_THROW_ON_ERROR)]));
    $app->useStoragePath(temporaryProject([]));
    $app->register(ExcubiaServiceProvider::class, true);
    Route::post('stripe/webhook', [StripeWebhookController::class, 'handleWebhook'])->name('cashier.webhook');
    Route::post('paddle/webhook', PaddleWebhookController::class)->name('cashier.paddle-webhook');
}

/**
 * @param  array<string, mixed>  $object
 */
function stripeWebhook(mixed $test, string $type, array $object = ['id' => 'in_1']): int
{
    return $test->postJson('stripe/webhook', ['id' => 'evt_1', 'object' => 'event', 'type' => $type, 'data' => ['object' => $object]])->getStatusCode();
}

/**
 * @param  array<string, mixed>  $data
 */
function paddleWebhook(mixed $test, string $eventType, array $data = ['id' => 'txn_1']): int
{
    return $test->postJson('paddle/webhook', ['event_id' => 'evt_1', 'event_type' => $eventType, 'data' => $data])->getStatusCode();
}

/**
 * @return Collection<string, array<string, mixed>>
 */
function bufferedBillingWebhooks(Application $app): Collection
{
    return bufferedOfType($app, 'billingWebhook')->keyBy(fn (array $entry): string => $entry['provider'].' '.$entry['eventType']);
}

/**
 * @return list<string>
 */
function billingRuntimeSchemaErrors(Application $app): array
{
    $validator = new Validator;
    $document = json_decode(json_encode(['bucketSeconds' => 60, 'aggregates' => bufferedRuntime($app)], JSON_THROW_ON_ERROR), false, 512, JSON_THROW_ON_ERROR);
    $validator->validate($document, (object) ['$ref' => 'file://'.dirname(__DIR__, 2).'/docs/runtime-schema.json']);

    return array_map(fn (array $error): string => $error['property'].': '.$error['message'], $validator->getErrors());
}

/**
 * A Stripe invoice and customer, and a Paddle transaction, full of personal
 * and payment data carrying the marker.
 *
 * @return array<string, mixed>
 */
function markedBillingObject(): array
{
    return [
        'id' => 'in_'.CASHIER_MARKER,
        'customer' => 'cus_'.CASHIER_MARKER,
        'customer_id' => 'ctm_'.CASHIER_MARKER,
        'subscription' => 'sub_'.CASHIER_MARKER,
        'subscription_id' => 'sub_'.CASHIER_MARKER,
        'customer_email' => CASHIER_MARKER.'@example.test',
        'email' => CASHIER_MARKER.'@example.test',
        'amount_due' => 987654321,
        'currency' => 'xts',
        'currency_code' => 'XTS',
        'details' => ['totals' => ['total' => '987654321', 'tax' => '123456789']],
        'payment_method_details' => ['card' => ['brand' => 'visa', 'last4' => '4242', 'exp_month' => 12, 'exp_year' => 2099, 'fingerprint' => 'fp_'.CASHIER_MARKER]],
        'metadata' => ['note' => CASHIER_MARKER],
    ];
}

it('keeps the billingWebhook type off by default and listens to Cashier only when switched on', function (array $runtime, int $added) {
    $before = count(Event::getListeners(StripeWebhookReceived::class));
    bootCashierRuntime($this->app, $runtime);

    expect(count(Event::getListeners(StripeWebhookReceived::class)))->toBe($before + $added)
        ->and(count(Event::getListeners(PaddleWebhookReceived::class)))->toBe($before + $added);
})->with([
    'runtime on, billing not switched on' => [['enabled' => true], 0],
    'runtime off' => [['enabled' => false, 'billing' => ['enabled' => true]], 0],
    'billing on' => [['enabled' => true, 'billing' => ['enabled' => true]], 1],
]);

it('records received, handled and failed Stripe webhooks per event type, with the payment signals and the handling time', function () {
    bootCashierRuntime($this->app);

    $statuses = [
        stripeWebhook($this, 'customer.subscription.deleted'),
        stripeWebhook($this, 'customer.subscription.deleted'),
        stripeWebhook($this, 'invoice.payment_action_required'),
        stripeWebhook($this, 'invoice.payment_failed'),
        stripeWebhook($this, 'customer.updated', ['id' => 'cus_1']),
    ];
    $entries = bufferedBillingWebhooks($this->app);
    $zeroSignals = ['paymentFailedCount' => 0, 'paymentActionRequiredCount' => 0, 'subscriptionCanceledCount' => 0];

    expect($statuses)->toBe([200, 200, 200, 200, 500])
        ->and($entries->keys()->sort()->values()->all())->toBe(['stripe customer.subscription.deleted', 'stripe customer.updated', 'stripe invoice.payment_action_required', 'stripe invoice.payment_failed'])
        ->and($entries['stripe customer.subscription.deleted'])->toMatchArray(['count' => 2, 'handledCount' => 2, 'errorCount' => 0, ...$zeroSignals, 'subscriptionCanceledCount' => 2])
        ->and($entries['stripe invoice.payment_action_required'])->toMatchArray(['count' => 1, 'handledCount' => 1, 'errorCount' => 0, ...$zeroSignals, 'paymentActionRequiredCount' => 1])
        ->and($entries['stripe invoice.payment_failed'])->toMatchArray(['count' => 1, 'handledCount' => 0, 'errorCount' => 0, ...$zeroSignals, 'paymentFailedCount' => 1])
        ->and($entries['stripe customer.updated'])->toMatchArray(['count' => 1, 'handledCount' => 0, 'errorCount' => 1, ...$zeroSignals])
        ->and($entries['stripe customer.updated']['totalMs'])->toBeFloat()->toBeGreaterThanOrEqual(0.0)
        ->and(array_sum($entries['stripe customer.subscription.deleted']['histogram']))->toBe(2)
        ->and(billingRuntimeSchemaErrors($this->app))->toBe([]);
});

it('records Paddle webhooks, Paddle Classic alert names included, and keeps only a well-formed event type', function () {
    bootCashierRuntime($this->app);

    $statuses = [
        paddleWebhook($this, 'transaction.completed'),
        paddleWebhook($this, 'transaction.payment_failed'),
        paddleWebhook($this, 'subscription.past_due'),
        paddleWebhook($this, 'subscription.canceled'),
        paddleWebhook($this, 'subscription.updated'),
        paddleWebhook($this, 'Transaction.Completed'),
        paddleWebhook($this, str_repeat('a', 65)),
    ];
    event(new PaddleWebhookReceived(['alert_name' => 'subscription_payment_failed']));
    $this->app->terminate();
    $entries = bufferedBillingWebhooks($this->app);

    expect($statuses)->toBe([200, 200, 200, 200, 500, 200, 200])
        ->and($entries['paddle transaction.completed'])->toMatchArray(['count' => 1, 'handledCount' => 1, 'errorCount' => 0, 'paymentFailedCount' => 0])
        ->and($entries['paddle transaction.payment_failed'])->toMatchArray(['count' => 1, 'handledCount' => 0, 'paymentFailedCount' => 1])
        ->and($entries['paddle subscription.past_due'])->toMatchArray(['count' => 1, 'paymentActionRequiredCount' => 1])
        ->and($entries['paddle subscription.canceled'])->toMatchArray(['count' => 1, 'handledCount' => 1, 'subscriptionCanceledCount' => 1])
        ->and($entries['paddle subscription.updated'])->toMatchArray(['count' => 1, 'handledCount' => 0, 'errorCount' => 1])
        ->and($entries['paddle unknown'])->toMatchArray(['count' => 2, 'handledCount' => 1, 'errorCount' => 0])
        ->and($entries['paddle subscription_payment_failed'])->toMatchArray(['count' => 1, 'paymentFailedCount' => 1])
        ->and(billingRuntimeSchemaErrors($this->app))->toBe([]);
});

it('never writes or pushes amounts, currencies, customer or subscription ids, emails, cards or the payload', function () {
    putenv('EXCUBIA_APP_URL=https://excubia.example.test/');
    putenv('EXCUBIA_APP_TOKEN=runtime-token');
    $client = new FakeHttpClient(['https://excubia.example.test/api/runtime' => ['stored' => true]]);
    bootCashierRuntime($this->app);
    $this->app->instance(HttpClientContract::class, $client);

    stripeWebhook($this, 'invoice.payment_failed', markedBillingObject());
    stripeWebhook($this, 'customer.subscription.deleted', markedBillingObject());
    stripeWebhook($this, 'customer.updated', markedBillingObject());
    stripeWebhook($this, 'invoice.'.CASHIER_MARKER.'@example.test', markedBillingObject());
    paddleWebhook($this, 'transaction.payment_failed', markedBillingObject());
    paddleWebhook($this, 'subscription.updated', markedBillingObject());
    paddleWebhook($this, 'transaction.'.CASHIER_MARKER.'-'.str_repeat('x', 64), markedBillingObject());

    $raw = (string) file_get_contents($this->app->storagePath('excubia/runtime/'.RuntimeBufferService::BUFFER_FILE));
    $keys = bufferedOfType($this->app, 'billingWebhook')->flatMap(fn (array $entry): array => array_keys($entry))->unique()->sort()->values()->all();
    $this->artisan('excubia:push-runtime')->assertExitCode(0);
    $pushed = json_encode($client->postedPayloads, JSON_THROW_ON_ERROR);
    putenv('EXCUBIA_APP_URL');
    putenv('EXCUBIA_APP_TOKEN');

    expect($raw)->toContain('invoice.payment_failed')
        ->and($pushed)->toContain('transaction.payment_failed')
        ->and($keys)->toBe(['bucket', 'count', 'errorCount', 'eventType', 'handledCount', 'histogram', 'maxMs', 'paymentActionRequiredCount', 'paymentFailedCount', 'provider', 'sampleRate', 'subscriptionCanceledCount', 'totalMs', 'type']);

    foreach ([$raw, $pushed] as $written) {
        expect($written)->not->toContain(CASHIER_MARKER)
            ->and($written)->not->toContain('@')
            ->and($written)->not->toContain('987654321')
            ->and($written)->not->toContain('123456789')
            ->and($written)->not->toContain('xts')
            ->and($written)->not->toContain('XTS')
            ->and($written)->not->toContain('visa')
            ->and($written)->not->toContain('cus_')
            ->and($written)->not->toContain('sub_')
            ->and($written)->not->toContain('evt_')
            ->and($written)->not->toContain('could not be updated');
    }
});

it('records nothing at sample rate 0', function () {
    bootCashierRuntime($this->app, ['enabled' => true, 'billing' => ['enabled' => true, 'sampleRate' => 0]]);

    stripeWebhook($this, 'invoice.payment_failed');
    stripeWebhook($this, 'customer.updated');
    paddleWebhook($this, 'transaction.completed');

    expect(bufferedBillingWebhooks($this->app))->toHaveCount(0);
});

it('settles a webhook dispatched outside a request when the process terminates, and ignores a stray WebhookHandled', function () {
    bootCashierRuntime($this->app);

    event(new StripeWebhookHandled(['type' => 'invoice.paid']));
    $received = new StripeWebhookReceived(['type' => 'invoice.paid']);
    $received->payload = 'not an array';
    event($received);
    event(new StripeWebhookReceived(['type' => 'invoice.paid']));
    $this->app->terminate();
    $entries = bufferedBillingWebhooks($this->app);

    expect($entries->keys()->sort()->values()->all())->toBe(['stripe invoice.paid', 'stripe unknown'])
        ->and($entries['stripe invoice.paid'])->toMatchArray(['count' => 1, 'handledCount' => 0, 'errorCount' => 0])
        ->and($entries['stripe unknown'])->toMatchArray(['count' => 1, 'handledCount' => 0, 'errorCount' => 0]);
});

/**
 * `laravel-cashier` on the booted test app, with this `cashier`
 * configuration (null leaves it unset).
 *
 * @param  array<string, mixed>|null  $cashier
 */
function cashierCheckResult(?array $cashier, string $environment = 'production', bool $isBooted = true): CheckResultData
{
    $snapshot = new ProjectSnapshotData(null, null, [], [], null);

    if ($cashier !== null) {
        config(['cashier' => $cashier]);
    }

    app()['env'] = $environment;

    if ($isBooted) {
        $snapshot = $snapshot->withLaravelPosture((new LaravelPostureService)->collect(app(), checkIds: ['laravel-cashier']));
    }

    return (new LaravelCashierCheck)->run($snapshot, ExcubiaConfigurationData::defaults());
}

/**
 * A `cashier` configuration that merges the keys of both packages, as the
 * two service providers would.
 *
 * @return array<string, mixed>
 */
function cashierConfiguration(bool $isLive = true, bool $hasSecrets = true): array
{
    return [
        'key' => $isLive ? 'pk_live_'.CASHIER_MARKER : 'pk_test_'.CASHIER_MARKER,
        'secret' => $isLive ? 'sk_live_'.CASHIER_MARKER : 'sk_test_'.CASHIER_MARKER,
        'path' => 'stripe',
        'webhook' => ['secret' => $hasSecrets ? 'whsec_'.CASHIER_MARKER : null, 'tolerance' => 300],
        'seller_id' => '12345',
        'client_side_token' => $isLive ? 'live_'.CASHIER_MARKER : 'test_'.CASHIER_MARKER,
        'api_key' => $isLive ? 'pdl_live_apikey_'.CASHIER_MARKER : 'pdl_sdbx_apikey_'.CASHIER_MARKER,
        'webhook_secret' => $hasSecrets ? 'pdl_ntfset_'.CASHIER_MARKER : '',
        'sandbox' => false,
    ];
}

/**
 * @return array<string, array{severity: SeverityEnum, status: FindingStatusEnum}>
 */
function cashierFindingsBySubject(CheckResultData $result): array
{
    $findings = [];

    foreach ($result->findings as $finding) {
        $findings[$finding->subject] = ['severity' => $finding->severity, 'status' => $finding->status];
    }

    ksort($findings);

    return $findings;
}

describe('laravel-cashier', function () {
    it('is skipped without a posture, and when no Cashier package is configured', function () {
        expect(cashierCheckResult(null)->findings[0]->message)->toBe('Neither laravel/cashier nor laravel/cashier-paddle is installed.')
            ->and(cashierCheckResult(cashierConfiguration(), isBooted: false)->status)->toBe(FindingStatusEnum::Skipped);
    });

    it('passes at info per package with live keys, webhook secrets and webhook routes, a subclass of the controller included', function () {
        Route::post('stripe/webhook', [StripeWebhookController::class, 'handleWebhook']);
        Route::post('billing/paddle', AppPaddleWebhookController::class);

        $result = cashierCheckResult(cashierConfiguration());

        expect($result->status)->toBe(FindingStatusEnum::Pass)
            ->and(cashierFindingsBySubject($result))->toBe([
                'paddle' => ['severity' => SeverityEnum::Info, 'status' => FindingStatusEnum::Pass],
                'stripe' => ['severity' => SeverityEnum::Info, 'status' => FindingStatusEnum::Pass],
            ])
            ->and($result->findings[0]->details)->toBe(['provider' => 'stripe', 'isWebhookSecretConfigured' => true, 'isWebhookRouteRegistered' => true, 'hasTestKeys' => false]);
    });

    it('fails at high in production without a webhook secret or with test keys, and warns without a webhook route', function () {
        $result = cashierCheckResult(cashierConfiguration(isLive: false, hasSecrets: false));
        $json = json_encode($result, JSON_THROW_ON_ERROR);

        expect($result->status)->toBe(FindingStatusEnum::Fail)
            ->and(cashierFindingsBySubject($result))->toBe([
                'paddle.keys' => ['severity' => SeverityEnum::High, 'status' => FindingStatusEnum::Fail],
                'paddle.webhook-route' => ['severity' => SeverityEnum::Medium, 'status' => FindingStatusEnum::Warn],
                'paddle.webhook-secret' => ['severity' => SeverityEnum::High, 'status' => FindingStatusEnum::Fail],
                'stripe.keys' => ['severity' => SeverityEnum::High, 'status' => FindingStatusEnum::Fail],
                'stripe.webhook-route' => ['severity' => SeverityEnum::Medium, 'status' => FindingStatusEnum::Warn],
                'stripe.webhook-secret' => ['severity' => SeverityEnum::High, 'status' => FindingStatusEnum::Fail],
            ])
            ->and($result->findings[0]->details)->toBe(['provider' => 'stripe', 'isWebhookSecretConfigured' => false, 'isWebhookRouteRegistered' => false, 'hasTestKeys' => true])
            ->and($json)->not->toContain(CASHIER_MARKER)
            ->and($json)->not->toContain('sk_test')
            ->and($json)->not->toContain('pk_test')
            ->and($json)->not->toContain('pdl_sdbx')
            ->and($json)->not->toContain('whsec');
    });

    it('takes Paddle\'s sandbox flag as test keys, and outside production allows test keys and warns low without a secret', function () {
        Route::post('stripe/webhook', [StripeWebhookController::class, 'handleWebhook']);
        Route::post('paddle/webhook', PaddleWebhookController::class);

        $sandbox = cashierCheckResult([...cashierConfiguration(), 'sandbox' => true]);
        $local = cashierCheckResult(cashierConfiguration(isLive: false, hasSecrets: false), environment: 'local');

        expect(cashierFindingsBySubject($sandbox))->toBe([
            'paddle.keys' => ['severity' => SeverityEnum::High, 'status' => FindingStatusEnum::Fail],
            'stripe' => ['severity' => SeverityEnum::Info, 'status' => FindingStatusEnum::Pass],
        ])
            ->and(cashierFindingsBySubject($local))->toBe([
                'paddle.webhook-secret' => ['severity' => SeverityEnum::Low, 'status' => FindingStatusEnum::Warn],
                'stripe.webhook-secret' => ['severity' => SeverityEnum::Low, 'status' => FindingStatusEnum::Warn],
            ]);
    });
});
