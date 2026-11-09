<?php

declare(strict_types=1);

namespace Qiick\Excubia\Laravel;

use Closure;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Auth\Events\Login;
use Illuminate\Cache\Events\CacheHit;
use Illuminate\Cache\Events\CacheMissed;
use Illuminate\Cache\Events\KeyForgotten;
use Illuminate\Cache\Events\KeyWritten;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskSkipped;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Http\Client\Events\ResponseReceived;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Log\Logger;
use Illuminate\Log\LogManager;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Notifications\Events\NotificationFailed;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Queue;
use Illuminate\Routing\Route;
use Illuminate\Support\Str;
use Laravel\Ai\Events\AgentFailed;
use Laravel\Ai\Events\AgentFailedOver;
use Laravel\Ai\Events\AgentPrompted;
use Laravel\Ai\Events\AgentStreamed;
use Laravel\Ai\Events\AudioFailed;
use Laravel\Ai\Events\AudioGenerated;
use Laravel\Ai\Events\ClassificationFailed;
use Laravel\Ai\Events\Classified;
use Laravel\Ai\Events\Classifying;
use Laravel\Ai\Events\EmbeddingsFailed;
use Laravel\Ai\Events\EmbeddingsGenerated;
use Laravel\Ai\Events\GeneratingAudio;
use Laravel\Ai\Events\GeneratingEmbeddings;
use Laravel\Ai\Events\GeneratingImage;
use Laravel\Ai\Events\GeneratingTranscription;
use Laravel\Ai\Events\ImageFailed;
use Laravel\Ai\Events\ImageGenerated;
use Laravel\Ai\Events\PromptingAgent;
use Laravel\Ai\Events\ProviderFailedOver;
use Laravel\Ai\Events\Reranked;
use Laravel\Ai\Events\Reranking;
use Laravel\Ai\Events\RerankingFailed;
use Laravel\Ai\Events\StreamingAgent;
use Laravel\Ai\Events\ToolFailed;
use Laravel\Ai\Events\ToolInvoked;
use Laravel\Ai\Events\TranscriptionFailed;
use Laravel\Ai\Events\TranscriptionGenerated;
use Laravel\Cashier\Events\WebhookHandled as StripeWebhookHandled;
use Laravel\Cashier\Events\WebhookReceived as StripeWebhookReceived;
use Laravel\Octane\Events\RequestReceived;
use Laravel\Octane\Events\RequestTerminated;
use Laravel\Octane\Events\TaskReceived;
use Laravel\Octane\Events\TickReceived;
use Laravel\Octane\Events\WorkerErrorOccurred;
use Laravel\Octane\Events\WorkerStarting;
use Laravel\Octane\Events\WorkerStopping;
use Laravel\Paddle\Events\WebhookHandled as PaddleWebhookHandled;
use Laravel\Paddle\Events\WebhookReceived as PaddleWebhookReceived;
use Laravel\Pennant\Events\FeatureResolved;
use Laravel\Pennant\Events\FeatureRetrieved;
use Laravel\Pennant\Events\UnknownFeatureResolved;
use Laravel\Reverb\Events\ChannelCreated;
use Laravel\Reverb\Events\ChannelRemoved;
use Laravel\Reverb\Events\ConnectionPruned;
use Laravel\Reverb\Events\MessageReceived as ReverbMessageReceived;
use Laravel\Reverb\Events\MessageSent as ReverbMessageSent;
use Laravel\Sanctum\Events\TokenAuthenticated;
use Laravel\Scout\Builder as ScoutBuilder;
use Laravel\Scout\Jobs\MakeSearchable;
use Laravel\Scout\Jobs\RemoveFromSearch;
use Laravel\Scout\Scout;
use Monolog\Logger as MonologLogger;
use Monolog\LogRecord;
use Qiick\Excubia\Data\RuntimeConfigurationData;
use Qiick\Excubia\Enums\RuntimeTypeEnum;
use Qiick\Excubia\Services\RuntimeBufferService;
use Qiick\Excubia\Services\ServerMetricsService;
use React\EventLoop\Loop;
use Throwable;
use WeakMap;
use WeakReference;

/**
 * Opt-in runtime aggregates (`docs/runtime-schema.json`, SPEC section 13).
 *
 * Listens to Laravel events, aggregates in memory per type, dimensions and
 * UTC minute (counts, total and maximum milliseconds or megabytes where the
 * type has them, and a latency histogram for durations) and writes the
 * aggregates to the local buffer when the request or command terminates,
 * and after each queued job, scheduled task or Artisan command (a queue worker terminates only
 * when it stops). Nothing is sent from here: `excubia:push-runtime` sends
 * the buffer.
 *
 * What is kept: route names (or `unnamed`), HTTP methods and status classes,
 * SQL text without bindings (quoted literals masked) and the connection name,
 * job class, queue and connection names, scheduled task names and ids
 * (`ScheduledTaskIdentityService`), outgoing hosts, peak memory, queue wait
 * times, cache store names and masked key prefixes, mailable, mailer, notification and
 * channel names, log levels and log channel names, the source package, masked message and PHP
 * minor version of PHP deprecations, the normalised SQL shape, connection
 * and route name or job class of queries repeated within one request or job
 * (`duplicateQuery`, #148), Artisan command names with their exit code
 * class (`command`, #152), and for the Laravel AI SDK the operation, agent
 * and tool class names, provider and model names, token counts and
 * durations (`ai`, `aiTool`, #198). Never request or response bodies,
 * headers, cookies, users, IP addresses, query bindings, exception messages,
 * whole cache keys or values, recipients, log messages or context, absolute
 * paths, the line and stack of a deprecation, command arguments and
 * options, or AI prompts, attachments, messages, responses, reasoning, tool
 * arguments or results and conversations, Octane URLs, request or task
 * data, worker ids or PIDs, Reverb message payloads, channel names,
 * socket ids, origins or app keys and secrets, anything of a Cashier
 * webhook payload but its event type, the user, credentials, token or
 * request of an authentication event, Scout search queries, record ids or
 * record data, or the scope or value of a Pennant feature.
 *
 * Every listener swallows its own exceptions: monitoring must never break or
 * slow down the host application. Excubia never logs, and the log listener
 * ignores messages logged while it runs, so it cannot loop.
 *
 * ponytail: the aggregates are flushed by `terminating()`; under Octane that
 * runs per request on the sandbox. A worker that never terminates and runs no
 * job keeps at most MAX_PENDING_AGGREGATES in memory before it writes them.
 *
 * ponytail: `duplicateQuery` evaluates the queries of an HTTP request at
 * `RequestHandled` and those of a queued job when it ends. Queries outside
 * both (Artisan commands, scheduled tasks, a worker between jobs) are never
 * evaluated and are dropped when the process terminates; a command or task
 * scope is the upgrade path.
 *
 * ponytail: request peak memory is the process peak; under Octane it only
 * grows across requests. Reset it per request if Octane users need it.
 */
final class RuntimeCollectorService
{
    public const MAX_PENDING_AGGREGATES = 500;

    public const MAX_SQL_LENGTH = 2000;

    /**
     * Key added to every job payload when `queueWait` is on: the Unix time,
     * with microseconds, when the job was pushed.
     */
    public const QUEUED_AT_PAYLOAD_KEY = 'excubiaQueuedAt';

    public const MASKED_KEY_PREFIX = '[masked]';

    public const MAX_DEPRECATION_MESSAGE_LENGTH = 300;

    /**
     * Distinct query shapes counted per request or job (`duplicateQuery`);
     * a new shape past it is ignored, so memory stays bounded.
     */
    public const MAX_QUERY_SHAPES = 200;

    /**
     * Raw SQL longer than this is normalised on every execution instead of
     * being remembered, so the memo of normalised SQL stays small.
     */
    private const MAX_MEMOISED_SQL_LENGTH = 4000;

    /**
     * Environment variable the scheduler commands set for the task processes
     * they start (#152), so a scheduled Artisan command, already counted as
     * `scheduledTask`, is not counted again as `command`.
     */
    public const SCHEDULER_ENVIRONMENT_VARIABLE = 'EXCUBIA_INSIDE_SCHEDULER';

    /**
     * Commands that run scheduled tasks: the ones they start, in the same
     * process or as child processes, are `scheduledTask`, never `command`.
     */
    private const SCHEDULER_COMMANDS = ['schedule:run', 'schedule:work', 'schedule:test'];

    /**
     * AI invocations whose start time is kept until they end (`ai`, #198):
     * one that never ends (a stream nobody reads) is forgotten, oldest first,
     * past this many.
     */
    public const MAX_PENDING_AI_INVOCATIONS = 200;

    private const BYTES_PER_MEGABYTE = 1048576;

    /**
     * The Octane servers `octane.server` may name (#213).
     */
    public const OCTANE_SERVERS = ['swoole', 'roadrunner', 'frankenphp'];

    /**
     * The container key of the client Octane binds in each worker, and the
     * server of each client class.
     */
    private const OCTANE_CLIENT = 'Laravel\\Octane\\Contracts\\Client';

    private const OCTANE_CLIENT_SERVERS = [
        'Laravel\\Octane\\Swoole\\SwooleClient' => 'swoole',
        'Laravel\\Octane\\RoadRunner\\RoadRunnerClient' => 'roadrunner',
        'Laravel\\Octane\\FrankenPhp\\FrankenPhpClient' => 'frankenphp',
    ];

    /**
     * The container key of the Swoole server Octane binds in a Swoole worker.
     */
    private const SWOOLE_SERVER = 'Swoole\\Http\\Server';

    /**
     * Laravel Reverb's container keys (#215): its application provider (the
     * configured apps) and its channel manager (the channels and the
     * connections subscribed to them, per app).
     */
    private const REVERB_APPLICATION_PROVIDER = 'Laravel\\Reverb\\Contracts\\ApplicationProvider';

    private const REVERB_CHANNEL_MANAGER = 'Laravel\\Reverb\\Protocols\\Pusher\\Contracts\\ChannelManager';

    /**
     * How Reverb's Pusher protocol starts the frames it sends for a new
     * connection, for an error, and for the error a pruned connection gets
     * (code 4201): `json_encode` of `['event' => …, 'data' => …]`. Only these
     * fixed prefixes are compared; nothing else of a message is read.
     */
    private const REVERB_CONNECTION_ESTABLISHED = '{"event":"pusher:connection_established"';

    private const REVERB_ERROR = '{"event":"pusher:error"';

    private const REVERB_PRUNED_ERROR = '{"event":"pusher:error","data":"{\\"code\\":4201,';

    /**
     * The Reverb app id when it is a short name; a longer or unusual one is
     * hashed.
     */
    private const REVERB_APPLICATION_ID_PATTERN = '/^[A-Za-z0-9_-]{1,32}$/';

    /**
     * A billing webhook's event type (#217) is kept only when it is a short
     * enum-like name such as `invoice.payment_failed`; anything else, which
     * a forged or unusual payload could carry, becomes `unknown`.
     */
    private const BILLING_EVENT_TYPE_PATTERN = '/^[a-z0-9_.]{1,64}$/';

    /**
     * The event types that are payment signals (#217), per provider, and
     * the counter each one adds to: Stripe's failed invoice payment,
     * payment that needs the customer's action (3D Secure) and ended
     * subscription; Paddle Billing's failed transaction payment, past due
     * subscription (renewal unpaid, the customer must update the payment
     * method) and canceled subscription, plus the Paddle Classic alert
     * names of cashier-paddle 1.x.
     */
    private const BILLING_SIGNALS = [
        'stripe' => [
            'invoice.payment_failed' => 'paymentFailedCount',
            'invoice.payment_action_required' => 'paymentActionRequiredCount',
            'customer.subscription.deleted' => 'subscriptionCanceledCount',
        ],
        'paddle' => [
            'transaction.payment_failed' => 'paymentFailedCount',
            'subscription_payment_failed' => 'paymentFailedCount',
            'subscription.past_due' => 'paymentActionRequiredCount',
            'subscription.canceled' => 'subscriptionCanceledCount',
            'subscription_cancelled' => 'subscriptionCanceledCount',
        ],
    ];

    /**
     * An auth guard name (#219) or a Scout engine name (#221) is kept only
     * when it looks like a config key; anything else becomes `unknown`.
     */
    private const CONFIG_NAME_PATTERN = '/^[A-Za-z0-9_.-]{1,64}$/';

