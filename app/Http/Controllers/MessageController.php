<?php

namespace App\Http\Controllers;

use App\Models\Conversation;
use App\Services\AiService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

class MessageController extends Controller
{
    public function __construct(
        protected AiService $ai
    ) {}

    public function index(Request $request, Conversation $conversation)
    {
        abort_unless(
            $conversation->user_id === $request->user()->id,
            404
        );

        $messages = $conversation->messages()
            ->orderBy('created_at')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $messages,
        ]);
    }

    public function store(Request $request, Conversation $conversation)
    {
        abort_unless(
            $conversation->user_id === $request->user()->id,
            404
        );

        $validated = $request->validate([
            'content' => ['required', 'string', 'max:8000'],
        ]);

        $userMessage = $conversation->messages()->create([
            'role' => 'user',
            'content' => $validated['content'],
        ]);

        $history = $conversation->messages()
            ->orderBy('created_at')
            ->get(['role', 'content'])
            ->map(fn ($message) => [
                'role' => $message->role,
                'content' => $message->content,
            ])
            ->values()
            ->all();

        try {
            $aiReply = $this->ai->chat(
                $history,
                $conversation->model
            );

            $assistantMessage = $conversation->messages()->create([
                'role' => 'assistant',
                'content' => $aiReply['content'],
                'model' => $aiReply['model'],
                'prompt_tokens' => $aiReply['prompt_tokens'],
                'completion_tokens' => $aiReply['completion_tokens'],
                'total_tokens' => $aiReply['total_tokens'],
            ]);
        } catch (Throwable $e) {
            Log::error('Failed to generate online AI reply', [
                'conversation_id' => $conversation->id,
                'error' => $e->getMessage(),
            ]);

            $assistantMessage = $conversation->messages()->create([
                'role' => 'assistant',
                'content' => 'Online AI error: '.$e->getMessage(),
                'model' => null,
            ]);
        }

        if ($conversation->title === 'New Chat') {
            $conversation->update([
                'title' => mb_substr($validated['content'], 0, 60),
            ]);
        } else {
            $conversation->touch();
        }

        return response()->json([
            'success' => true,
            'data' => [
                'user_message' => $userMessage,
                'assistant_message' => $assistantMessage,
            ],
        ], 201);
    }
}
