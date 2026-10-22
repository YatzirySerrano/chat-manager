<?php

namespace App\Services\Operations;

/**
 * Result of one health or preflight verification. "Fail" means the
 * application cannot work correctly; "Warning" needs attention but the
 * service keeps running; "Skipped" means the check does not apply to this
 * configuration (e.g. Reverb when real time is disabled).
 */
enum HealthStatus: string
{
    case Ok = 'ok';
    case Warning = 'warning';
    case Fail = 'fail';
    case Skipped = 'skipped';

    public function label(): string
    {
        return match ($this) {
            self::Ok => 'Correcto',
            self::Warning => 'Atención',
            self::Fail => 'Falla',
            self::Skipped => 'No aplica',
        };
    }

    public function severity(): int
    {
        return match ($this) {
            self::Skipped, self::Ok => 0,
            self::Warning => 1,
            self::Fail => 2,
        };
    }

    /**
     * The worst of several statuses.
     *
     * @param  iterable<self>  $statuses
     */
    public static function worst(iterable $statuses): self
    {
        $worst = self::Ok;

        foreach ($statuses as $status) {
            if ($status->severity() > $worst->severity()) {
                $worst = $status;
            }
        }

        return $worst;
    }
}
