<?php

namespace App\Http\Controllers;

use App\Models\Conversation;
use Illuminate\Http\Request;

class ConversationController extends Controller
{
    public function index(Request $request)
    {
        $conversations = $request->user()
            ->conversations()
            ->latest('updated_at')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $conversations,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'title' => ['nullable', 'string', 'max:255'],
            'model' => ['nullable', 'string', 'max:100'],
        ]);

        $conversation = $request->user()
            ->conversations()
            ->create([
                'title' => $validated['title'] ?? 'New Chat',
                'model' => $validated['model'] ?? null,
            ]);

        return response()->json([
            'success' => true,
            'data' => $conversation,
        ], 201);
    }

    public function show(Request $request, Conversation $conversation)
    {
        abort_unless(
            $conversation->user_id === $request->user()->id,
            404
        );

        $conversation->load([
            'messages' => function ($query) {
                $query->orderBy('created_at');
            }
        ]);

        return response()->json([
            'success' => true,
            'data' => $conversation,
        ]);
    }

    public function update(
        Request $request,
        Conversation $conversation
    ) {
        abort_unless(
            $conversation->user_id === $request->user()->id,
            404
        );

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
        ]);

        $conversation->update($validated);

        return response()->json([
            'success' => true,
            'data' => $conversation,
        ]);
    }

    public function destroy(
        Request $request,
        Conversation $conversation
    ) {
        abort_unless(
            $conversation->user_id === $request->user()->id,
            404
        );

        $conversation->delete();

        return response()->json([
            'success' => true,
            'message' => 'Conversation deleted successfully',
        ]);
    }
}
