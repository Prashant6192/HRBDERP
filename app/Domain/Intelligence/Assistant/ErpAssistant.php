<?php

declare(strict_types=1);

namespace App\Domain\Intelligence\Assistant;

use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * The ERP assistant.
 *
 * A person asks in plain words — "do we have enough Surfactant A for next
 * week's batches?", "why was MO-2609-00012 delayed?", "what should purchase
 * order today?" — and the assistant answers from the ERP itself. It reads
 * through the same services the screens use, under the asking person's
 * own permissions, and never changes anything. Every answer says which
 * figures it looked at.
 */
class ErpAssistant
{
    public function __construct(
        private readonly AssistantBackend $backend,
        private readonly AssistantTools $tools,
    ) {}

    /**
     * @param  list<array{role: string, content: string}>  $history  earlier turns, oldest first
     * @return array{answer: string, tools: list<array{name: string, input: array<string, mixed>}>, usage: array{input: int, output: int}}
     */
    public function ask(User $user, string $question, array $history = []): array
    {
        $messages = [];

        foreach (array_slice($history, -(int) config('erp.ai.assistant.history_turns', 12)) as $turn) {
            if (in_array($turn['role'] ?? '', ['user', 'assistant'], true) && trim((string) ($turn['content'] ?? '')) !== '') {
                $messages[] = ['role' => $turn['role'], 'content' => mb_substr(trim((string) $turn['content']), 0, 4000)];
            }
        }

        $messages[] = ['role' => 'user', 'content' => trim($question)];

        $definitions = $this->tools->definitions();
        $used = [];
        $usage = ['input' => 0, 'output' => 0];
        $answer = '';

        for ($round = 0; $round <= (int) config('erp.ai.assistant.max_tool_rounds', 6); $round++) {
            $turn = $this->backend->turn($this->system($user), $definitions, $messages);
            $usage['input'] += $turn['usage']['input'];
            $usage['output'] += $turn['usage']['output'];

            $text = implode("\n", array_map(fn (array $b) => $b['text'] ?? '', array_filter($turn['blocks'], fn (array $b) => $b['type'] === 'text')));
            $calls = array_values(array_filter($turn['blocks'], fn (array $b) => $b['type'] === 'tool_use'));

            if ($turn['stop_reason'] !== 'tool_use' || $calls === []) {
                $answer = trim($text);
                break;
            }

            $messages[] = ['role' => 'assistant', 'content' => $turn['content']];
            $results = [];

            foreach ($calls as $call) {
                $used[] = ['name' => $call['name'], 'input' => $call['input'] ?? []];
                $results[] = [
                    'type' => 'tool_result',
                    'toolUseID' => $call['id'],
                    'content' => $this->tools->run($user, $call['name'], $call['input'] ?? []),
                ];
            }

            $messages[] = ['role' => 'user', 'content' => $results];
            $answer = trim($text);
        }

        if ($answer === '') {
            $answer = 'I looked but could not put an answer together. Try asking about one material, one order or one batch at a time.';
        }

        return ['answer' => $answer, 'tools' => $used, 'usage' => $usage];
    }

    public function system(User $user): string
    {
        $company = (string) config('erp.company.name', 'the company');
        $now = CarbonImmutable::now(config('erp.company.timezone', 'Asia/Kolkata'));
        $roles = $user->getRoleNames()->implode(', ');

        return <<<TEXT
You are the ERP assistant for {$company}, a cosmetics and personal-care manufacturer. You answer questions about the factory from the ERP's own data, which you reach only through the tools. You never guess a figure: if a tool did not return it, say so. You never change anything; if the person asks you to do something (order, approve, post, edit), tell them which screen does it.

The person asking is {$user->name} ({$roles}). Tools enforce their permissions; if a tool says they do not hold a permission, tell them plainly which one.

Now: {$now->format('l j F Y, H:i')} ({$now->tzName}). Currency: Indian rupees. Quantities carry their unit.

How to answer:
- Lead with the answer in one or two sentences, then the figures that support it, then the recommended action when there is one ("Order 75 KG today from Vendor ABC").
- Be concrete: batch numbers, order numbers, quantities with units, dates. Round sensibly.
- When something is at risk, say what will go wrong and when.
- Keep it short. Use short paragraphs or a compact list; no headings, no tables wider than three columns.
- If the question is vague, use find_items or command_centre first rather than asking back, then answer what you can.
- Mention which tools you consulted only if it helps trust ("Read from the stock outlook as of today").
TEXT;
    }
}
