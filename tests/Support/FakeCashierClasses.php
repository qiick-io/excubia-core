<?php

declare(strict_types=1);

/*
 * Stand-ins for Laravel Cashier (Stripe, laravel/cashier 16.x) and Laravel
 * Cashier Paddle (laravel/cashier-paddle 2.x) (#217), under their real class
 * names: the `Cashier` classes, the `WebhookReceived` / `WebhookHandled`
 * events with their public `payload`, and the two webhook controllers, which
 * dispatch the events exactly as the real ones do (src/Http/Controllers/
 * WebhookController.php): `WebhookReceived` first, then the handler for the
 * event type when the controller has one, then `WebhookHandled`; nothing
 * more when the handler throws, and a bare 200 when there is no handler.
 * The handlers do nothing, except `customer.updated` (Stripe) and
 * `subscription.updated` (Paddle), which throw like a handler that fails.
 * Neither package is a dependency, so the tests fake them instead of
 * installing them. Loaded with require_once by the Cashier tests only; once
 * loaded, `class_exists()` sees both as installed for the rest of the run,
 * which changes nothing while `runtime.billing` is off and the app has no
 * `cashier` configuration.
 */

namespace Laravel\Cashier {
    class Cashier
    {
        public const VERSION = '16.9.0';
    }
}

namespace Laravel\Cashier\Events {
    use Illuminate\Foundation\Events\Dispatchable;

    class WebhookReceived
    {
        use Dispatchable;

        /** @var array<string, mixed> */
        public $payload;

        /** @param array<string, mixed> $payload */
        public function __construct(array $payload)
        {
            $this->payload = $payload;
        }
    }

    class WebhookHandled
    {
        use Dispatchable;

        /** @var array<string, mixed> */
        public $payload;

        /** @param array<string, mixed> $payload */
        public function __construct(array $payload)
        {
            $this->payload = $payload;
        }
    }
}

namespace Laravel\Cashier\Http\Controllers {
    use Illuminate\Http\Request;
    use Illuminate\Routing\Controller;
    use Illuminate\Support\Str;
    use Laravel\Cashier\Events\WebhookHandled;
    use Laravel\Cashier\Events\WebhookReceived;
    use RuntimeException;
    use Symfony\Component\HttpFoundation\Response;

    class WebhookController extends Controller
    {
        public function handleWebhook(Request $request): Response
        {
            $payload = json_decode($request->getContent(), true);
            $method = 'handle'.Str::studly(str_replace('.', '_', $payload['type']));

            WebhookReceived::dispatch($payload);

            if (method_exists($this, $method)) {
                $response = $this->{$method}($payload);

                WebhookHandled::dispatch($payload);

                return $response;
            }

            return new Response;
        }

        /** @param array<string, mixed> $payload */
        protected function handleCustomerSubscriptionDeleted(array $payload): Response
        {
            return new Response('Webhook Handled', 200);
        }

        /** @param array<string, mixed> $payload */
        protected function handleInvoicePaymentActionRequired(array $payload): Response
        {
            return new Response('Webhook Handled', 200);
        }

        /** @param array<string, mixed> $payload */
        protected function handleCustomerUpdated(array $payload): Response
        {
            throw new RuntimeException('Customer '.$payload['data']['object']['id'].' could not be updated.');
        }
    }
}

namespace Laravel\Paddle {
    class Cashier
    {
        public const VERSION = '2.8.1';
    }
}

namespace Laravel\Paddle\Events {
    use Illuminate\Foundation\Events\Dispatchable;

    class WebhookReceived
    {
        use Dispatchable;

        /** @var array<string, mixed> */
        public $payload;

        /** @param array<string, mixed> $payload */
        public function __construct(array $payload)
        {
            $this->payload = $payload;
        }
    }

    class WebhookHandled
    {
        use Dispatchable;

        /** @var array<string, mixed> */
        public $payload;

        /** @param array<string, mixed> $payload */
        public function __construct(array $payload)
        {
            $this->payload = $payload;
        }
    }
}

namespace Laravel\Paddle\Http\Controllers {
    use Illuminate\Http\Request;
    use Illuminate\Routing\Controller;
    use Illuminate\Support\Str;
    use Laravel\Paddle\Events\WebhookHandled;
    use Laravel\Paddle\Events\WebhookReceived;
    use RuntimeException;
    use Symfony\Component\HttpFoundation\Response;

    class WebhookController extends Controller
    {
        public function __invoke(Request $request): Response
        {
            $payload = $request->all();
            $method = 'handle'.Str::studly(Str::replace('.', ' ', $payload['event_type']));

            WebhookReceived::dispatch($payload);

            if (method_exists($this, $method)) {
                $this->{$method}($payload);

                WebhookHandled::dispatch($payload);

                return new Response('Webhook Handled');
            }

            return new Response;
        }

        /** @param array<string, mixed> $payload */
        protected function handleTransactionCompleted(array $payload): void {}

        /** @param array<string, mixed> $payload */
        protected function handleSubscriptionCanceled(array $payload): void {}

        /** @param array<string, mixed> $payload */
        protected function handleSubscriptionUpdated(array $payload): void
        {
            throw new RuntimeException('Subscription '.$payload['data']['id'].' could not be updated.');
        }
    }
}
