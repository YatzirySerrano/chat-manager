<?php

namespace App\Services\Ai;

/**
 * One company's API behind the assistant (ClaudeProvider, OpenAiProvider).
 * Tests swap the whole thing through AiProviders, so nothing here ever
 * talks to the network outside production.
 */
interface AiProvider
{
    /**
     * Asks for the reply to a conversation, as structured JSON
     * {reply, handoff, reason}.
     *
     * @throws AiProviderException
     */
    public function reply(AiRequest $request): AiReply;

    /**
     * The smallest request that proves the key and the model work.
     *
     * @throws AiProviderException
     */
    public function test(string $apiKey, string $model): void;
}
