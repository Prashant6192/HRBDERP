<?php

declare(strict_types=1);

namespace App\Http\Controllers\Intelligence;

use App\Domain\Intelligence\Assistant\AssistantBackend;
use App\Domain\Intelligence\Assistant\AssistantException;
use App\Domain\Intelligence\Assistant\ClaudeBackend;
use App\Domain\Intelligence\Assistant\ErpAssistant;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Ask the ERP.
 */
class AssistantController extends Controller
{
    public function __construct(
        private readonly ErpAssistant $assistant,
        private readonly AssistantBackend $backend,
    ) {}

    public function index(Request $request): Response
    {
        Gate::authorize('assistant.view');

        return Inertia::render('assistant/index', [
            'available' => $this->available(),
            'suggestions' => [
                'Do we have enough stock for the batches planned this week?',
                'What should purchase order today, and from whom?',
                'What is running on the floor right now, and is anything delayed?',
                'Which batches are at risk of expiring before we use them?',
                'How did QC and dispatch perform in the last 30 days?',
                'Where did batch RM-0001 go?',
            ],
        ]);
    }

    public function ask(Request $request): JsonResponse
    {
        Gate::authorize('assistant.view');

        $data = $request->validate([
            'question' => ['required', 'string', 'max:2000'],
            'history' => ['nullable', 'array', 'max:24'],
            'history.*.role' => ['required', 'in:user,assistant'],
            'history.*.content' => ['required', 'string', 'max:8000'],
        ]);

        if (! $this->available()) {
            return response()->json(['error' => 'The assistant is switched off: set ANTHROPIC_API_KEY on the server to turn it on.'], 503);
        }

        try {
            $reply = $this->assistant->ask($request->user(), $data['question'], $data['history'] ?? []);
        } catch (AssistantException $e) {
            Log::warning('ERP assistant failed', ['user' => $request->user()->id, 'error' => $e->getMessage()]);

            return response()->json(['error' => $e->getMessage()], 502);
        }

        return response()->json($reply);
    }

    private function available(): bool
    {
        return (bool) config('erp.ai.assistant.enabled', true)
            && (! $this->backend instanceof ClaudeBackend || $this->backend->available());
    }
}
