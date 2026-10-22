<?php

namespace App\Listeners\Security;

use App\Enums\AuditAction;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Laravel\Fortify\Events\RecoveryCodesGenerated;
use Laravel\Fortify\Events\TwoFactorAuthenticationConfirmed;
use Laravel\Fortify\Events\TwoFactorAuthenticationDisabled;
use Laravel\Fortify\Fortify;

/**
 * Writes the account security events of Fortify to the audit log: password
 * reset by e-mail link, two-factor authentication turned on (once confirmed)
 * or off, recovery codes regenerated, and sign-in lockouts after repeated
 * failures. No password, code, secret or token is ever stored; the audit
 * logger strips sensitive keys anyway. A user's own password change is
 * audited by the settings controller, which does not dispatch an event.
 */
class AuditSecurityEvents
{
    /** Seconds during which repeated lockouts of the same e-mail and IP are recorded once. */
    private const LOCKOUT_DEDUPLICATION_SECONDS = 60;

    public function __construct(private AuditLogger $audit) {}

    public function handlePasswordReset(PasswordReset $event): void
    {
        if ($event->user instanceof User) {
            $this->audit->record(AuditAction::AuthPasswordResetByLink, $event->user, [], $event->user);
        }
    }

    public function handleTwoFactorConfirmed(TwoFactorAuthenticationConfirmed $event): void
    {
        $this->audit->record(AuditAction::AuthTwoFactorEnabled, $event->user, [], $event->user);
    }

    public function handleTwoFactorDisabled(TwoFactorAuthenticationDisabled $event): void
    {
        $this->audit->record(AuditAction::AuthTwoFactorDisabled, $event->user, [], $event->user);
    }

    public function handleRecoveryCodesGenerated(RecoveryCodesGenerated $event): void
    {
        $this->audit->record(AuditAction::AuthRecoveryCodesRegenerated, $event->user, [], $event->user);
    }

    /**
     * Every throttled attempt fires a lockout; one entry per e-mail and IP
     * and minute is enough to investigate a brute force attempt.
     */
    public function handleLockout(Lockout $event): void
    {
        $email = Str::limit(Str::lower(trim((string) $event->request->input(Fortify::username()))), 191, '');

        if (! Cache::add('audit:lockout:'.sha1($email.'|'.$event->request->ip()), true, self::LOCKOUT_DEDUPLICATION_SECONDS)) {
            return;
        }

        $user = $email === '' ? null : User::query()->where('email', $email)->first();

        $this->audit->record(AuditAction::AuthLockout, $user, ['email' => $email]);
    }
}
