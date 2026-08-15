<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class AiService
{
    /**
     * Generate an assistant reply from chat messages.
     *
     * @param  array<int, array{role: string, content: string}>  $messages
     * @return array{content: string, model: string, prompt_tokens: ?int, completion_tokens: ?int, total_tokens: ?int}
     */
    public function chat(array $messages, ?string $model = null): array
    {
        $mode = config('ai.mode', 'online');

        if ($mode !== 'online') {
            throw new RuntimeException(
                'Only online AI mode is enabled right now. Local/Ollama is not set up yet.'
            );
        }

        $provider = config('ai.online_provider', 'gemini');

        return match ($provider) {
            'gemini' => $this->chatGemini($messages, $model),
            'groq' => $this->chatOpenAiCompatible('groq', $messages, $model),
            'pollinations' => $this->chatPollinations($messages, $model),
            default => throw new RuntimeException("Unsupported online AI provider [{$provider}]."),
        };
    }

    /**
     * Google Gemini free-tier generateContent API.
     *
     * @param  array<int, array{role: string, content: string}>  $messages
     * @return array{content: string, model: string, prompt_tokens: ?int, completion_tokens: ?int, total_tokens: ?int}
     */
    protected function chatGemini(array $messages, ?string $model = null): array
    {
        $config = config('ai.providers.gemini');
        $apiKey = $config['api_key'] ?? null;

        if (!$apiKey) {
            throw new RuntimeException(
                'GEMINI_API_KEY is missing. Get a free key at https://aistudio.google.com/apikey and add it to .env'
            );
        }

        $preferred = $model ?: ($config['model'] ?? 'gemini-3.5-flash');
        $candidates = array_values(array_unique(array_filter([
            $preferred,
            'gemini-3.5-flash',
            'gemini-flash-latest',
            'gemini-3.1-flash-lite',
            'gemini-flash-lite-latest',
        ])));

        $contents = [];

        foreach ($messages as $message) {
            if (($message['role'] ?? '') === 'system') {
                continue;
            }

            $role = ($message['role'] ?? 'user') === 'assistant' ? 'model' : 'user';

            $contents[] = [
                'role' => $role,
                'parts' => [
                    ['text' => (string) $message['content']],
                ],
            ];
        }

        if ($contents === []) {
            throw new RuntimeException('No messages provided to Gemini.');
        }

        $payload = [
            'contents' => $contents,
        ];

        $system = config('ai.system_prompt');

        if ($system) {
            $payload['systemInstruction'] = [
                'parts' => [
                    ['text' => $system],
                ],
            ];
        }

        $lastError = null;

        foreach ($candidates as $chosenModel) {
            $url = rtrim($config['base_url'], '/')
                . '/models/'
                . $chosenModel
                . ':generateContent';

            $response = Http::timeout((int) config('ai.timeout', 60))
                ->acceptJson()
                ->asJson()
                ->withQueryParameters(['key' => $apiKey])
                ->post($url, $payload);

            if (!$response->successful()) {
                $apiMessage = data_get($response->json(), 'error.message');
                $lastError = $apiMessage
                    ? "Gemini error ({$chosenModel}): {$apiMessage}"
                    : "Online AI (Gemini/{$chosenModel}) failed with HTTP {$response->status()}.";

                Log::warning('Gemini model attempt failed', [
                    'model' => $chosenModel,
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                // Try next model on not-found / overloaded
                if (in_array($response->status(), [404, 429, 503], true)) {
                    continue;
                }

                throw new RuntimeException($lastError);
            }

            $data = $response->json();
            $content = data_get($data, 'candidates.0.content.parts.0.text');

            if (!is_string($content) || trim($content) === '') {
                $lastError = "Gemini ({$chosenModel}) returned an empty response.";
                continue;
            }

            return [
                'content' => trim($content),
                'model' => $chosenModel,
                'prompt_tokens' => data_get($data, 'usageMetadata.promptTokenCount'),
                'completion_tokens' => data_get($data, 'usageMetadata.candidatesTokenCount'),
                'total_tokens' => data_get($data, 'usageMetadata.totalTokenCount'),
            ];
        }

        throw new RuntimeException($lastError ?: 'All Gemini model attempts failed.');
    }

    /**
     * Free anonymous Pollinations text API (GET) — unreliable fallback.
     *
     * @param  array<int, array{role: string, content: string}>  $messages
     * @return array{content: string, model: string, prompt_tokens: ?int, completion_tokens: ?int, total_tokens: ?int}
     */
    protected function chatPollinations(array $messages, ?string $model = null): array
    {
        $config = config('ai.providers.pollinations');
        $chosenModel = $model ?: ($config['model'] ?? 'openai');
        $prompt = $this->buildPlainPrompt($messages);

        if (strlen($prompt) > 2500) {
            $prompt = mb_substr($prompt, -2500);
        }

        $url = rtrim($config['get_url'] ?? 'https://text.pollinations.ai', '/')
            . '/' . rawurlencode($prompt);

        $response = Http::timeout((int) config('ai.timeout', 60))
            ->withHeaders([
                'Accept' => 'text/plain',
            ])
            ->get($url);

        if (!$response->successful()) {
            Log::error('Pollinations GET failed', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            throw new RuntimeException(
                'Online AI (Pollinations) failed with HTTP '.$response->status().'.'
            );
        }

        $content = trim($response->body());

        if ($content === '' || str_starts_with($content, '{"error"')) {
            throw new RuntimeException('Online AI returned an empty response.');
        }

        return [
            'content' => $content,
            'model' => $chosenModel,
            'prompt_tokens' => null,
            'completion_tokens' => null,
            'total_tokens' => null,
        ];
    }

    /**
     * @param  array<int, array{role: string, content: string}>  $messages
     * @return array{content: string, model: string, prompt_tokens: ?int, completion_tokens: ?int, total_tokens: ?int}
     */
    protected function chatOpenAiCompatible(
        string $provider,
        array $messages,
        ?string $model = null
    ): array {
        $config = config("ai.providers.{$provider}");

        if (!$config || empty($config['base_url'])) {
            throw new RuntimeException("AI provider [{$provider}] is not configured.");
        }

        if (empty($config['api_key'])) {
            throw new RuntimeException(
                strtoupper($provider).'_API_KEY is missing in .env'
            );
        }

        $payload = [
            'model' => $model ?: ($config['model'] ?? 'llama-3.1-8b-instant'),
            'messages' => $this->withSystemPrompt($messages),
        ];

        $response = Http::timeout((int) config('ai.timeout', 60))
            ->acceptJson()
            ->asJson()
            ->withToken($config['api_key'])
            ->post($config['base_url'], $payload);

        if (!$response->successful()) {
            Log::error('Online AI request failed', [
                'provider' => $provider,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            throw new RuntimeException(
                "Online AI provider [{$provider}] failed with HTTP {$response->status()}."
            );
        }

        $data = $response->json();
        $content = data_get($data, 'choices.0.message.content');

        if (!is_string($content) || trim($content) === '') {
            throw new RuntimeException('Online AI returned an empty response.');
        }

        return [
            'content' => trim($content),
            'model' => (string) data_get($data, 'model', $payload['model']),
            'prompt_tokens' => data_get($data, 'usage.prompt_tokens'),
            'completion_tokens' => data_get($data, 'usage.completion_tokens'),
            'total_tokens' => data_get($data, 'usage.total_tokens'),
        ];
    }

    /**
     * @param  array<int, array{role: string, content: string}>  $messages
     */
    protected function buildPlainPrompt(array $messages): string
    {
        $userText = '';

        foreach (array_reverse($messages) as $message) {
            if (($message['role'] ?? '') === 'user') {
                $userText = trim((string) ($message['content'] ?? ''));
                break;
            }
        }

        if ($userText === '') {
            $userText = trim((string) (end($messages)['content'] ?? 'Hello'));
        }

        return $userText;
    }

    /**
     * @param  array<int, array{role: string, content: string}>  $messages
     * @return array<int, array{role: string, content: string}>
     */
    protected function withSystemPrompt(array $messages): array
    {
        $system = config('ai.system_prompt');

        if (!$system) {
            return $messages;
        }

        return array_merge([
            [
                'role' => 'system',
                'content' => $system,
            ],
        ], $messages);
    }
}
