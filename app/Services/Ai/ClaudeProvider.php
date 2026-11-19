<?php

namespace App\Services\Ai;

use Anthropic\Beta\Messages\BetaTextBlock;
use Anthropic\Client;
use Anthropic\Core\Exceptions\AnthropicException;
use Anthropic\Core\Exceptions\APIConnectionException;
use Anthropic\Core\Exceptions\APIStatusException;
use Anthropic\Core\Exceptions\APITimeoutException;
use Anthropic\ErrorType;
use GuzzleHttp\Client as Guzzle;
use Psr\Http\Client\ClientInterface;

/**
 * Claude through the official Anthropic PHP SDK (anthropic-ai/sdk):
 *
 * - the instructions, business information and catalog go in `system`
 *   with a cache breakpoint (a stable prefix: no dates, no names), the
 *   per-request context after it, the conversation in `messages`;
 * - structured output (`output_config.format`, JSON schema of AiReply);
 * - low effort: short customer-service answers, quickly (thinking cannot
 *   be disabled on the current models, effort is the only control);
 * - on Opus 5.5 / Sonnet 5.5, server-side refusal fallbacks (beta
 *   `server-side-fallback-2026-07-01`, `fallbacks: "default"`); a refusal
 *   that still stands hands the conversation to a person;
 * - 30 second timeout per attempt (enforced by the Guzzle transport, the
 *   SDK does not) and the SDK's 2 retries on 408/409/429/5xx.
 */
class ClaudeProvider implements AiProvider
{
    public const EFFORT = 'low';

    public const TIMEOUT_SECONDS = 30;

    public const FALLBACK_BETA = 'server-side-fallback-2026-07-01';

    /** Models that accept server-side refusal fallbacks. */
    public const FALLBACK_MODELS = ['claude-opus-5-5', 'claude-sonnet-5-5'];

    private ?ClientInterface $transporter = null;

    /**
     * A custom HTTP transport (verification scripts record the request
     * instead of sending it).
     */
    public function usingTransporter(ClientInterface $transporter): static
    {
        $this->transporter = $transporter;

        return $this;
    }

    public function reply(AiRequest $request): AiReply
    {
        $fallbacks = in_array($request->model, self::FALLBACK_MODELS, true);
        $system = [['type' => 'text', 'text' => $request->instructions, 'cacheControl' => ['type' => 'ephemeral']]];

        if (trim($request->context) !== '') {
            $system[] = ['type' => 'text', 'text' => $request->context];
        }

        $started = hrtime(true);

        try {
            $message = $this->client($request->apiKey)->beta->messages->create(
                maxTokens: $request->maxTokens,
                messages: $request->messages,
                model: $request->model,
                fallbacks: $fallbacks ? 'default' : null,
                outputConfig: [
                    'effort' => self::EFFORT,
                    'format' => ['type' => 'json_schema', 'schema' => AiReply::schema()],
                ],
                system: $system,
                betas: $fallbacks ? [self::FALLBACK_BETA] : null,
            );
        } catch (AnthropicException $exception) {
            throw self::translate($exception);
        }

        $latency = (int) round((hrtime(true) - $started) / 1_000_000);
        $usage = $message->usage;
        $input = $usage->inputTokens + (int) $usage->cacheReadInputTokens + (int) $usage->cacheCreationInputTokens;

        if ($message->stopReason === 'refusal') {
            return AiReply::refusal($message->model, $input, $usage->outputTokens, $latency);
        }

        $text = '';

        foreach ($message->content as $block) {
            if ($block instanceof BetaTextBlock) {
                $text .= $block->text;
            }
        }

        return AiReply::fromJson($text, $message->model, $input, $usage->outputTokens, (int) $usage->cacheReadInputTokens, $latency);
    }

    public function test(string $apiKey, string $model): void
    {
        try {
            $this->client($apiKey)->messages->create(
                maxTokens: 32,
                messages: [['role' => 'user', 'content' => 'ping']],
                model: $model,
                outputConfig: ['effort' => 'low'],
            );
        } catch (AnthropicException $exception) {
            throw self::translate($exception);
        }
    }

    private function client(string $apiKey): Client
    {
        return new Client(
            apiKey: $apiKey,
            requestOptions: ['transporter' => $this->transporter ?? new Guzzle(['timeout' => self::TIMEOUT_SECONDS, 'connect_timeout' => 10])],
            openTelemetry: false,
        );
    }

    private static function translate(AnthropicException $exception): AiProviderException
    {
        if ($exception instanceof APITimeoutException) {
            return new AiProviderException(AiProviderException::TIMEOUT, null, $exception);
        }

        if ($exception instanceof APIConnectionException) {
            return new AiProviderException(AiProviderException::CONNECTION, null, $exception);
        }

        if (! $exception instanceof APIStatusException) {
            return new AiProviderException(AiProviderException::UNKNOWN, null, $exception);
        }

        $type = match (ErrorType::tryFrom((string) $exception->getType())) {
            ErrorType::AUTHENTICATION_ERROR => AiProviderException::INVALID_KEY,
            ErrorType::PERMISSION_ERROR => AiProviderException::PERMISSION,
            ErrorType::BILLING_ERROR => AiProviderException::BILLING,
            ErrorType::RATE_LIMIT_ERROR => AiProviderException::RATE_LIMIT,
            ErrorType::OVERLOADED_ERROR, ErrorType::API_ERROR => AiProviderException::OVERLOADED,
            ErrorType::TIMEOUT_ERROR => AiProviderException::TIMEOUT,
            ErrorType::NOT_FOUND_ERROR => AiProviderException::MODEL_NOT_FOUND,
            ErrorType::INVALID_REQUEST_ERROR => AiProviderException::BAD_REQUEST,
            default => match (true) {
                $exception->status === 401 => AiProviderException::INVALID_KEY,
                $exception->status === 402 => AiProviderException::BILLING,
                $exception->status === 403 => AiProviderException::PERMISSION,
                $exception->status === 404 => AiProviderException::MODEL_NOT_FOUND,
                $exception->status === 429 => AiProviderException::RATE_LIMIT,
                $exception->status >= 500 => AiProviderException::OVERLOADED,
                default => AiProviderException::UNKNOWN,
            },
        };

        return new AiProviderException($type, $exception->status, $exception);
    }
}
