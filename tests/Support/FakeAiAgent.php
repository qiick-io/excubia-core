<?php

declare(strict_types=1);

namespace Qiick\Excubia\Tests\Support;

use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Promptable;

/**
 * A Laravel AI SDK agent for the `ai` and `aiTool` runtime tests (#198),
 * answered by the SDK's own fake gateway (`FakeAiAgent::fake()`).
 */
final class FakeAiAgent implements Agent, HasTools
{
    use Promptable;

    public function __construct(private readonly string $instructions = 'You help with orders.') {}

    public function instructions(): string
    {
        return $this->instructions;
    }

    public function tools(): iterable
    {
        return [new FakeAiTool];
    }
}
