<?php

namespace App\Services\Ai;

/**
 * What a provider answered, with what it cost (kept on the message's
 * metadata, never in the log). A refusal or an answer that is not the
 * expected JSON arrives as a handoff.
 */
final readonly class AiReply
{
    public function __construct(
        public string $reply,
        public bool $handoff,
        public string $reason,
        public string $model,
        public int $inputTokens = 0,
        public int $outputTokens = 0,
        public int $cachedTokens = 0,
        public int $latencyMs = 0,
    ) {}

    /**
     * Reads the structured answer ({"reply", "handoff", "reason"}). Anything
     * else hands the conversation off without a reply of its own.
     */
    public static function fromJson(?string $json, string $model, int $inputTokens, int $outputTokens, int $cachedTokens, int $latencyMs): self
    {
        $data = is_string($json) ? json_decode($json, true) : null;

        if (! is_array($data) || ! is_string($data['reply'] ?? null) || ! is_bool($data['handoff'] ?? null)) {
            return new self('', true, 'invalid_output', $model, $inputTokens, $outputTokens, $cachedTokens, $latencyMs);
        }

        $reply = trim($data['reply']);

        return new self(
            $reply,
            $data['handoff'] || $reply === '',
            is_string($data['reason'] ?? null) ? mb_substr(trim($data['reason']), 0, 200) : '',
            $model,
            $inputTokens,
            $outputTokens,
            $cachedTokens,
            $latencyMs,
        );
    }

    /**
     * The provider declined to answer (a safety refusal): a person takes it.
     */
    public static function refusal(string $model, int $inputTokens, int $outputTokens, int $latencyMs): self
    {
        return new self('', true, 'refusal', $model, $inputTokens, $outputTokens, 0, $latencyMs);
    }

    /**
     * The JSON schema both providers are held to.
     *
     * @return array<string, mixed>
     */
    public static function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'reply' => ['type' => 'string', 'description' => 'The message for the customer, in their language.'],
                'handoff' => ['type' => 'boolean', 'description' => 'True when a person must take over.'],
                'reason' => ['type' => 'string', 'description' => 'Short internal reason for the handoff, empty otherwise.'],
            ],
            'required' => ['reply', 'handoff', 'reason'],
            'additionalProperties' => false,
        ];
    }
}