    /**
     * The models of a Scout index job (#221), in its serialized command:
     * Laravel's `SerializesModels` writes the collection as a
     * `ModelIdentifier` whose `class` is the model class (null for an empty
     * collection) and whose `id` is the array of keys. Only the class and the
     * length of that array are read, never the keys.
     */
    private const SCOUT_MODELS_PATTERN = '/"Illuminate\\\\Contracts\\\\Database\\\\ModelIdentifier":\d+:\{s:5:"class";(?:s:\d+:"([^"]+)"|N);s:2:"id";a:(\d+):/';

    /**
     * The guard Laravel Sanctum registers, under which its token
     * authentications are counted.
     */
    private const SANCTUM_GUARD = 'sanctum';

    /**
     * A Pennant feature name (#225) is kept when it is a short name or a
     * class name (`new-checkout`, `App\Features\NewCheckout`); anything
     * else, which an app could build from user data, is hashed.
     */
    private const PENNANT_FEATURE_PATTERN = '/^[A-Za-z0-9_.:\\\\-]{1,128}$/';

    /**
     * Dimensions of the `pennant` aggregates per feature name, so a name is
     * checked or hashed once. At most MAX_PENDING_AGGREGATES entries;
     * cleared when full.
     *
     * @var array<string, array{feature: string}>
     */
    private array $pennantDimensions = [];

    private const LOG_LEVELS = ['emergency', 'alert', 'critical', 'error', 'warning', 'notice', 'info', 'debug'];

    /** @var array<string, array<string, mixed>> */
    private array $aggregates = [];

    /** @var WeakMap<object, float> start time of each running job */
    private WeakMap $jobStartedAt;

    /** @var WeakMap<object, true> tasks recorded as failed by ScheduledTaskFinished, before their ScheduledTaskFailed */
    private WeakMap $recordedTasks;

    /**
     * Highest peak seen before a job reset the PHP peak (`jobMemory`), so a
     * request that runs sync jobs still reports its own peak.
     */
    private int $peakMemoryBytes = 0;

    /**
     * Mailable and mailer of the message being sent: `MessageSending` sets
     * it, `MessageSent` clears it, and one still set when its scope ends is
     * a failed send (`settlePendingMail()`).
     *
     * @var array{mailable: string, mailer: string}|null
     */
    private ?array $pendingMail = null;

    private bool $isRecordingLog = false;

    /**
     * Log channel of the entry being written, set by the Monolog processor
     * `watchLogChannel()` pushes and read (then cleared) by the next
     * `MessageLogged`, which Laravel fires after Monolog ran the processors.
     */
    private ?string $logChannel = null;

    /**
     * The `datetime` of the log record `logChannel` belongs to. Each record
     * has its own; a stack runs its own processor first and then the ones
     * it copied from the channels inside it, so the first name set for a
     * record wins and a stack reports the stack's name.
     *
     * @var WeakReference<object>|null
     */
    private ?WeakReference $logChannelRecord = null;

    private bool $isRecordingDeprecation = false;

    /** @var list<string>|null */
    private ?array $projectPrefixes = null;

    private readonly bool $isCountingQueryShapes;

    /**
     * Executions per normalised SQL and connection in the current request or
     * job (`duplicateQuery`), at most MAX_QUERY_SHAPES shapes.
     *
     * @var array<string, array<string, int>> connection => normalised SQL => executions
     */
    private array $queryShapes = [];

    private int $queryShapeCount = 0;

    /**
     * The counts of the request or job around each running job, put back
     * when the job ends, so a sync job inside a request is counted apart.
     *
     * @var WeakMap<object, array{array<string, array<string, int>>, int}>
     */
    private WeakMap $outerQueryShapes;

    /**
     * Normalised SQL by raw SQL, so a query repeated in a loop is normalised
     * once. At most MAX_QUERY_SHAPES entries; cleared when full.
     *
     * @var array<string, string>
     */
    private array $normalisedSql = [];

    /**
     * Artisan commands running in this process: 1 for the top-level one,
     * more while it calls others through `Artisan::call()` (`command`).
     */
    private int $commandDepth = 0;

    /**
     * Start time of the top-level command when `command` counts it, else null
     * (ignored, Excubia's own, or run by the scheduler).
     */
    private ?float $commandStartedAt = null;

    /**
     * Whether this process set SCHEDULER_ENVIRONMENT_VARIABLE for the
     * scheduler command running now; it is removed when that command ends,
     * so a long-lived process that called `schedule:run` keeps counting.
     */
    private bool $hasMarkedScheduler = false;

    /**
     * Start time of each running AI operation by invocation id (`ai`), at
     * most MAX_PENDING_AI_INVOCATIONS.
     *
     * @var array<string, float>
     */
    private array $aiStartedAt = [];

    /**
     * The pending aggregate of the last failed image, embeddings,
     * transcription, reranking, audio or classification attempt, with its provider and
     * model, so the `ProviderFailedOver` that follows it counts the failover
     * on that aggregate (the event carries no invocation id or operation).
     *
     * @var array{key: string, provider: string, model: string}|null
     */
    private ?array $lastAiFailure = null;

    /**
     * Dimensions of this Octane worker's `octane` aggregates (#213): the
     * server identity, the Octane server and the worker type. Read once,
     * from the first Octane event the worker fires.
     *
     * @var array{server: string, octaneServer: string, workerType: string}|null
     */
    private ?array $octaneDimensions = null;

    /**
     * Requests this Octane worker received since it started, sampled or
     * not: its count when it stops, to compare with `max_requests`.
     */
    private int $octaneRequestCount = 0;

    /**
     * Whether a request was received and has not terminated yet: one that
     * ends in a worker error is counted with the error.
     */
    private bool $isOctaneRequestOpen = false;

    /**
     * Memory in use after the worker's first request, the base of the
     * growth (the leak signal).
     */
    private ?int $octaneFirstRequestBytes = null;

    /**
     * When an Octane listener last flushed the buffer. A worker never
     * terminates, so tasks and ticks flush at most once a minute.
     */
    private float $octaneFlushedAt = 0.0;

    /**
     * The server dimension of the `reverb` aggregates (#215), set when the
     * Reverb listeners are registered.
     */
    private string $reverbServer = '';

    /**
     * Dimensions of the `reverb` aggregates per Reverb app id, so the id is
     * checked or hashed once. As many entries as configured Reverb apps.
     *
     * @var array<string, array{server: string, appId: string}>
     */
    private array $reverbDimensions = [];

    /**
     * Reverb connections that received `pusher:connection_established`: an
     * error sent to any other connection rejected it (connection quota,
     * origin not allowed). Weak, so it forgets closed connections.
     *
     * @var WeakMap<object, true>
     */
    private WeakMap $establishedReverbConnections;

    /**
     * The Cashier webhook being handled (#217): `WebhookReceived` sets it,
     * `WebhookHandled` records and clears it, and one still set when the
     * request ends was not handled by Cashier (`settlePendingBillingWebhook()`).
     *
     * @var array{provider: string, eventType: string, startedAt: float}|null
     */
    private ?array $pendingBillingWebhook = null;

    /**
     * `auth.defaults.guard`, under which lockouts are counted (#219):
     * Laravel's `Lockout` event carries the request only, no guard.
     */
    private string $defaultAuthGuard = 'unknown';

    /**
     * `scout.driver`, the engine dimension of the `scout` aggregates (#221),
     * read when the Scout listeners are registered.
     */
    private string $scoutEngine = 'unknown';

    /**
     * Dimensions, record count and start time of each running Scout index
     * job that is sampled (#221).
     *
     * @var WeakMap<object, array{dimensions: array{model: string, engine: string, operation: string}, modelCount: int, startedAt: float}>
     */
    private WeakMap $scoutJobs;

    /**
     * @param  (Closure(): float)|null  $clock  current Unix time with microseconds; tests pass a fixed one
     */
    public function __construct(
        private readonly RuntimeConfigurationData $configuration,
        private readonly RuntimeBufferService $buffer,
        private readonly string $basePath,
        private readonly ?Closure $clock = null,
    ) {
        $this->jobStartedAt = new WeakMap;
        $this->recordedTasks = new WeakMap;
        $this->outerQueryShapes = new WeakMap;
        $this->establishedReverbConnections = new WeakMap;
        $this->scoutJobs = new WeakMap;
        $this->isCountingQueryShapes = $configuration->isTypeEnabled(RuntimeTypeEnum::DuplicateQuery);
    }

    /**
     * Register the collector, its listeners and the terminating flush. Only
     * the enabled types get a listener.
     */
    public static function register(Application $application, RuntimeConfigurationData $configuration): void
    {
        $collector = new self(
            $configuration,
            new RuntimeBufferService($application->storagePath('excubia'.DIRECTORY_SEPARATOR.'runtime'), $configuration->bufferMaxBytes),
            $application->basePath(),
        );
        $application->instance(self::class, $collector);

        /** @var Dispatcher $events */
        $events = $application->make('events');

        $isCountingQueryShapes = $configuration->isTypeEnabled(RuntimeTypeEnum::DuplicateQuery);

        if ($configuration->isTypeEnabled(RuntimeTypeEnum::Request) || $configuration->isTypeEnabled(RuntimeTypeEnum::RequestMemory) || $isCountingQueryShapes) {
            $events->listen(RequestHandled::class, fn (RequestHandled $event) => $collector->recordRequest($event));
        }

        if ($configuration->isTypeEnabled(RuntimeTypeEnum::SlowQuery) || $isCountingQueryShapes) {
            $events->listen(QueryExecuted::class, fn (QueryExecuted $event) => $collector->recordQuery($event));
        }

        if ($configuration->isTypeEnabled(RuntimeTypeEnum::Mail)) {
            $events->listen(MessageSending::class, fn (MessageSending $event) => $collector->rememberMail($event));
            $events->listen(MessageSent::class, fn (MessageSent $event) => $collector->recordMail($event));
            // Registered before the job and command listeners, which flush: a failed send lands in that flush.
            $events->listen(
                [JobProcessed::class, JobExceptionOccurred::class, JobFailed::class, CommandFinished::class],
                fn () => $collector->settlePendingMail(),
            );
        }

        if ($configuration->isTypeEnabled(RuntimeTypeEnum::Job) || $configuration->isTypeEnabled(RuntimeTypeEnum::JobMemory) || $configuration->isTypeEnabled(RuntimeTypeEnum::QueueWait) || $isCountingQueryShapes) {
            $events->listen(JobProcessing::class, fn (JobProcessing $event) => $collector->startJob($event));
            $events->listen(JobProcessed::class, fn (JobProcessed $event) => $collector->recordJob($event));
            $events->listen(JobFailed::class, fn (JobFailed $event) => $collector->recordJob($event));
        }

        if ($isCountingQueryShapes) {
            // A job that throws and is released for a retry fires neither JobProcessed nor JobFailed.
            $events->listen(JobExceptionOccurred::class, fn (JobExceptionOccurred $event) => $collector->recordJobQueryShapes($event));
        }

        if ($configuration->isTypeEnabled(RuntimeTypeEnum::QueueWait)) {
            Queue::createPayloadUsing(static fn (): array => [self::QUEUED_AT_PAYLOAD_KEY => microtime(true)]);
        }

        if ($configuration->isTypeEnabled(RuntimeTypeEnum::Cache)) {
            $events->listen(
                [CacheHit::class, CacheMissed::class, KeyWritten::class, KeyForgotten::class],
                fn (CacheHit|CacheMissed|KeyWritten|KeyForgotten $event) => $collector->recordCache($event),
            );
        }

        if ($configuration->isTypeEnabled(RuntimeTypeEnum::Notification)) {
            $events->listen(
                [NotificationSent::class, NotificationFailed::class],
                fn (NotificationSent|NotificationFailed $event) => $collector->recordNotification($event),
            );
        }

        if ($configuration->isTypeEnabled(RuntimeTypeEnum::LogLevel)) {
            $events->listen(MessageLogged::class, fn (MessageLogged $event) => $collector->recordLog($event));
            $collector->tapLogChannels($application);
        }

        if ($configuration->isTypeEnabled(RuntimeTypeEnum::Deprecation)) {
            $collector->listenToDeprecations();
        }

        if ($configuration->isTypeEnabled(RuntimeTypeEnum::ScheduledTask)) {
            $events->listen(ScheduledTaskFinished::class, fn (ScheduledTaskFinished $event) => $collector->recordScheduledTask($event));
            $events->listen(ScheduledTaskFailed::class, fn (ScheduledTaskFailed $event) => $collector->recordScheduledTask($event));
            $events->listen(ScheduledTaskSkipped::class, fn (ScheduledTaskSkipped $event) => $collector->recordScheduledTask($event));
        }

        if ($configuration->isTypeEnabled(RuntimeTypeEnum::OutgoingHttp)) {
            $events->listen(ResponseReceived::class, fn (ResponseReceived $event) => $collector->recordOutgoingHttp($event));
        }

        if ($configuration->isTypeEnabled(RuntimeTypeEnum::Command)) {
            $events->listen(CommandStarting::class, fn (CommandStarting $event) => $collector->startCommand($event));
            $events->listen(CommandFinished::class, fn (CommandFinished $event) => $collector->recordCommand($event));
        }

        if (class_exists(PromptingAgent::class)) {
            $collector->listenToAi($events);
        }

        if ($configuration->isTypeEnabled(RuntimeTypeEnum::Octane) && class_exists(WorkerStarting::class)) {
            $collector->listenToOctane($events);
        }

        if ($configuration->isTypeEnabled(RuntimeTypeEnum::Reverb) && class_exists(ReverbMessageSent::class)) {
            $collector->listenToReverb($application, $events);
        }

        if ($configuration->isTypeEnabled(RuntimeTypeEnum::BillingWebhook) && (class_exists(StripeWebhookReceived::class) || class_exists(PaddleWebhookReceived::class))) {
            $collector->listenToBillingWebhooks($events);
        }

        if ($configuration->isTypeEnabled(RuntimeTypeEnum::Auth)) {
            $collector->listenToAuth($application, $events);
        }

        if ($configuration->isTypeEnabled(RuntimeTypeEnum::Scout) && class_exists(ScoutBuilder::class)) {
            $collector->listenToScout($application, $events);
        }

        if ($configuration->isTypeEnabled(RuntimeTypeEnum::Pennant) && class_exists(FeatureRetrieved::class)) {
            $events->listen(
                [FeatureRetrieved::class, FeatureResolved::class, UnknownFeatureResolved::class],
                fn (FeatureRetrieved|FeatureResolved|UnknownFeatureResolved $event) => $collector->recordFeature($event),
            );
        }

        $application->terminating(function () use ($collector): void {
            $collector->settlePendingMail();
            $collector->settlePendingBillingWebhook();
            $collector->forgetQueryShapes();
            $collector->flush();
        });
    }

