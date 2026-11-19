<?php

namespace App\Services\Ai;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * ChatGPT through OpenAI's Chat Completions API (plain HTTP), with a strict
 * JSON schema response format. Instructions and context travel as the
 * system message (OpenAI caches long prefixes by itself); 30 second
 * timeout and 2 retries on rate limits, server errors and lost connections.
 */
class OpenAiProvider implements AiProvider
{
    public const ENDPOINT = 'https://api.openai.com/v1/chat/completions';

    public const TIMEOUT_SECONDS = 30;

    public function reply(AiRequest $request): AiReply
    {
        $started = hrtime(true);

        $response = $this->send($request->apiKey, [
            'model' => $request->model,
            'max_completion_tokens' => $request->maxTokens,
            'messages' => [
                ['role' => 'system', 'content' => trim($request->instructions."\n\n".$request->context)],
                ...$request->messages,
            ],
            'response_format' => [
                'type' => 'json_schema',
                'json_schema' => ['name' => 'customer_reply', 'strict' => true, 'schema' => AiReply::schema()],
            ],
        ]);

        $latency = (int) round((hrtime(true) - $started) / 1_000_000);
        $model = is_string($response->json('model')) ? $response->json('model') : $request->model;
        $input = (int) $response->json('usage.prompt_tokens', 0);
        $output = (int) $response->json('usage.completion_tokens', 0);

        if (is_string($response->json('choices.0.message.refusal'))) {
            return AiReply::refusal($model, $input, $output, $latency);
        }

        $content = $response->json('choices.0.message.content');

        return AiReply::fromJson(is_string($content) ? $content : null, $model, $input, $output, (int) $response->json('usage.prompt_tokens_details.cached_tokens', 0), $latency);
    }

    public function test(string $apiKey, string $model): void
    {
        $this->send($apiKey, [
            'model' => $model,
            'max_completion_tokens' => 16,
            'messages' => [['role' => 'user', 'content' => 'ping']],
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function send(string $apiKey, array $payload): Response
    {
        try {
            $response = $this->http($apiKey)->post(self::ENDPOINT, $payload);
        } catch (ConnectionException $exception) {
            $timeout = str_contains(strtolower($exception->getMessage()), 'timed out');

            throw new AiProviderException($timeout ? AiProviderException::TIMEOUT : AiProviderException::CONNECTION, null, $exception);
        }

        if ($response->successful()) {
            return $response;
        }

        $code = $response->json('error.code');

        throw new AiProviderException(match (true) {
            $response->status() === 401 => AiProviderException::INVALID_KEY,
            $response->status() === 403 => AiProviderException::PERMISSION,
            $response->status() === 404 || $code === 'model_not_found' => AiProviderException::MODEL_NOT_FOUND,
            $code === 'insufficient_quota' || $response->status() === 402 => AiProviderException::BILLING,
            $response->status() === 429 => AiProviderException::RATE_LIMIT,
            $response->status() === 408 => AiProviderException::TIMEOUT,
            $response->serverError() => AiProviderException::OVERLOADED,
            $response->status() === 400 => AiProviderException::BAD_REQUEST,
            default => AiProviderException::UNKNOWN,
        }, $response->status());
    }

    private function http(string $apiKey): PendingRequest
    {
        return Http::withToken($apiKey)
            ->acceptJson()
            ->timeout(self::TIMEOUT_SECONDS)
            ->connectTimeout(10)
            ->retry(3, 500, fn (Throwable $exception): bool => $exception instanceof ConnectionException
                || ($exception instanceof RequestException
                    && ($exception->response->status() === 429 && $exception->response->json('error.code') !== 'insufficient_quota'
                        || $exception->response->serverError())), throw: false);
    }
}
