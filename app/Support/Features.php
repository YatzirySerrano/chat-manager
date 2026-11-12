<?php

namespace App\Support;

/**
 * Feature flags of the application (config/features.php). The only reader
 * of that configuration, so a cached configuration is always honored.
 */
final class Features
{
    /**
     * Whether the phase-two service rules are enabled (FEATURE_PHASE_TWO).
     * Off, the application behaves as phase one: see config/features.php.
     */
    public static function phaseTwo(): bool
    {
        return (bool) config('features.phase_two', false);
    }
}
