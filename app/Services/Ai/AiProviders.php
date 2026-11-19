<?php

namespace App\Services\Ai;

use App\Enums\AiVendor;
use Closure;
use Illuminate\Container\Attributes\Singleton;

/**
 * Picks the provider of a vendor. fake() replaces every provider with one
 * implementation (tests and verification scripts), so they never reach the
 * real APIs. A singleton, so a fake lasts for the whole process.
 */
#[Singleton]
class AiProviders
{
    private ?AiProvider $fake = null;

    public function for(AiVendor $vendor): AiProvider
    {
        if ($this->fake !== null) {
            return $this->fake;
        }

        return match ($vendor) {
            AiVendor::Anthropic => app(ClaudeProvider::class),
            AiVendor::OpenAi => app(OpenAiProvider::class),
        };
    }

    /**
     * @param  AiProvider|Closure(AiRequest): AiReply  $provider
     */
    public function fake(AiProvider|Closure $provider): AiProvider
    {
        return $this->fake = $provider instanceof AiProvider ? $provider : new FakeAiProvider($provider);
    }
}