    public function recordRequest(RequestHandled $event): void
    {
        $this->guard(function () use ($event): void {
            $route = $event->request->route();
            $routeName = $route instanceof Route ? $route->getName() : null;
            $dimensions = [
                'route' => is_string($routeName) && $routeName !== '' ? $routeName : 'unnamed',
                'method' => strtoupper($event->request->getMethod()),
            ];

            if ($this->shouldRecord(RuntimeTypeEnum::Request)) {
                $startedAt = $event->request->server('REQUEST_TIME_FLOAT');
                $status = $event->response->getStatusCode();

                $this->add(RuntimeTypeEnum::Request, [
                    ...$dimensions,
                    'statusClass' => self::statusClass($status),
                ], is_numeric($startedAt) ? ($this->now() - (float) $startedAt) * 1000 : 0.0, $status >= 500);
            }

            if ($this->shouldRecord(RuntimeTypeEnum::RequestMemory)) {
                $this->addMemory(RuntimeTypeEnum::RequestMemory, $dimensions, max($this->peakMemoryBytes, memory_get_peak_usage()));
            }

            if ($this->isCountingQueryShapes) {
                $this->recordQueryShapes('request', $dimensions['route']);
            }
        });
    }

    public function recordQuery(QueryExecuted $event): void
    {
        $this->guard(function () use ($event): void {
            if ($this->isCountingQueryShapes) {
                $this->countQueryShape($event->sql, (string) $event->connectionName);
            }

            if ($event->time < $this->configuration->slowQueryThresholdMs || ! $this->shouldRecord(RuntimeTypeEnum::SlowQuery)) {
                return;
            }

            $this->add(RuntimeTypeEnum::SlowQuery, [
                'sql' => self::maskSql($event->sql),
                'connection' => (string) $event->connectionName,
            ], (float) $event->time, false);
        });
    }

    /**
     * Drop the query shapes counted so far: after each request and job, and
     * when the process terminates (queries outside a request or job).
     */
    public function forgetQueryShapes(): void
    {
        $this->queryShapes = [];
        $this->queryShapeCount = 0;
    }

    /**
     * Evaluate the query shapes of a job that ends (processed, failed, or
     * thrown and released), then put back the counts of the request or job
     * around it. A job whose start was not seen, or that was already
     * evaluated (`JobExceptionOccurred` then `JobFailed`), is skipped.
     */
    public function recordJobQueryShapes(JobProcessed|JobFailed|JobExceptionOccurred $event): void
    {
        $this->guard(function () use ($event): void {
            $outer = $this->outerQueryShapes[$event->job] ?? null;

            if ($outer === null) {
                return;
            }

            unset($this->outerQueryShapes[$event->job]);

            try {
                $this->recordQueryShapes('job', $this->className($event->job->resolveName()));
            } finally {
                [$this->queryShapes, $this->queryShapeCount] = $outer;
            }
        });
    }

    /**
     * One execution of a query shape: the SQL normalised (`normaliseSql()`)
     * per connection. Bindings are never read. A new shape past
     * MAX_QUERY_SHAPES is ignored.
     */
    private function countQueryShape(string $sql, string $connection): void
    {
        $normalised = $this->normalisedSql[$sql] ?? null;

        if ($normalised === null) {
            $normalised = self::normaliseSql($sql);

            if (strlen($sql) <= self::MAX_MEMOISED_SQL_LENGTH) {
                if (count($this->normalisedSql) >= self::MAX_QUERY_SHAPES) {
                    $this->normalisedSql = [];
                }

                $this->normalisedSql[$sql] = $normalised;
            }
        }

        if (isset($this->queryShapes[$connection][$normalised])) {
            $this->queryShapes[$connection][$normalised]++;

            return;
        }

        if ($this->queryShapeCount >= self::MAX_QUERY_SHAPES) {
            return;
        }

        $this->queryShapes[$connection][$normalised] = 1;
        $this->queryShapeCount++;
    }

    /**
     * Record one `duplicateQuery` occurrence per query shape of the request
     * or job that ran at least `runtime.duplicateQueries.threshold` times,
     * then forget the counts. The sample rate applies per request or job.
     *
     * @param  'request'|'job'  $origin
     */
    private function recordQueryShapes(string $origin, string $name): void
    {
        $queryShapes = $this->queryShapes;
        $this->forgetQueryShapes();

        if ($queryShapes === [] || ! $this->isSampled(RuntimeTypeEnum::DuplicateQuery)) {
            return;
        }

        foreach ($queryShapes as $connection => $executions) {
            foreach ($executions as $sql => $repeats) {
                if ($repeats < $this->configuration->duplicateQueryThreshold) {
                    continue;
                }

                $this->record(RuntimeTypeEnum::DuplicateQuery, [
                    'origin' => $origin,
                    'name' => $name,
                    'sql' => (string) $sql,
                    'connection' => (string) $connection,
                ], ['totalRepeats' => $repeats, 'maxRepeats' => $repeats]);
            }
        }
    }

    /**
     * Start timing the job, record its queue wait (`queueWait`) and, for
     * `jobMemory`, reset the PHP peak so the job's own peak can be read when
     * it ends (the earlier peak is kept for the request around it). For
     * `duplicateQuery`, set the counts of the request or job around it aside.
     */
    public function startJob(JobProcessing $event): void
    {
        $this->guard(function () use ($event): void {
            $this->jobStartedAt[$event->job] = $this->now();

            if ($this->isCountingQueryShapes) {
                $this->outerQueryShapes[$event->job] = [$this->queryShapes, $this->queryShapeCount];
                $this->forgetQueryShapes();
            }

            if ($this->shouldRecord(RuntimeTypeEnum::QueueWait)) {
                $this->recordQueueWait($event);
            }

            if ($this->configuration->isTypeEnabled(RuntimeTypeEnum::JobMemory)) {
                $this->peakMemoryBytes = max($this->peakMemoryBytes, memory_get_peak_usage());
                memory_reset_peak_usage();
            }
        });
    }

    public function recordJob(JobProcessed|JobFailed $event): void
    {
        $this->guard(function () use ($event): void {
            $startedAt = $this->jobStartedAt[$event->job] ?? null;
            unset($this->jobStartedAt[$event->job]);

            if ($this->isCountingQueryShapes) {
                $this->recordJobQueryShapes($event);
            }

            $job = $this->className($event->job->resolveName());
            $queue = (string) $event->job->getQueue();

            if ($this->shouldRecord(RuntimeTypeEnum::Job)) {
                $isFailed = $event instanceof JobFailed;

                $this->add(RuntimeTypeEnum::Job, [
                    'job' => $job,
                    'queue' => $queue,
                    'connection' => (string) $event->connectionName,
                    'outcome' => $isFailed ? 'failed' : 'processed',
                ], $startedAt === null ? 0.0 : ($this->now() - $startedAt) * 1000, $isFailed);
            }

            if ($this->configuration->isTypeEnabled(RuntimeTypeEnum::JobMemory)) {
                $peakBytes = memory_get_peak_usage();
                $this->peakMemoryBytes = max($this->peakMemoryBytes, $peakBytes);

                if ($this->isSampled(RuntimeTypeEnum::JobMemory)) {
                    $this->addMemory(RuntimeTypeEnum::JobMemory, ['job' => $job, 'queue' => $queue], $peakBytes);
                }
            }

            $this->flush();
        });
    }

    /**
     * Wait between the push and the first attempt, without the job's
     * delay. The push time is the `excubiaQueuedAt` key this collector adds
     * to every payload, else Horizon's `pushedAt`. Retries are skipped: a
     * released job keeps its first push time, so its wait would include the
     * earlier attempts and the backoff.
     *
     * ponytail: the push and the worker may run on different hosts; clock
     * skew between them shows up in the wait (negative waits become 0).
     */
    private function recordQueueWait(JobProcessing $event): void
    {
        if ($event->job->attempts() > 1) {
            return;
        }

        $payload = $event->job->payload();
        $queuedAt = $payload[self::QUEUED_AT_PAYLOAD_KEY] ?? $payload['pushedAt'] ?? null;

        if (! is_numeric($queuedAt)) {
            return;
        }

        $delaySeconds = $payload['delay'] ?? null;
        $delaySeconds = is_numeric($delaySeconds) ? (float) $delaySeconds : 0.0;

        $this->add(RuntimeTypeEnum::QueueWait, [
            'job' => $this->className($event->job->resolveName()),
            'queue' => (string) $event->job->getQueue(),
        ], ($this->now() - (float) $queuedAt - $delaySeconds) * 1000, false);
    }

    /**
     * One hit, miss, write or delete per cache store and masked key prefix.
     */
    public function recordCache(CacheHit|CacheMissed|KeyWritten|KeyForgotten $event): void
    {
        $this->guard(function () use ($event): void {
            if (! $this->isSampled(RuntimeTypeEnum::Cache)) {
                return;
            }

            $this->record(RuntimeTypeEnum::Cache, [
                'store' => is_string($event->storeName) && $event->storeName !== '' ? $event->storeName : 'default',
                'keyPrefix' => $this->keyPrefix((string) $event->key),
            ], [
                'hits' => $event instanceof CacheHit ? 1 : 0,
                'misses' => $event instanceof CacheMissed ? 1 : 0,
                'writes' => $event instanceof KeyWritten ? 1 : 0,
                'deletes' => $event instanceof KeyForgotten ? 1 : 0,
            ]);
        });
    }

    /**
     * The part of a cache key before its first `:` or `.`, through the
     * exception message masks. A prefix that still looks like an id, a
     * token or personal data (any digit, `@`, a space or other punctuation,
     * hex-only text, more than 64 characters, nothing but a mask
     * placeholder such as `[uuid]`) becomes `[masked]`: only letters, `_`,
     * `-`, `/` and the masks' own `[…]` placeholders inside a word pass.
     */
    public function keyPrefix(string $key): string
    {
        $prefix = preg_split('/[:.]/', $key, 2)[0] ?? '';
        $masked = ExceptionPayloadService::mask($prefix, $this->basePath);

        if (preg_match('#^[A-Za-z_/\[\]-]{1,64}$#', $masked) !== 1 || preg_match('/^(?:[0-9a-f]{8,}|\[\w+\])$/i', $masked) === 1) {
            return self::MASKED_KEY_PREFIX;
        }

        return $masked;
    }

