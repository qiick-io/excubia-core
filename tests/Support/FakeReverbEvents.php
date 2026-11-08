<?php

declare(strict_types=1);

/*
 * Stand-ins for Laravel Reverb's events (#215) and the few Reverb classes
 * they carry, under their real class names and with the constructor
 * properties and methods of laravel/reverb 1.x (src/Events, src/Application,
 * src/Contracts/Connection, src/Protocols/Pusher/Channels), plus ReactPHP's
 * global event loop, which `reverb:start` runs on. Reverb is not a dependency
 * of this package, so the tests fake them instead of installing it. Loaded
 * with require_once by the Reverb tests only; once loaded, `class_exists()`
 * sees Reverb as installed for the rest of the run, which changes nothing
 * while `runtime.reverb` is off and the app has no `reverb` configuration.
 */

namespace Laravel\Reverb {
    class Application
    {
        /**
         * @param  array<int, string>  $allowedOrigins
         */
        public function __construct(protected string $id, protected string $key = 'app-key', protected string $secret = 'app-secret', protected array $allowedOrigins = ['*']) {}

        public function id(): string
        {
            return $this->id;
        }

        public function key(): string
        {
            return $this->key;
        }

        public function secret(): string
        {
            return $this->secret;
        }
    }
}

namespace Laravel\Reverb\Contracts {
    use Laravel\Reverb\Application;

    /**
     * Reverb's connection contract is an abstract class; the fake keeps
     * only the app, the origin and the socket id.
     */
    class Connection
    {
        public function __construct(protected Application $application, protected ?string $origin = null, protected string $socketId = '1234.5678') {}

        public function app(): Application
        {
            return $this->application;
        }

        public function origin(): ?string
        {
            return $this->origin;
        }

        public function id(): string
        {
            return $this->socketId;
        }
    }
}

namespace Laravel\Reverb\Protocols\Pusher\Channels {
    use Laravel\Reverb\Contracts\Connection;

    class Channel
    {
        public function __construct(protected string $name) {}

        public function name(): string
        {
            return $this->name;
        }
    }

    class ChannelConnection
    {
        /**
         * @param  array<string, mixed>  $data
         */
        public function __construct(protected Connection $connection, protected array $data = []) {}

        public function connection(): Connection
        {
            return $this->connection;
        }
    }
}

namespace Laravel\Reverb\Events {
    use Laravel\Reverb\Contracts\Connection;
    use Laravel\Reverb\Protocols\Pusher\Channels\Channel;
    use Laravel\Reverb\Protocols\Pusher\Channels\ChannelConnection;

    class MessageReceived
    {
        public function __construct(public Connection $connection, public string $message) {}
    }

    class MessageSent
    {
        public function __construct(public Connection $connection, public string $message) {}
    }

    class ConnectionPruned
    {
        public function __construct(public ChannelConnection $connection) {}
    }

    class ChannelCreated
    {
        public function __construct(public Channel $channel) {}
    }

    class ChannelRemoved
    {
        public function __construct(public Channel $channel) {}
    }
}

namespace React\EventLoop {
    use Closure;

    /**
     * ReactPHP's global loop accessor. The fake loop only remembers its
     * periodic timers, so a test can run one tick.
     */
    final class Loop
    {
        private static ?FakePeriodicLoop $loop = null;

        public static function get(): FakePeriodicLoop
        {
            return self::$loop ??= new FakePeriodicLoop;
        }
    }

    final class FakePeriodicLoop
    {
        /** @var list<array{interval: float, callback: Closure}> */
        public array $timers = [];

        public function addPeriodicTimer(float $interval, Closure $callback): void
        {
            $this->timers[] = ['interval' => $interval, 'callback' => $callback];
        }
    }
}
