<?php

declare(strict_types=1);

namespace App\Domain\Intelligence\Assistant;

/**
 * One turn of the model: given the system prompt, the tools and the
 * conversation so far, what it said and which tools it wants run.
 * Abstracted so the assistant can be exercised without the network.
 */
interface AssistantBackend
{
    /**
     * @param  list<array<string, mixed>>  $tools  tool definitions (name, description, inputSchema)
     * @param  list<array<string, mixed>>  $messages  the conversation in API shape
     * @return array{stop_reason: string|null, blocks: list<array{type: string, text?: string, id?: string, name?: string, input?: array<string, mixed>}>, content: mixed, usage: array{input: int, output: int}}
     *                                                                                                                                                                                                            `content` is what goes back as the assistant turn
     */
    public function turn(string $system, array $tools, array $messages): array;
}
