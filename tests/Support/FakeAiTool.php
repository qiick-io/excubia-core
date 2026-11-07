<?php

declare(strict_types=1);

namespace Qiick\Excubia\Tests\Support;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use RuntimeException;

/**
 * A Laravel AI SDK tool for the `aiTool` runtime tests (#198). It echoes
 * its `query` argument in its result, or throws with it in the message when
 * `fail` is set, so a test can check neither ever reaches the buffer.
 */
final class FakeAiTool implements Tool
{
    public function description(): string
    {
        return 'Looks up an order.';
    }

    public function handle(Request $request): string
    {
        $query = $request->string('query')->toString();

        if ($request->boolean('fail')) {
            throw new RuntimeException('tool failed for '.$query);
        }

        return 'result for '.$query;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string(),
            'fail' => $schema->boolean(),
        ];
    }
}
