<?php

namespace App\Http\Controllers;

use App\Models\Conversation;
use Illuminate\Http\Request;

class MessageController extends Controller
{
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
            'content' => ['required', 'string'],
        ]);

        $userMessage = $conversation->messages()->create([
            'role' => 'user',
            'content' => $validated['content'],
        ]);

        $assistantMessage = $conversation->messages()->create([
            'role' => 'assistant',
            'content' => 'I received your message. AI model replies are not configured yet.',
        ]);

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