    /**
     * Remember the message being sent. A message still pending from an
     * earlier `MessageSending` never reached `MessageSent`, so it is counted
     * as failed first (the app caught the transport exception and went on).
     */
    public function rememberMail(MessageSending $event): void
    {
        $this->guard(function () use ($event): void {
            $this->settlePendingMail();
            $this->pendingMail = $this->mailDimensions($event->data);
        });
    }

    public function recordMail(MessageSent $event): void
    {
        $this->guard(function () use ($event): void {
            $this->pendingMail = null;

            if ($this->isSampled(RuntimeTypeEnum::Mail)) {
                $this->record(RuntimeTypeEnum::Mail, $this->mailDimensions($event->data), []);
            }
        });
    }

    /**
     * Count the pending message as one failed mail (#175). Laravel has no
     * mail failure event, but the mailer fires `MessageSent` right after the
     * transport returns: a `MessageSending` with no `MessageSent` by the end
     * of its scope (the next send, the end of the queued job or attempt, the
     * end of the Artisan command, or the request or process terminating) is
     * a send that threw. This is the only source of mail failures, so it
     * works with exception capture off and counts each send once with it on.
     *
     * ponytail: a `MessageSending` listener registered after Excubia's that
     * cancels the send (returns false), or one that throws, is counted as a
     * failed mail; a job or command that runs inside a `MessageSending`
     * listener (a queued listener on the sync driver) counts the send as
     * failed and then as sent. Wrapping the mail transport is the upgrade
     * path if that ever matters.
     */
    public function settlePendingMail(): void
    {
        $this->guard(function (): void {
            $dimensions = $this->pendingMail;
            $this->pendingMail = null;

            if ($dimensions !== null && $this->isSampled(RuntimeTypeEnum::Mail)) {
                $this->record(RuntimeTypeEnum::Mail, $dimensions, ['errorCount' => 1]);
            }
        });
    }

    public function recordNotification(NotificationSent|NotificationFailed $event): void
    {
        $this->guard(function () use ($event): void {
            if (! $this->isSampled(RuntimeTypeEnum::Notification)) {
                return;
            }

            $channel = $event->channel;

            $this->record(RuntimeTypeEnum::Notification, [
                'notification' => is_object($event->notification) ? $this->className($event->notification::class) : 'unknown',
                'channel' => is_string($channel) && $channel !== '' ? $this->className($channel) : 'unknown',
            ], ['errorCount' => $event instanceof NotificationFailed ? 1 : 0]);
        });
    }

    /**
     * Learn the channel name of each log entry (#174) without touching the
     * message or the context: append `LogChannelTapService` to the `tap`
     * list of every configured channel, so Laravel calls it when it
     * resolves the channel, and watch the channels it already resolved.
     * The other taps of a channel are kept and run first.
     *
     * Why a tap and a processor, not `LogManager::extend()` or a handler:
     * `MessageLogged` carries no channel, but Laravel fires it right after
     * Monolog wrote the record, and Monolog runs the processors before the
     * handlers. A tap keeps every driver as configured and is lazy.
     *
     * ponytail: on-demand channels (`Log::build()`, `Log::stack([...])`)
     * have no configuration to tap, so their entries have no channel unless
     * they copy a tapped channel's processors (a stack of named channels
     * reports the first one). An entry whose handler throws fires no
     * `MessageLogged`; its channel is overwritten by the next entry.
     */
    public function tapLogChannels(Application $application): void
    {
        $this->guard(function () use ($application): void {
            /** @var Repository $config */
            $config = $application->make('config');
            $channels = $config->get('logging.channels');

            if (is_array($channels)) {
                foreach ($channels as $name => $channel) {
                    if (! is_string($name) || $name === '' || ! is_array($channel)) {
                        continue;
                    }

                    $taps = is_array($channel['tap'] ?? null) ? $channel['tap'] : [];
                    $tap = LogChannelTapService::class.':'.$name;

                    if (! in_array($tap, $taps, true)) {
                        $channels[$name]['tap'] = [...$taps, $tap];
                    }
                }

                $config->set('logging.channels', $channels);
            }

            if (! $application->resolved('log')) {
                return;
            }

            $manager = $application->make('log');

            if (! $manager instanceof LogManager) {
                return;
            }

            foreach ($manager->getChannels() as $name => $logger) {
                if (is_string($name) && $logger instanceof Logger) {
                    $this->watchLogChannel($logger, $name);
                }
            }
        });
    }

    /**
     * Push a Monolog processor onto the channel's logger that remembers the
     * channel name for the next `MessageLogged`. The processor returns the
     * record unchanged and never reads its message or context.
     */
    public function watchLogChannel(Logger $logger, string $channel): void
    {
        $this->guard(function () use ($logger, $channel): void {
            $monolog = $logger->getLogger();

            if ($channel === '' || ! $monolog instanceof MonologLogger) {
                return;
            }

            $monolog->pushProcessor(function (LogRecord $record) use ($channel): LogRecord {
                if ($this->logChannelRecord?->get() !== $record->datetime) {
                    $this->logChannel = $channel;
                    $this->logChannelRecord = WeakReference::create($record->datetime);
                }

                return $record;
            });
        });
    }

    /**
     * Count one log entry by level and, when a watched channel wrote it,
     * channel. The message and context are never read. A message logged
     * while this runs (a deprecation raised inside Excubia) is ignored, so
     * the listener cannot loop.
     */
    public function recordLog(MessageLogged $event): void
    {
        $channel = $this->logChannel;
        $this->logChannel = null;
        $this->logChannelRecord = null;

        if ($this->isRecordingLog) {
            return;
        }

        $this->isRecordingLog = true;

        try {
            $this->guard(function () use ($event, $channel): void {
                if (! $this->isSampled(RuntimeTypeEnum::LogLevel)) {
                    return;
                }

                $level = is_string($event->level) ? strtolower($event->level) : '';
                $dimensions = ['level' => in_array($level, self::LOG_LEVELS, true) ? $level : 'unknown'];

                if ($channel !== null) {
                    $dimensions['channel'] = $channel;
                }

                $this->record(RuntimeTypeEnum::LogLevel, $dimensions, []);
            });
        } finally {
            $this->isRecordingLog = false;
        }
    }

    /**
     * Chain a PHP error handler in front of the current one (Laravel's
     * `HandleExceptions`, which writes deprecations to the `deprecations`
     * log channel, when the provider boots). It records `E_DEPRECATED` and
     * `E_USER_DEPRECATED`, then always calls the previous handler with the
     * same arguments and returns its result, so Laravel's deprecation log,
     * its ErrorException for warnings, and any other chained handler (Sentry,
     * Flare…) behave as before. With no previous handler it returns false and
     * PHP's own handling continues.
     *
     * The handler is registered for every level, not with a level mask: PHP
     * sends the levels outside a mask to its built-in handler, not to the
     * previous one, so a mask would stop Laravel turning warnings into
     * exceptions. A request that raises no PHP error never calls it.
     *
     * Why the error handler and not Laravel's `deprecations` channel: the
     * `MessageLogged` event carries no channel, the log line holds the
     * absolute file and line in its text, and Laravel skips that channel in
     * unit tests and before the app is bootstrapped. The handler sees the
     * file directly and counts the same deprecations whatever the logging
     * configuration, and Laravel's channel keeps working because the handler
     * forwards to it.
     *
     * ponytail: a handler set later that does not forward to the previous
     * one hides deprecations from Excubia; it is not re-chained.
     */
    public function listenToDeprecations(): void
    {
        $previousHandler = null;
        $previousHandler = set_error_handler(function (int $level, string $message, string $file = '', int $line = 0) use (&$previousHandler): mixed {
            if ($level === E_DEPRECATED || $level === E_USER_DEPRECATED) {
                $this->recordDeprecation($message, $file);
            }

            if ($previousHandler === null) {
                return false;
            }

            return $previousHandler($level, $message, $file, $line);
        });
    }

    /**
     * Count one deprecation by source, masked message and PHP minor
     * version. The line and the stack are never read. A deprecation raised
     * while this runs is not counted (it is still forwarded), so the
     * collector cannot loop.
     */
    public function recordDeprecation(string $message, string $file): void
    {
        if ($this->isRecordingDeprecation) {
            return;
        }

        $this->isRecordingDeprecation = true;

        try {
            $this->guard(function () use ($message, $file): void {
                if (! $this->isSampled(RuntimeTypeEnum::Deprecation)) {
                    return;
                }

                $masked = mb_strimwidth(ExceptionPayloadService::mask($message, $this->basePath), 0, self::MAX_DEPRECATION_MESSAGE_LENGTH, '…');

                $this->record(RuntimeTypeEnum::Deprecation, [
                    'source' => $this->deprecationSource($file),
                    'message' => $masked === '' ? 'unknown' : $masked,
                    'phpVersion' => PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION,
                ], []);
            });
        } finally {
            $this->isRecordingDeprecation = false;
        }
    }

    /**
     * `vendor/<vendor>/<package>` for a file under the project's `vendor/`
     * (`vendor/<directory>` for Composer's own files), `app` for any other
     * file inside the project (compiled views included), `unknown` for a
     * file outside it or no file. Never the path itself.
     *
     * ponytail: assumes Composer's default `vendor/` directory; a custom
     * `vendor-dir` counts as `app`, and a path repository symlinked into
     * `vendor/` reports its real path.
     */
    public function deprecationSource(string $file): string
    {
        $file = str_replace('\\', '/', preg_replace("/\\(\\d+\\) : eval\\(\\)'d code$/", '', $file) ?? $file);
        $relative = null;

        foreach ($this->projectPrefixes() as $prefix) {
            if ($file !== '' && str_starts_with($file, $prefix)) {
                $relative = substr($file, strlen($prefix));

                break;
            }
        }

        if ($relative === null) {
            return 'unknown';
        }

        if (preg_match('#^vendor/([^/]+)(?:/([^/]+)/)?#', $relative, $matches) !== 1) {
            return 'app';
        }

        return isset($matches[2]) ? 'vendor/'.$matches[1].'/'.$matches[2] : 'vendor/'.$matches[1];
    }

    /**
     * The project root with a trailing `/`, as configured and resolved
     * (PHP reports included files by their real path, so a root reached
     * through a symlink, such as `/var` on macOS, still matches). Computed
     * on the first deprecation only.
     *
     * @return list<string>
     */
    private function projectPrefixes(): array
    {
        if ($this->projectPrefixes !== null) {
            return $this->projectPrefixes;
        }

        $roots = [$this->basePath];
        $realPath = realpath($this->basePath);

        if ($realPath !== false) {
            $roots[] = $realPath;
        }

        $this->projectPrefixes = array_values(array_unique(array_map(
            static fn (string $root): string => rtrim(str_replace('\\', '/', $root), '/').'/',
            $roots,
        )));

        return $this->projectPrefixes;
    }

    /**
     * A command that exits non-zero fires `ScheduledTaskFinished` and then
     * `ScheduledTaskFailed`; it is recorded once, as failed, with its runtime.
     */
    public function recordScheduledTask(ScheduledTaskFinished|ScheduledTaskFailed|ScheduledTaskSkipped $event): void
    {
        $this->guard(function () use ($event): void {
            if ($event instanceof ScheduledTaskFailed && isset($this->recordedTasks[$event->task])) {
                unset($this->recordedTasks[$event->task]);

                return;
            }

            $hasExitedWithError = (int) ($event->task->exitCode ?? 0) !== 0;

            if ($event instanceof ScheduledTaskFinished && $hasExitedWithError) {
                $this->recordedTasks[$event->task] = true;
            }

            if ($this->isSampled(RuntimeTypeEnum::ScheduledTask)) {
                $outcome = match (true) {
                    $event instanceof ScheduledTaskSkipped => 'skipped',
                    $event instanceof ScheduledTaskFailed, $hasExitedWithError => 'failed',
                    default => 'finished',
                };
                // ScheduledTaskFailed carries no runtime on Laravel 12.
                $runtimeSeconds = $event instanceof ScheduledTaskSkipped ? null : ($event->runtime ?? null);

                $task = ScheduledTaskIdentityService::name($event->task, $this->basePath);
                $expression = $event->task->expression ?? null;
                $dimensions = is_string($expression) ? ['task' => $task, 'taskId' => ScheduledTaskIdentityService::id($task, $expression)] : ['task' => $task];

                $this->add(RuntimeTypeEnum::ScheduledTask, [
                    ...$dimensions,
                    'outcome' => $outcome,
                ], is_numeric($runtimeSeconds) ? (float) $runtimeSeconds * 1000 : 0.0, $outcome === 'failed');
            }

            $this->flush();
        });
    }

