<?php

declare(strict_types=1);

namespace App\Support\Ai;

use Anthropic\Client;
use Anthropic\Core\Exceptions\APIStatusException;
use Throwable;

/**
 * One way of asking Claude to read a document — a PDF or a photo — into
 * JSON fixed to a schema. Each kind of document (a supplier's bill, a
 * transfer challan) brings its own instructions and schema; this class
 * owns the call, the errors and the key.
 */
final class ClaudeDocuments
{
    public function available(): bool
    {
        return trim((string) config('erp.ai.api_key')) !== '';
    }

    public function model(): string
    {
        return (string) config('erp.ai.model', 'claude-opus-5');
    }

    /**
     * @param  array<string, mixed>  $schema  JSON schema the reply must satisfy
     * @return array<string, mixed> the decoded reply
     *
     * @throws DocumentReadException
     */
    public function extract(string $system, array $schema, string $contents, string $mime, string $prompt): array
    {
        if (! $this->available()) {
            throw new DocumentReadException(DocumentReadException::NOT_CONFIGURED, 'ANTHROPIC_API_KEY is missing.');
        }

        $client = new Client(apiKey: (string) config('erp.ai.api_key'));

        $document = $mime === 'application/pdf'
            ? ['type' => 'document', 'source' => ['type' => 'base64', 'mediaType' => 'application/pdf', 'data' => base64_encode($contents)]]
            : ['type' => 'image', 'source' => ['type' => 'base64', 'mediaType' => $mime, 'data' => base64_encode($contents)]];

        try {
            $message = $client->messages->create(
                model: $this->model(),
                maxTokens: 16000,
                system: [['type' => 'text', 'text' => $system, 'cacheControl' => ['type' => 'ephemeral']]],
                messages: [[
                    'role' => 'user',
                    'content' => [$document, ['type' => 'text', 'text' => $prompt]],
                ]],
                outputConfig: ['format' => ['type' => 'json_schema', 'schema' => $schema]],
            );
        } catch (APIStatusException $e) {
            throw new DocumentReadException(DocumentReadException::API, $e->type?->value ?? 'api error', $e);
        } catch (Throwable $e) {
            throw new DocumentReadException(DocumentReadException::API, $e->getMessage(), $e);
        }

        if ($message->stopReason === 'refusal') {
            throw new DocumentReadException(DocumentReadException::REFUSED, 'The document was declined by the reader.');
        }

        $json = null;

        foreach ($message->content as $block) {
            if ($block->type === 'text') {
                $json = $block->text;
                break;
            }
        }

        $data = is_string($json) ? json_decode($json, true) : null;

        if (! is_array($data)) {
            throw new DocumentReadException(DocumentReadException::EMPTY, 'The reader returned nothing usable for this document.');
        }

        return $data;
    }
}
