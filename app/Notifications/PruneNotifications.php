<?php

namespace App\Notifications;

use Illuminate\Support\Facades\DB;

/**
 * Keeps the notification tables small: read notifications and the
 * deduplication receipts expire after a while (notifications are not
 * business records; the history lives in conversation events and audit).
 */
class PruneNotifications
{
    public const READ_RETENTION_DAYS = 90;

    public const UNREAD_RETENTION_DAYS = 180;

    /**
     * Receipts outlive any realistic webhook or job retry by far.
     */
    public const RECEIPT_RETENTION_DAYS = 30;

    /**
     * @return array{notifications: int, receipts: int}
     */
    public function handle(): array
    {
        $notifications = 0;

        do {
            $deleted = DB::table('notifications')
                ->where(fn ($query) => $query
                    ->where(fn ($read) => $read->whereNotNull('read_at')->where('read_at', '<', now()->subDays(self::READ_RETENTION_DAYS)))
                    ->orWhere('updated_at', '<', now()->subDays(self::UNREAD_RETENTION_DAYS)))
                ->limit(1000)
                ->delete();
            $notifications += $deleted;
        } while ($deleted === 1000);

        $receipts = 0;

        do {
            $deleted = DB::table('notification_receipts')
                ->where('created_at', '<', now()->subDays(self::RECEIPT_RETENTION_DAYS))
                ->limit(1000)
                ->delete();
            $receipts += $deleted;
        } while ($deleted === 1000);

        return ['notifications' => $notifications, 'receipts' => $receipts];
    }
}