    /**
     * Start timing an Artisan command (`command`, #152). Only the top-level
     * command of the process counts: one it calls through `Artisan::call()`
     * is part of its duration, and a command called from a scheduled closure
     * or job runs below `schedule:run`. A scheduler command marks the
     * environment its task processes inherit, so a scheduled Artisan command
     * (a child `php artisan …` process, top-level there) is skipped as well:
     * it is already a `scheduledTask`. Ignored commands and Excubia's own are
     * skipped, before the sample rate is applied.
     */
    public function startCommand(CommandStarting $event): void
    {
        $this->commandDepth++;

        if ($this->commandDepth !== 1) {
            return;
        }

        $this->commandStartedAt = null;

        $this->guard(function () use ($event): void {
            $isInsideScheduler = self::isInsideScheduler();

            if (! $isInsideScheduler && in_array($event->command, self::SCHEDULER_COMMANDS, true)) {
                self::markScheduler(true);
                $this->hasMarkedScheduler = true;
            }

            if ($isInsideScheduler || $this->isIgnoredCommand($event->command)) {
                return;
            }

            $this->commandStartedAt = $this->now();
        });
    }

    /**
     * Record the top-level command by name and exit code class, then flush:
     * a long-lived process (Octane, a worker calling `Artisan::call()`) does
     * not terminate after each command. In a short-lived `artisan` process
     * the terminating flush that follows finds nothing left to write. The
     * input (arguments and options) is never read.
     */
    public function recordCommand(CommandFinished $event): void
    {
        $this->commandDepth = max($this->commandDepth - 1, 0);

        if ($this->commandDepth !== 0) {
            return;
        }

        if ($this->hasMarkedScheduler) {
            $this->hasMarkedScheduler = false;
            self::markScheduler(false);
        }

        if ($this->commandStartedAt === null) {
            return;
        }

        $startedAt = $this->commandStartedAt;
        $this->commandStartedAt = null;

        $this->guard(function () use ($event, $startedAt): void {
            if ($this->isSampled(RuntimeTypeEnum::Command)) {
                $this->add(RuntimeTypeEnum::Command, [
                    'command' => $event->command !== '' ? $event->command : 'unknown',
                    'exitCodeClass' => match ($event->exitCode) {
                        0 => '0',
                        1 => '1',
                        default => 'other',
                    },
                ], ($this->now() - $startedAt) * 1000, $event->exitCode !== 0);
            }

            $this->flush();
        });
    }

    /**
     * Excubia's own commands (`excubia:*`) always, then the configured
     * `runtime.commands.ignore` names and `*` patterns.
     */
    private function isIgnoredCommand(string $command): bool
    {
        return Str::is(['excubia:*', ...$this->configuration->ignoredCommands], $command);
    }

    /**
     * ponytail: the marker reaches every task process the scheduler starts
     * through Symfony Process, which inherits `$_ENV`; a task run with
     * `->user()` goes through `sudo`, which resets the environment, so such a
     * scheduled Artisan command is also counted as `command`. Laravel's own
     * marker for scheduled processes would be the upgrade path, if one lands.
     */
    private static function markScheduler(bool $isInside): void
    {
        if ($isInside) {
            $_ENV[self::SCHEDULER_ENVIRONMENT_VARIABLE] = '1';
            putenv(self::SCHEDULER_ENVIRONMENT_VARIABLE.'=1');

            return;
        }

        unset($_ENV[self::SCHEDULER_ENVIRONMENT_VARIABLE]);
        putenv(self::SCHEDULER_ENVIRONMENT_VARIABLE);
    }

    private static function isInsideScheduler(): bool
    {
        return getenv(self::SCHEDULER_ENVIRONMENT_VARIABLE) === '1' || ($_ENV[self::SCHEDULER_ENVIRONMENT_VARIABLE] ?? null) === '1';
    }

    public function recordOutgoingHttp(ResponseReceived $event): void
    {
        $this->guard(function () use ($event): void {
            if (! $this->isSampled(RuntimeTypeEnum::OutgoingHttp)) {
                return;
            }

            $host = parse_url($event->request->url(), PHP_URL_HOST);
            $totalTime = $event->response->handlerStats()['total_time'] ?? null;
            $status = $event->response->status();

            $this->add(RuntimeTypeEnum::OutgoingHttp, [
                'host' => is_string($host) && $host !== '' ? strtolower($host) : 'unknown',
                'statusClass' => self::statusClass($status),
            ], is_numeric($totalTime) ? (float) $totalTime * 1000 : 0.0, $status >= 500);
        });
    }

    /**
     * Listen to the Laravel AI SDK (`laravel/ai`, #198), which the provider
     * only calls when its events exist: Excubia does not depend on it.
     * Laravel dispatches an event to the listeners of its own class only, so
     * `StreamingAgent`, `AgentStreamed` and `AgentFailedOver` are listened to
     * apart from the classes they extend.
     */
    public function listenToAi(Dispatcher $events): void
    {
        if ($this->configuration->isTypeEnabled(RuntimeTypeEnum::Ai)) {
            $events->listen(
                [PromptingAgent::class, StreamingAgent::class, GeneratingImage::class, GeneratingEmbeddings::class, GeneratingTranscription::class, Reranking::class, GeneratingAudio::class, Classifying::class],
                $this->startAiOperation(...),
            );
            $events->listen(
                [AgentPrompted::class, AgentStreamed::class, ImageGenerated::class, EmbeddingsGenerated::class, TranscriptionGenerated::class, Reranked::class, AudioGenerated::class, Classified::class],
                $this->recordAiOperation(...),
            );
            $events->listen(
                [AgentFailed::class, ImageFailed::class, EmbeddingsFailed::class, TranscriptionFailed::class, RerankingFailed::class, AudioFailed::class, ClassificationFailed::class],
                $this->recordAiFailure(...),
            );
            $events->listen([ProviderFailedOver::class, AgentFailedOver::class], $this->recordAiFailover(...));
        }

        if ($this->configuration->isTypeEnabled(RuntimeTypeEnum::AiTool)) {
            $events->listen([ToolInvoked::class, ToolFailed::class], $this->recordAiTool(...));
        }
    }

    /**
     * Remember when an AI operation starts, by its invocation id. A streamed
     * agent starts when its stream is first read (`StreamingAgent`).
     */
    public function startAiOperation(PromptingAgent|GeneratingImage|GeneratingEmbeddings|GeneratingTranscription|Reranking|GeneratingAudio|Classifying $event): void
    {
        $this->guard(function () use ($event): void {
            $this->lastAiFailure = null;
            unset($this->aiStartedAt[$event->invocationId]);

            if (count($this->aiStartedAt) >= self::MAX_PENDING_AI_INVOCATIONS) {
                unset($this->aiStartedAt[array_key_first($this->aiStartedAt)]);
            }

            $this->aiStartedAt[$event->invocationId] = $this->now();
        });
    }

    /**
     * One completed AI operation with its duration and usage. A streamed
     * agent is counted once, at `AgentStreamed`, with the usage of the whole
     * stream. Provider and model come from the response meta of an agent,
     * else from the prompt. Only the usage counts and the meta names are
     * read, never the text, reasoning, messages or tool calls, and for a
     * classification (#201) never the classified state, the questions,
     * the attachments or the answers.
     */
    public function recordAiOperation(AgentPrompted|ImageGenerated|EmbeddingsGenerated|TranscriptionGenerated|Reranked|AudioGenerated|Classified $event): void
    {
        $this->guard(function () use ($event): void {
            $durationMs = $this->aiDurationMs($event->invocationId);

            if (! $this->isSampled(RuntimeTypeEnum::Ai)) {
                return;
            }

            $dimensions = $event instanceof AgentPrompted
                ? $this->aiDimensions(
                    'agent',
                    $event->prompt->agent,
                    $event->response->meta->provider ?? $event->prompt->provider->name(),
                    $event->response->meta->model ?? $event->prompt->model,
                )
                : $this->aiDimensions(self::aiOperation($event), null, $event->provider->name(), $event->model);

            $this->add(RuntimeTypeEnum::Ai, $dimensions, $durationMs, false, [
                'failoverCount' => 0,
                ...self::aiUsage($event->response->usage),
            ]);
        });
    }

    /**
     * One failed AI attempt, without usage. `AgentFailed` comes once per
     * run, when no provider is left to fail over to; the other operations
     * fire their failure once per attempt, and a `ProviderFailedOver` right
     * after it counts the failover (`recordAiFailover()`). The exception is
     * never read.
     */
    public function recordAiFailure(AgentFailed|ImageFailed|EmbeddingsFailed|TranscriptionFailed|RerankingFailed|AudioFailed|ClassificationFailed $event): void
    {
        $this->guard(function () use ($event): void {
            $durationMs = $this->aiDurationMs($event->invocationId);
            $this->lastAiFailure = null;

            if (! $this->isSampled(RuntimeTypeEnum::Ai)) {
                return;
            }

            if ($event instanceof AgentFailed) {
                $this->add(RuntimeTypeEnum::Ai, $this->aiDimensions('agent', $event->prompt->agent, $event->prompt->provider->name(), $event->prompt->model), $durationMs, true, [
                    'failoverCount' => 0,
                    ...self::aiUsage(null),
                ]);

                return;
            }

            $dimensions = $this->aiDimensions(self::aiOperation($event), null, $event->provider->name(), $event->model);
            $key = $this->add(RuntimeTypeEnum::Ai, $dimensions, $durationMs, true, [
                'failoverCount' => 0,
                ...self::aiUsage(null),
            ]);
            $this->lastAiFailure = ['key' => $key, 'provider' => $dimensions['provider'], 'model' => $dimensions['model']];
        });
    }

    /**
     * A failed attempt that moves on to the next configured provider.
     * `AgentFailedOver` is the agent attempt itself (no `AgentFailed` comes
     * for it): one failed attempt with its failover. `ProviderFailedOver`
     * follows the failure event of the attempt, which was already counted:
     * it adds the failover to that attempt's aggregate.
     *
     * ponytail: the failover of an image, embeddings, transcription,
     * reranking, audio or classification attempt is dropped when the buffer was flushed
     * between the two events (MAX_PENDING_AGGREGATES reached by that very
     * failure); the error itself is kept. An invocation id on
     * `ProviderFailedOver` would be the upgrade path.
     */
    public function recordAiFailover(ProviderFailedOver $event): void
    {
        $this->guard(function () use ($event): void {
            if ($event instanceof AgentFailedOver) {
                $durationMs = $this->aiDurationMs($event->invocationId);

                if ($this->isSampled(RuntimeTypeEnum::Ai)) {
                    $this->add(RuntimeTypeEnum::Ai, $this->aiDimensions('agent', $event->agent, $event->provider->name(), $event->model), $durationMs, true, [
                        'failoverCount' => 1,
                        ...self::aiUsage(null),
                    ]);
                }

                return;
            }

            $failure = $this->lastAiFailure;
            $this->lastAiFailure = null;

            if ($failure === null
                || $failure['provider'] !== self::aiName($event->provider->name())
                || $failure['model'] !== self::aiName($event->model)
                || ! isset($this->aggregates[$failure['key']])) {
                return;
            }

            $failoverCount = $this->aggregates[$failure['key']]['failoverCount'] ?? 0;
            $this->aggregates[$failure['key']]['failoverCount'] = (is_int($failoverCount) ? $failoverCount : 0) + 1;
        });
    }

    /**
     * One tool call of an agent (`aiTool`), timed by the SDK itself. Only
     * the agent and tool class names are read, never the arguments or the
     * result.
     */
    public function recordAiTool(ToolInvoked|ToolFailed $event): void
    {
        $this->guard(function () use ($event): void {
            if (! $this->isSampled(RuntimeTypeEnum::AiTool)) {
                return;
            }

            $this->add(RuntimeTypeEnum::AiTool, [
                'agent' => $this->className($event->agent::class),
                'tool' => $this->className($event->tool::class),
            ], $event->time, $event instanceof ToolFailed);
        });
    }

    /**
     * Milliseconds since the operation started, and forget the start; 0
     * when the start was not seen.
     */
    private function aiDurationMs(string $invocationId): float
    {
        $startedAt = $this->aiStartedAt[$invocationId] ?? null;
        unset($this->aiStartedAt[$invocationId]);

        return $startedAt === null ? 0.0 : ($this->now() - $startedAt) * 1000;
    }

