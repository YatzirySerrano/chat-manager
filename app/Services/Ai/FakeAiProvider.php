<?php

namespace App\Services\Ai;

use Closure;

/**
 * A provider that answers with a closure and remembers every request
 * (AiProviders::fake()).
 */
class FakeAiProvider implements AiProvider
{
    /** @var list<AiRequest> */
    public array $requests = [];

    /**
     * @param  Closure(AiRequest): AiReply  $answer
     */
    public function __construct(private Closure $answer) {}

    public function reply(AiRequest $request): AiReply
    {
        $this->requests[] = $request;

        return ($this->answer)($request);
    }

    public function test(string $apiKey, string $model): void
    {
        $this->reply(new AiRequest($apiKey, $model, '', '', [['role' => 'user', 'content' => 'ping']], 32));
    }
}
