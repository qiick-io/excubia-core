<?php

declare(strict_types=1);

/*
 * Stand-ins for Laravel Octane's worker events (#213), under their real
 * class names and with the constructor properties of laravel/octane 2.x
 * (src/Events): Octane is not a dependency of this package, so the tests
 * fake the events instead of installing it. Loaded with require_once by the
 * Octane tests only; once loaded, `class_exists()` sees Octane as installed
 * for the rest of the run, which changes nothing while `runtime.octane` is
 * off and the app has no `octane` configuration.
 */

namespace Laravel\Octane\Events;

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class WorkerStarting
{
    public function __construct(public Application $app) {}
}

class RequestReceived
{
    public function __construct(public Application $app, public Application $sandbox, public Request $request) {}
}

class RequestTerminated
{
    public function __construct(public Application $app, public Application $sandbox, public Request $request, public Response $response) {}
}

class TaskReceived
{
    public function __construct(public Application $app, public Application $sandbox, public mixed $data) {}
}

class TickReceived
{
    public function __construct(public Application $app, public Application $sandbox) {}
}

class WorkerErrorOccurred
{
    public function __construct(public Throwable $exception, public Application $sandbox) {}
}

class WorkerStopping
{
    public function __construct(public Application $app) {}
}