    /**
     * @return array<string, string>
     */
    private function aiDimensions(string $operation, ?object $agent, string $provider, string $model): array
    {
        return [
            'operation' => $operation,
            ...$agent === null ? [] : ['agent' => $this->className($agent::class)],
            'provider' => self::aiName($provider),
            'model' => self::aiName($model),
        ];
    }

    /**
     * A provider or model name, `unknown` when empty.
     */
    private static function aiName(string $name): string
    {
        return $name !== '' ? $name : 'unknown';
    }

    /**
     * @return 'image'|'embeddings'|'transcription'|'reranking'|'audio'|'classification'
     */
    private static function aiOperation(ImageGenerated|EmbeddingsGenerated|TranscriptionGenerated|Reranked|AudioGenerated|Classified|ImageFailed|EmbeddingsFailed|TranscriptionFailed|RerankingFailed|AudioFailed|ClassificationFailed $event): string
    {
        return match (true) {
            $event instanceof ImageGenerated, $event instanceof ImageFailed => 'image',
            $event instanceof EmbeddingsGenerated, $event instanceof EmbeddingsFailed => 'embeddings',
            $event instanceof TranscriptionGenerated, $event instanceof TranscriptionFailed => 'transcription',
            $event instanceof Reranked, $event instanceof RerankingFailed => 'reranking',
            $event instanceof Classified, $event instanceof ClassificationFailed => 'classification',
            default => 'audio',
        };
    }

    /**
     * Every usage field of `RuntimeBufferService::NULLABLE_SUM_FIELDS`
     * that the usage object has (`TextUsage`, `ImageUsage`,
     * `TranscriptionUsage`, `RerankingUsage`, `Usage`; a classification
     * reports `TextUsage`), else null: a count
     * the provider does not report stays null.
     *
     * @return array<string, int|float|null>
     */
    private static function aiUsage(?object $usage): array
    {
        $metrics = [];

        foreach (RuntimeBufferService::NULLABLE_SUM_FIELDS as $field) {
            $value = $usage !== null && property_exists($usage, $field) ? $usage->{$field} : null;

            $metrics[$field] = match (true) {
                is_int($value) => max($value, 0),
                is_float($value) => round(max($value, 0.0), 3),
                default => null,
            };
        }

        return $metrics;
    }

    /**
     * Listen to Laravel Octane (`laravel/octane`, #213), which the provider
     * only calls when its events exist: Excubia does not depend on it. The
     * provider boots once per worker, before Octane fires `WorkerStarting`,
     * and Octane fires every later event on the same dispatcher (through the
     * request, task or tick sandbox).
     */
    public function listenToOctane(Dispatcher $events): void
    {
        $events->listen(WorkerStarting::class, $this->startOctaneWorker(...));
        $events->listen(RequestReceived::class, $this->receiveOctaneRequest(...));
        $events->listen(RequestTerminated::class, $this->recordOctaneRequest(...));
        $events->listen([TaskReceived::class, TickReceived::class], $this->recordOctaneOperation(...));
        $events->listen(WorkerStopping::class, $this->stopOctaneWorker(...));

        if (class_exists(WorkerErrorOccurred::class)) {
            $events->listen(WorkerErrorOccurred::class, $this->recordOctaneError(...));
        }
    }

    /**
     * One worker start, flushed right away so a crash loop shows up.
     */
    public function startOctaneWorker(WorkerStarting $event): void
    {
        $this->guard(function () use ($event): void {
            $this->octaneDimensions = null;
            $this->octaneRequestCount = 0;
            $this->isOctaneRequestOpen = false;
            $this->octaneFirstRequestBytes = null;

            if ($this->isSampled(RuntimeTypeEnum::Octane)) {
                $this->recordOctane($event->app, ['workerStarts' => 1]);
            }

            $this->flushOctane();
        });
    }

    /**
     * Count the request for the worker's total. Nothing of the request is
     * read.
     */
    public function receiveOctaneRequest(RequestReceived $event): void
    {
        $this->octaneRequestCount++;
        $this->isOctaneRequestOpen = true;
    }

    /**
     * One handled request with the memory in use after it (after the
     * kernel terminated) and its growth since the worker's first request.
     * Nothing of the request or the response is read.
     *
     * ponytail: the request's aggregate is written by the next request's
     * terminating flush, a task, tick or worker stop, or at most a minute
     * later; an idle worker holds it until then.
     */
    public function recordOctaneRequest(RequestTerminated $event): void
    {
        $this->isOctaneRequestOpen = false;

        $this->guard(function () use ($event): void {
            $memoryBytes = memory_get_usage();
            $this->octaneFirstRequestBytes ??= $memoryBytes;

            if ($this->isSampled(RuntimeTypeEnum::Octane)) {
                $this->recordOctane($event->sandbox, [
                    'requests' => 1,
                    'maxMb' => self::megabytes($memoryBytes),
                    'maxGrowthMb' => self::megabytes($memoryBytes - $this->octaneFirstRequestBytes),
                ]);
            }

            $this->flushOctaneEveryMinute();
        });
    }

    /**
     * One task (`TaskReceived`) or tick (`TickReceived`, Swoole's every
     * second timer). The task data is never read.
     */
    public function recordOctaneOperation(TaskReceived|TickReceived $event): void
    {
        $this->guard(function () use ($event): void {
            if ($this->isSampled(RuntimeTypeEnum::Octane)) {
                $this->recordOctane($event->sandbox, $event instanceof TaskReceived ? ['tasks' => 1] : ['ticks' => 1]);
            }

            $this->flushOctaneEveryMinute();
        });
    }

    /**
     * One worker error: an exception that escaped a request, task or tick.
     * A request it ended is counted here. The exception is never read.
     */
    public function recordOctaneError(WorkerErrorOccurred $event): void
    {
        $hasOpenRequest = $this->isOctaneRequestOpen;
        $this->isOctaneRequestOpen = false;

        $this->guard(function () use ($event, $hasOpenRequest): void {
            if ($this->isSampled(RuntimeTypeEnum::Octane)) {
                $this->recordOctane($event->sandbox, ['errorCount' => 1, 'requests' => $hasOpenRequest ? 1 : 0]);
            }

            $this->flushOctane();
        });
    }

    /**
     * One worker stop with the requests it handled (`max_requests` reached,
     * a reload or a shutdown), then flush.
     */
    public function stopOctaneWorker(WorkerStopping $event): void
    {
        $this->guard(function () use ($event): void {
            if ($this->isSampled(RuntimeTypeEnum::Octane)) {
                $this->recordOctane($event->app, [
                    'workerStops' => 1,
                    'totalRequestsAtStop' => $this->octaneRequestCount,
                    'maxRequestsAtStop' => $this->octaneRequestCount,
                ]);
            }

            $this->flushOctane();
        });
    }

    /**
     * One `octane` event with every metric field: the ones not given are 0.
     *
     * @param  array<string, int|float>  $metrics
     */
    private function recordOctane(Application $application, array $metrics): void
    {
        $this->octaneDimensions ??= $this->octaneDimensions($application);

        $this->record(RuntimeTypeEnum::Octane, $this->octaneDimensions, [
            'workerStarts' => 0,
            'workerStops' => 0,
            'requests' => 0,
            'tasks' => 0,
            'ticks' => 0,
            'totalRequestsAtStop' => 0,
            'maxRequestsAtStop' => 0,
            'maxMb' => 0.0,
            'maxGrowthMb' => 0.0,
            ...$metrics,
        ]);
    }

    /**
     * The server identity (as for `server`: the configured name, else a
     * hash of the hostname and the app key, never the hostname), the Octane
     * server (`swoole`, `roadrunner`, `frankenphp`, else `unknown`) from the
     * client Octane bound, else `octane.server`, and the worker type: `task`
     * in a Swoole task worker (which also runs the ticks), else `http`.
     *
     * @return array{server: string, octaneServer: string, workerType: string}
     */
    private function octaneDimensions(Application $application): array
    {
        /** @var Repository $config */
        $config = $application->make('config');
        $applicationKey = $config->get('app.key');
        $hostname = gethostname();
        $client = $application->bound(self::OCTANE_CLIENT) ? $application->make(self::OCTANE_CLIENT) : null;
        $configuredServer = $config->get('octane.server');
        $octaneServer = null;

        foreach (self::OCTANE_CLIENT_SERVERS as $clientClass => $server) {
            if ($client instanceof $clientClass) {
                $octaneServer = $server;

                break;
            }
        }

        $swooleServer = $application->bound(self::SWOOLE_SERVER) ? $application->make(self::SWOOLE_SERVER) : null;

        return [
            'server' => ServerMetricsService::serverIdentity($this->configuration->serverName, is_string($hostname) ? $hostname : '', is_string($applicationKey) ? $applicationKey : ''),
            'octaneServer' => $octaneServer ?? (in_array($configuredServer, self::OCTANE_SERVERS, true) ? $configuredServer : 'unknown'),
            'workerType' => is_object($swooleServer) && ($swooleServer->taskworker ?? false) === true ? 'task' : 'http',
        ];
    }

    private function flushOctane(): void
    {
        $this->octaneFlushedAt = $this->now();
        $this->flush();
    }

    private function flushOctaneEveryMinute(): void
    {
        if ($this->now() - $this->octaneFlushedAt >= 60) {
            $this->flushOctane();
        }
    }

    private static function megabytes(int $bytes): float
    {
        return round(max($bytes, 0) / self::BYTES_PER_MEGABYTE, 3);
    }

    /**
     * Listen to Laravel Reverb (`laravel/reverb`, #215), which the provider
     * only calls when its events exist: Excubia does not depend on it. The
     * Reverb server is `php artisan reverb:start`, a long-lived process that
     * boots the app (and this provider) once and fires every event on this
     * dispatcher; it never terminates between messages, so a timer on its
     * event loop samples the open connections and flushes once a minute.
     */
    public function listenToReverb(Application $application, Dispatcher $events): void
    {
        /** @var Repository $config */
        $config = $application->make('config');
        $applicationKey = $config->get('app.key');
        $hostname = gethostname();
        $this->reverbServer = ServerMetricsService::serverIdentity($this->configuration->serverName, is_string($hostname) ? $hostname : '', is_string($applicationKey) ? $applicationKey : '');

        $events->listen(ReverbMessageReceived::class, $this->recordReverbMessageReceived(...));
        $events->listen(ReverbMessageSent::class, $this->recordReverbMessageSent(...));
        $events->listen(ConnectionPruned::class, $this->recordReverbConnectionPruned(...));
        $events->listen(
            [ChannelCreated::class, ChannelRemoved::class],
            fn (ChannelCreated|ChannelRemoved $event) => $this->recordReverbChannel($event, $application),
        );
        $events->listen(CommandStarting::class, fn (CommandStarting $event) => $this->startReverbServer($event, $application));
    }

    /**
     * When `reverb:start` starts, add a timer of 60 seconds to the event
     * loop it is about to run (Reverb runs on ReactPHP's global loop,
     * `Loop::get()`, a dependency of laravel/reverb): each tick samples the
     * open connections and flushes the buffer, so `excubia:push-runtime`,
     * which reads the same buffer file, sends them within two minutes.
     *
     * ponytail: what was recorded after the last tick is lost when the
     * server is killed or stops on a signal (Symfony exits without
     * Laravel's terminating callbacks); a `reverb:restart` ends the command
     * normally and flushes.
     */
    public function startReverbServer(CommandStarting $event, Application $application): void
    {
        if ($event->command !== 'reverb:start' || ! class_exists(Loop::class)) {
            return;
        }

        $this->guard(function () use ($application): void {
            Loop::get()->addPeriodicTimer(60, fn () => $this->sampleReverb($application));
        });
    }

    /**
     * One sample per configured Reverb app of the connections subscribed to
     * at least one channel on this server (what Reverb's own `connections`
     * API counts), as `maxConnections`, then flush. Not sampled, as a
     * gauge, except that sample rate 0 records nothing. Reverb's channel manager is a singleton scoped with `for()`,
     * which Reverb itself calls before every use, so scoping it here between
     * two loop callbacks changes nothing.
     */
    public function sampleReverb(Application $application): void
    {
        $this->guard(function () use ($application): void {
            if ($this->configuration->sampleRate(RuntimeTypeEnum::Reverb) <= 0.0) {
                return;
            }

            $channels = $application->make(self::REVERB_CHANNEL_MANAGER);

            foreach ($application->make(self::REVERB_APPLICATION_PROVIDER)->all() as $reverbApplication) {
                $this->recordReverb($reverbApplication->id(), ['maxConnections' => count($channels->for($reverbApplication)->connections())]);
            }
        });

        $this->flush();
    }

