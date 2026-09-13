<?php

declare(strict_types=1);

namespace App\Domain\Intelligence\Assistant;

use Anthropic\Client;
use Anthropic\Core\Exceptions\APIStatusException;
use Anthropic\Messages\TextBlock;
use Anthropic\Messages\ToolUseBlock;
use Throwable;

/**
 * The assistant on Claude, through the official SDK.
 */
final class ClaudeBackend implements AssistantBackend
{
    public function available(): bool
    {
        return trim((string) config('erp.ai.api_key')) !== '';
    }

    public function turn(string $system, array $tools, array $messages): array
    {
        if (! $this->available()) {
            throw new AssistantException('ANTHROPIC_API_KEY is not set; the assistant is switched off.');
        }

        $client = new Client(apiKey: (string) config('erp.ai.api_key'));

        try {
            $response = $client->messages->create(
                model: (string) config('erp.ai.model', 'claude-opus-5'),
                maxTokens: (int) config('erp.ai.assistant.max_tokens', 4000),
                system: [['type' => 'text', 'text' => $system, 'cacheControl' => ['type' => 'ephemeral']]],
                tools: $tools,
                messages: $messages,
            );
        } catch (APIStatusException $e) {
            throw new AssistantException('The assistant could not reach Claude ('.($e->type?->value ?? 'api error').').', $e);
        } catch (Throwable $e) {
            throw new AssistantException('The assistant could not reach Claude: '.$e->getMessage(), $e);
        }

        $blocks = [];

        foreach ($response->content as $block) {
            if ($block instanceof ToolUseBlock) {
                $blocks[] = ['type' => 'tool_use', 'id' => $block->id, 'name' => $block->name, 'input' => $block->input];
            } elseif ($block instanceof TextBlock) {
                $blocks[] = ['type' => 'text', 'text' => $block->text];
            }
        }

        return [
            'stop_reason' => $response->stopReason,
            'blocks' => $blocks,
            'content' => $response->content,
            'usage' => ['input' => (int) ($response->usage->inputTokens ?? 0), 'output' => (int) ($response->usage->outputTokens ?? 0)],
        ];
    }
}
