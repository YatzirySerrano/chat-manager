<?php

namespace App\Support;

use Inertia\Inertia;

/**
 * Toast answered by edit endpoints. When the submitted data did not change
 * anything, the user gets an informative toast instead of a false success.
 */
class SaveToast
{
    public const NO_CHANGES = 'No había cambios por guardar.';

    /**
     * Flash the success message when something changed, or the informative
     * "nothing to save" message otherwise.
     */
    public static function flash(bool $changed, string $successMessage): void
    {
        Inertia::flash('toast', $changed
            ? ['type' => 'success', 'message' => $successMessage]
            : ['type' => 'info', 'message' => self::NO_CHANGES]);
    }
}