    /**
     * One message a client sent (`MessageReceived`, after Reverb handled it:
     * Pusher pings and subscriptions included). The message is never read.
     */
    public function recordReverbMessageReceived(ReverbMessageReceived $event): void
    {
        $this->guard(function () use ($event): void {
            if ($this->isSampled(RuntimeTypeEnum::Reverb)) {
                $this->recordReverb($event->connection->app()->id(), ['messagesReceived' => 1]);
            }
        });
    }

    /**
     * One message Reverb sent to a client (`MessageSent`): broadcasts and
     * protocol frames. A `pusher:connection_established` frame is also an
     * opened connection, and a `pusher:error` frame a rejected connection
     * (sent before the connection was established: quota or origin) or an
     * error (rate limit, unauthorized subscription, invalid message); the
     * error a pruned connection gets is counted by `ConnectionPruned`. Only
     * the fixed prefix of the frame is compared, never its data.
     */
    public function recordReverbMessageSent(ReverbMessageSent $event): void
    {
        $this->guard(function () use ($event): void {
            $connection = $event->connection;
            $message = $event->message;
            $metrics = ['messagesSent' => 1];

            if (str_starts_with($message, self::REVERB_CONNECTION_ESTABLISHED)) {
                $this->establishedReverbConnections[$connection] = true;
                $metrics['connectionsOpened'] = 1;
            } elseif (str_starts_with($message, self::REVERB_ERROR) && ! str_starts_with($message, self::REVERB_PRUNED_ERROR)) {
                $metrics[isset($this->establishedReverbConnections[$connection]) ? 'errorCount' : 'connectionsRejected'] = 1;
            }

            if ($this->isSampled(RuntimeTypeEnum::Reverb)) {
                $this->recordReverb($connection->app()->id(), $metrics);
            }
        });
    }

    /**
     * One connection Reverb pruned: it missed the pong in time.
     */
    public function recordReverbConnectionPruned(ConnectionPruned $event): void
    {
        $this->guard(function () use ($event): void {
            if ($this->isSampled(RuntimeTypeEnum::Reverb)) {
                $this->recordReverb($event->connection->connection()->app()->id(), ['connectionsPruned' => 1]);
            }
        });
    }

    /**
     * One channel created or removed (its last subscriber left), counted by
     * prefix only: `private…` (private and encrypted, cache included),
     * `presence…`, else public, as Reverb's ChannelBroker types them. The
     * events carry no app, so it is the one Reverb's channel manager is
     * scoped to while it creates or removes the channel. The channel name
     * is never kept.
     */
    public function recordReverbChannel(ChannelCreated|ChannelRemoved $event, Application $application): void
    {
        $this->guard(function () use ($event, $application): void {
            if (! $this->isSampled(RuntimeTypeEnum::Reverb)) {
                return;
            }

            $name = $event->channel->name();
            $channelType = match (true) {
                str_starts_with($name, 'private') => 'private',
                str_starts_with($name, 'presence') => 'presence',
                default => 'public',
            };
            $reverbApplication = $application->make(self::REVERB_CHANNEL_MANAGER)->app();

            $this->recordReverb(
                is_object($reverbApplication) ? $reverbApplication->id() : 'unknown',
                [$channelType.'Channels'.($event instanceof ChannelCreated ? 'Created' : 'Removed') => 1],
            );
        });
    }

    /**
     * One `reverb` event with every metric field: the ones not given are 0.
     *
     * @param  array<string, int>  $metrics
     */
    private function recordReverb(string $applicationId, array $metrics): void
    {
        $this->reverbDimensions[$applicationId] ??= [
            'server' => $this->reverbServer,
            'appId' => preg_match(self::REVERB_APPLICATION_ID_PATTERN, $applicationId) === 1 ? $applicationId : substr(hash('sha256', $applicationId), 0, 12),
        ];

        $this->record(RuntimeTypeEnum::Reverb, $this->reverbDimensions[$applicationId], [
            'connectionsOpened' => 0,
            'connectionsPruned' => 0,
            'connectionsRejected' => 0,
            'maxConnections' => 0,
            'messagesReceived' => 0,
            'messagesSent' => 0,
            'publicChannelsCreated' => 0,
            'privateChannelsCreated' => 0,
            'presenceChannelsCreated' => 0,
            'publicChannelsRemoved' => 0,
            'privateChannelsRemoved' => 0,
            'presenceChannelsRemoved' => 0,
            ...$metrics,
        ]);
    }

    /**
     * Listen to the webhooks of Laravel Cashier (`laravel/cashier`, Stripe)
     * and Laravel Cashier Paddle (`laravel/cashier-paddle`) (#217), which the
     * provider only calls when one of them is installed: Excubia depends on
     * neither. Both webhook controllers fire `WebhookReceived` before their
     * handler and `WebhookHandled` after it returns, and nothing when it
     * throws, so `RequestHandled` settles a webhook still pending with the
     * response status.
     */
    public function listenToBillingWebhooks(Dispatcher $events): void
    {
        $events->listen(
            [StripeWebhookReceived::class, PaddleWebhookReceived::class],
            fn (StripeWebhookReceived|PaddleWebhookReceived $event) => $this->receiveBillingWebhook($event),
        );
        $events->listen(
            [StripeWebhookHandled::class, PaddleWebhookHandled::class],
            fn (StripeWebhookHandled|PaddleWebhookHandled $event) => $this->recordBillingWebhookHandled($event),
        );
        $events->listen(RequestHandled::class, fn (RequestHandled $event) => $this->settlePendingBillingWebhook($event->response->getStatusCode()));
    }

    /**
     * Remember the webhook Cashier received: its provider, its event type
     * (Stripe's `type`, Paddle's `event_type`, or `alert_name` for Paddle
     * Classic) when it matches BILLING_EVENT_TYPE_PATTERN, else `unknown`,
     * and when it arrived. Nothing else of the payload is read: never
     * amounts, currencies, customer or subscription ids, emails or cards.
     */
    public function receiveBillingWebhook(StripeWebhookReceived|PaddleWebhookReceived $event): void
    {
        $this->guard(function () use ($event): void {
            $this->settlePendingBillingWebhook();

            if (! $this->isSampled(RuntimeTypeEnum::BillingWebhook)) {
                return;
            }

            $isStripe = $event instanceof StripeWebhookReceived;
            $payload = $event->payload;
            $eventType = is_array($payload) ? ($isStripe ? ($payload['type'] ?? null) : ($payload['event_type'] ?? $payload['alert_name'] ?? null)) : null;

            $this->pendingBillingWebhook = [
                'provider' => $isStripe ? 'stripe' : 'paddle',
                'eventType' => is_string($eventType) && preg_match(self::BILLING_EVENT_TYPE_PATTERN, $eventType) === 1 ? $eventType : 'unknown',
                'startedAt' => $this->now(),
            ];
        });
    }

    /**
     * Cashier's handler for the pending webhook returned.
     */
    public function recordBillingWebhookHandled(StripeWebhookHandled|PaddleWebhookHandled $event): void
    {
        $this->guard(function () use ($event): void {
            $pending = $this->pendingBillingWebhook;

            if ($pending === null || $pending['provider'] !== ($event instanceof StripeWebhookHandled ? 'stripe' : 'paddle')) {
                return;
            }

            $this->pendingBillingWebhook = null;
            $this->recordBillingWebhook($pending, isHandled: true, isFailed: false);
        });
    }

    /**
     * Record the webhook still pending when its request ends (or the next
     * one arrives, or the process terminates), as not handled. With a 5xx
     * response it failed: Cashier's handler or an app listener threw, and
     * the provider retries it. Otherwise Cashier has no handler for its
     * event type (the controller answers 200 without `WebhookHandled`), and
     * it counts as received only.
     */
    public function settlePendingBillingWebhook(?int $statusCode = null): void
    {
        $this->guard(function () use ($statusCode): void {
            $pending = $this->pendingBillingWebhook;
            $this->pendingBillingWebhook = null;

            if ($pending !== null) {
                $this->recordBillingWebhook($pending, isHandled: false, isFailed: $statusCode !== null && $statusCode >= 500);
            }
        });
    }

    /**
     * One `billingWebhook` aggregate, timed from `WebhookReceived` to
     * `WebhookHandled` or to the end of the request, with every counter.
     *
     * @param  array{provider: string, eventType: string, startedAt: float}  $pending
     */
    private function recordBillingWebhook(array $pending, bool $isHandled, bool $isFailed): void
    {
        $signal = self::BILLING_SIGNALS[$pending['provider']][$pending['eventType']] ?? null;

        $this->add(RuntimeTypeEnum::BillingWebhook, ['provider' => $pending['provider'], 'eventType' => $pending['eventType']], ($this->now() - $pending['startedAt']) * 1000, $isFailed, [
            'handledCount' => $isHandled ? 1 : 0,
            'paymentFailedCount' => $signal === 'paymentFailedCount' ? 1 : 0,
            'paymentActionRequiredCount' => $signal === 'paymentActionRequiredCount' ? 1 : 0,
            'subscriptionCanceledCount' => $signal === 'subscriptionCanceledCount' ? 1 : 0,
        ]);
    }

    /**
     * Listen to Laravel's authentication events (#219): `Login` (also a
     * login through the remember cookie), `Failed` and `Lockout`, and, when
     * Laravel Sanctum is installed (it is not a dependency), its
     * `TokenAuthenticated`, fired for each request a personal access token
     * authenticates. Passport fires no event when a token authenticates a
     * request, so its token requests are not counted here.
     */
    public function listenToAuth(Application $application, Dispatcher $events): void
    {
        $defaultGuard = $application->make('config')->get('auth.defaults.guard');
        $this->defaultAuthGuard = self::configName($defaultGuard);

        $events->listen([Login::class, Failed::class, Lockout::class], fn (Login|Failed|Lockout $event) => $this->recordAuthentication($event));

        if (class_exists(TokenAuthenticated::class)) {
            $events->listen(TokenAuthenticated::class, fn (TokenAuthenticated $event) => $this->recordAuthentication($event));
        }
    }

    /**
     * One `auth` aggregate per guard: `errorCount` 1 for a failed login and
     * one of `loginCount`, `lockoutCount` or `tokenAuthenticationCount`
     * otherwise. Only the guard name is read: never the user, the
     * credentials, the token or the request (no username, email, id or IP).
     * A lockout has no guard and counts under `auth.defaults.guard`, a
     * Sanctum token authentication under `sanctum`.
     */
    public function recordAuthentication(Login|Failed|Lockout|TokenAuthenticated $event): void
    {
        $this->guard(function () use ($event): void {
            if (! $this->isSampled(RuntimeTypeEnum::Auth)) {
                return;
            }

            $guard = match (true) {
                $event instanceof Lockout => $this->defaultAuthGuard,
                $event instanceof TokenAuthenticated => self::SANCTUM_GUARD,
                default => self::configName($event->guard),
            };

            $this->record(RuntimeTypeEnum::Auth, ['guard' => $guard], [
                'errorCount' => $event instanceof Failed ? 1 : 0,
                'loginCount' => $event instanceof Login ? 1 : 0,
                'lockoutCount' => $event instanceof Lockout ? 1 : 0,
                'tokenAuthenticationCount' => $event instanceof TokenAuthenticated ? 1 : 0,
            ]);
        });
    }

    /**
     * A guard or engine name from the config when it matches
     * CONFIG_NAME_PATTERN, else `unknown`.
     */
    private static function configName(mixed $name): string
    {
        return is_string($name) && preg_match(self::CONFIG_NAME_PATTERN, $name) === 1 ? $name : 'unknown';
    }

    /**
     * Listen to Laravel Scout (#221), only when `laravel/scout` is installed
     * (it is not a dependency). Scout fires no event when it updates an
     * index or runs a search, so this records what Laravel itself shows:
     * the queued index jobs (`MakeSearchable`, `RemoveFromSearch` and their
     * subclasses, run when `scout.queue` is on), timed from `JobProcessing`
     * to `JobProcessed` or `JobFailed`, and each search builder the
     * container makes (`Model::search()`), counted without a duration.
     *
     * ponytail: with `scout.queue` off the observer updates the engine
     * inline and nothing is recorded, and search latency is not measured;
     * both would need Scout's builder or engines wrapped. The engine is
     * `scout.driver`; a model that overrides `searchableUsing()` is still
     * counted under it.
     */
    public function listenToScout(Application $application, Dispatcher $events): void
    {
        $this->scoutEngine = self::configName($application->make('config')->get('scout.driver'));

        $events->listen(JobProcessing::class, fn (JobProcessing $event) => $this->startScoutJob($event));
        $events->listen([JobProcessed::class, JobFailed::class], fn (JobProcessed|JobFailed $event) => $this->recordScoutJob($event));
        $application->afterResolving(ScoutBuilder::class, fn (object $builder) => $this->recordScoutSearch($builder));
    }

    /**
     * Remember a sampled Scout index job when it starts: its operation
     * (`index` or `remove`), model class and record count from the payload.
     */
    public function startScoutJob(JobProcessing $event): void
    {
        $this->guard(function () use ($event): void {
            $operation = self::scoutOperation($event->job->resolveName());

            if ($operation === null || ! $this->isSampled(RuntimeTypeEnum::Scout)) {
                return;
            }

            $command = $event->job->payload()['data']['command'] ?? null;
            $hasModels = is_string($command) && preg_match(self::SCOUT_MODELS_PATTERN, $command, $matches) === 1;

            $this->scoutJobs[$event->job] = [
                'dimensions' => [
                    'model' => $hasModels && $matches[1] !== '' ? $this->className($matches[1]) : 'unknown',
                    'engine' => $this->scoutEngine,
                    'operation' => $operation,
                ],
                'modelCount' => $hasModels ? (int) $matches[2] : 0,
                'startedAt' => $this->now(),
            ];
        });
    }

    /**
     * Record a Scout index job that ends, processed or failed, and flush.
     * A job released for a retry fires neither event and is not counted
     * until its last attempt.
     */
    public function recordScoutJob(JobProcessed|JobFailed $event): void
    {
        $this->guard(function () use ($event): void {
            $pending = $this->scoutJobs[$event->job] ?? null;

            if ($pending === null) {
                return;
            }

            unset($this->scoutJobs[$event->job]);
            $this->add(RuntimeTypeEnum::Scout, $pending['dimensions'], ($this->now() - $pending['startedAt']) * 1000, $event instanceof JobFailed, [
                'modelCount' => $pending['modelCount'],
            ]);
            $this->flush();
        });
    }

    /**
     * Count one search: a Scout builder made by `Model::search()`. Only the
     * model class is read, never the query, the callback or the results.
     */
    public function recordScoutSearch(object $builder): void
    {
        $this->guard(function () use ($builder): void {
            if (! $this->isSampled(RuntimeTypeEnum::Scout)) {
                return;
            }

            $model = $builder->model ?? null;

            $this->record(RuntimeTypeEnum::Scout, [
                'model' => is_object($model) ? $this->className($model::class) : 'unknown',
                'engine' => $this->scoutEngine,
                'operation' => 'search',
            ], ['modelCount' => 0]);
        });
    }

    /**
     * `index` for Scout's `MakeSearchable` job (or a subclass, or the class
     * set with `Scout::makeSearchableUsing()`), `remove` for
     * `RemoveFromSearch`, else null. A name that is not a loaded class (a
     * queued closure) is never autoloaded.
     */
    private static function scoutOperation(string $job): ?string
    {
        if (! class_exists($job, false)) {
            return null;
        }

        return match (true) {
            is_a($job, MakeSearchable::class, true) || $job === Scout::$makeSearchableJob => 'index',
            is_a($job, RemoveFromSearch::class, true) || $job === Scout::$removeFromSearchJob => 'remove',
            default => null,
        };
    }

    /**
     * One `pennant` aggregate per feature name (#225), only when Laravel
     * Pennant (`laravel/pennant`, not a dependency) is installed: a check
     * (`FeatureRetrieved`, fired for every `Feature::active()`, `value()`
     * and the like, from Pennant's in-memory cache or its store) counts as
     * active unless its value is `false`, Pennant's own rule; a first
     * resolution of a feature for a scope (`FeatureResolved`) and a check of
     * a feature that is not defined (`UnknownFeatureResolved`) have their
     * own counters. Only the feature name is kept, hashed unless it matches
     * PENNANT_FEATURE_PATTERN; the value is only compared with `false`, and
     * the scope (a user, a team) is never read.
     */
    public function recordFeature(FeatureRetrieved|FeatureResolved|UnknownFeatureResolved $event): void
    {
        $this->guard(function () use ($event): void {
            if (! $this->isSampled(RuntimeTypeEnum::Pennant)) {
                return;
            }

            $isCheck = $event instanceof FeatureRetrieved;

            $this->record(RuntimeTypeEnum::Pennant, $this->pennantDimensions((string) $event->feature), [
                'activeCount' => $isCheck && $event->value !== false ? 1 : 0,
                'inactiveCount' => $isCheck && $event->value === false ? 1 : 0,
                'resolvedCount' => $event instanceof FeatureResolved ? 1 : 0,
                'unknownCount' => $event instanceof UnknownFeatureResolved ? 1 : 0,
            ]);
        });
    }

    /**
     * @return array{feature: string}
     */
    private function pennantDimensions(string $feature): array
    {
        if (! isset($this->pennantDimensions[$feature])) {
            if (count($this->pennantDimensions) >= self::MAX_PENDING_AGGREGATES) {
                $this->pennantDimensions = [];
            }

            $this->pennantDimensions[$feature] = [
                'feature' => preg_match(self::PENNANT_FEATURE_PATTERN, $feature) === 1 ? $feature : substr(hash('sha256', $feature), 0, 12),
            ];
        }

        return $this->pennantDimensions[$feature];
    }

    /**
     * Write the pending aggregates to the buffer and forget them.
     */
    public function flush(): void
    {
        $this->guard(function (): void {
            if ($this->aggregates === []) {
                return;
            }

            $aggregates = array_values($this->aggregates);
            $this->aggregates = [];
            $this->buffer->append($aggregates);
        });
    }

    /**
     * SQL text without bindings. Literals written inline are masked too,
     * because they can hold the same kind of data as bindings and each
     * distinct value would be a new aggregate: dollar-quoted bodies
     * (`$$…$$`, `$tag$…$tag$`), hex literals (`X'…'`, `0x…`), single-quoted
     * strings and standalone numbers all become `?`. Placeholders (`?`,
     * `$1`) and identifiers with digits (`t1`, `col_2`) stay. Double-quoted
     * and backtick-quoted text is left alone, numbers included: it is an
     * identifier on PostgreSQL, SQLite and MySQL.
     */
    public static function maskSql(string $sql): string
    {
        return mb_strimwidth(self::maskLiterals($sql), 0, self::MAX_SQL_LENGTH, '…');
    }

    /**
     * The shape of a query for `duplicateQuery` (#148): the literals of
     * `maskSql()` masked, runs of whitespace as one space, and a list of
     * placeholders after `IN` (`IN (?, ?, ?)`, also `$1, $2`) as `IN (…)`,
     * so the same query with lists of any length is one shape. At most
     * MAX_SQL_LENGTH characters. The slow query `sql` stays `maskSql()`.
     */
    public static function normaliseSql(string $sql): string
    {
        $collapsed = preg_replace('/\s+/', ' ', trim(self::maskLiterals($sql))) ?? '?';
        $normalised = preg_replace('/\b(in) ?\( ?(?:\?|\$\d+)(?: ?, ?(?:\?|\$\d+))* ?\)/i', '$1 (…)', $collapsed) ?? $collapsed;

        return mb_strimwidth($normalised, 0, self::MAX_SQL_LENGTH, '…');
    }

    /**
     * The literals of `maskSql()` replaced by `?`, without the cut.
     */
    private static function maskLiterals(string $sql): string
    {
        $masked = preg_replace(
            [
                '/\$([a-z_]\w*|)\$.*?\$\1\$/is',
                "/\\bx'[0-9a-f]*'|\\b0x[0-9a-f]+\\b/i",
                "/'(?:[^'\\\\]|\\\\.|'')*'/s",
            ],
            '?',
            $sql,
        );
        $masked = $masked === null ? null : preg_replace_callback(
            '/("(?:[^"]|"")*"|`(?:[^`]|``)*`)|(?<![\w$.])\d+(?:\.\d+)?(?![\w.])/',
            static fn (array $matches): string => isset($matches[1]) && $matches[1] !== '' ? $matches[1] : '?',
            $masked,
        );

        return $masked ?? '?';
    }

    /**
     * One timed event: its duration in `totalMs` and `maxMs`, and one count
     * in its latency histogram bucket (#147), so the histogram always adds
     * up to `count`.
     *
     * @param  array<string, string>  $dimensions
     * @param  array<string, int|float|null>  $metrics  further metric fields of the type
     * @return string the merge key of the pending aggregate
     */
    private function add(RuntimeTypeEnum $type, array $dimensions, float $durationMs, bool $isError, array $metrics = []): string
    {
        $durationMs = round(max($durationMs, 0.0), 3);
        $histogram = array_fill(0, count(RuntimeTypeEnum::HISTOGRAM_BOUNDS_MS) + 1, 0);
        $histogram[RuntimeTypeEnum::histogramBucket($durationMs)]++;

        return $this->record($type, $dimensions, ['errorCount' => $isError ? 1 : 0, 'totalMs' => $durationMs, 'maxMs' => $durationMs, 'histogram' => $histogram, ...$metrics]);
    }

    /**
     * @param  array<string, string>  $dimensions
     */
    private function addMemory(RuntimeTypeEnum $type, array $dimensions, int $peakBytes): void
    {
        $peakMegabytes = self::megabytes($peakBytes);

        $this->record($type, $dimensions, ['totalMb' => $peakMegabytes, 'maxMb' => $peakMegabytes]);
    }

    /**
     * Add one event to the pending aggregate with the same dimensions.
     * Every aggregate has `count` 1 and `errorCount` 0 unless `$metrics`
     * says otherwise.
     *
     * @param  array<string, string>  $dimensions
     * @param  array<string, int|float|list<int>|null>  $metrics
     * @return string the merge key of the pending aggregate
     */
    private function record(RuntimeTypeEnum $type, array $dimensions, array $metrics): string
    {
        $aggregate = [
            'type' => $type->value,
            'bucket' => gmdate('Y-m-d\TH:i:00\Z', (int) $this->now()),
            'sampleRate' => $this->configuration->sampleRate($type),
            ...$dimensions,
            'count' => 1,
            'errorCount' => 0,
            ...$metrics,
        ];
        $key = RuntimeBufferService::mergeKey($aggregate);

        $this->aggregates[$key] = isset($this->aggregates[$key])
            ? RuntimeBufferService::combine($this->aggregates[$key], $aggregate)
            : $aggregate;

        if (count($this->aggregates) >= self::MAX_PENDING_AGGREGATES) {
            $this->flush();
        }

        return $key;
    }

    private function shouldRecord(RuntimeTypeEnum $type): bool
    {
        return $this->configuration->isTypeEnabled($type) && $this->isSampled($type);
    }

    private function isSampled(RuntimeTypeEnum $type): bool
    {
        $sampleRate = $this->configuration->sampleRate($type);

        if ($sampleRate >= 1.0) {
            return true;
        }

        return $sampleRate > 0.0 && mt_rand() / mt_getrandmax() < $sampleRate;
    }

    private function relative(string $value): string
    {
        return ScheduledTaskIdentityService::relative($value, $this->basePath);
    }

    /**
     * A class name without the absolute path of an anonymous class.
     */
    private function className(string $class): string
    {
        return ExceptionPayloadService::className($this->relative($class));
    }

    /**
     * Mailable class (`__laravel_mailable`), else the notification class
     * of a mail notification (`__laravel_notification`), else `unknown`,
     * and the mailer name. Nothing else of the message data is read.
     *
     * @param  array<mixed>  $data
     * @return array{mailable: string, mailer: string}
     */
    private function mailDimensions(array $data): array
    {
        $mailable = $data['__laravel_mailable'] ?? $data['__laravel_notification'] ?? null;
        $mailer = $data['mailer'] ?? null;

        return [
            'mailable' => is_string($mailable) && $mailable !== '' ? $this->className($mailable) : 'unknown',
            'mailer' => is_string($mailer) && $mailer !== '' ? $mailer : 'unknown',
        ];
    }

    private static function statusClass(int $status): string
    {
        return intdiv($status, 100).'xx';
    }

    private function now(): float
    {
        return $this->clock !== null ? ($this->clock)() : microtime(true);
    }

    /**
     * Run collection code; any exception stays inside Excubia.
     *
     * ponytail: errors are dropped, not logged, so a broken buffer directory
     * cannot flood the host app's log; a debug log is the upgrade path.
     */
    private function guard(Closure $callback): void
    {
        try {
            $callback();
        } catch (Throwable) {
            // Monitoring never reaches the host application.
        }
    }
}
