<?php

namespace App\Http\Controllers\Notifications;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Notifications\NotificationCenter;
use App\Notifications\NotificationPreferences;
use App\Notifications\NotificationPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * JSON endpoints of the notification bell. They only ever touch the
 * authenticated user's own notifications; texts are built at read time with
 * fresh access checks (see NotificationPresenter).
 */
class NotificationController extends Controller
{
    private const PAGE_SIZE = 20;

    public function index(Request $request, NotificationCenter $center, NotificationPresenter $presenter, NotificationPreferences $preferences): JsonResponse
    {
        $validated = $request->validate([
            'unread' => ['sometimes', 'boolean'],
            'before' => ['sometimes', 'date'],
            'before_id' => ['sometimes', 'uuid'],
        ]);

        /** @var User $user */
        $user = $request->user();
        $notifications = $center->latest(
            $user,
            self::PAGE_SIZE + 1,
            (bool) ($validated['unread'] ?? false),
            isset($validated['before']) ? now()->parse($validated['before'])->setTimezone(config('app.timezone'))->format('Y-m-d H:i:s') : null,
            isset($validated['before']) ? ($validated['before_id'] ?? null) : null,
        );
        $hasMore = $notifications->count() > self::PAGE_SIZE;
        $page = $notifications->take(self::PAGE_SIZE);
        $last = $page->last();

        return response()->json([
            'data' => $presenter->presentMany($page, $user),
            'unread' => $center->unreadSummary($user),
            'preferences' => $preferences->for($user),
            'next_before' => $hasMore && $last !== null ? $last->updated_at?->toIso8601String() : null,
            'next_before_id' => $hasMore && $last !== null ? (string) $last->id : null,
            // Lets the client tell news from notifications that were already
            // old when it fetched them (after a long disconnection), without
            // trusting the device clock.
            'server_time' => now()->toIso8601String(),
        ]);
    }

    /**
     * Cheap counter for the bell badge (used by the periodic refresh).
     */
    public function summary(Request $request, NotificationCenter $center): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json(['unread' => $center->unreadSummary($user)]);
    }

    public function read(Request $request, string $notification, NotificationCenter $center): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json([
            'changed' => $center->markRead($user, $notification),
            'unread' => $center->unreadSummary($user),
        ]);
    }

    public function readAll(Request $request, NotificationCenter $center): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json([
            'changed' => $center->markAllRead($user),
            'unread' => $center->unreadSummary($user),
        ]);
    }

    /**
     * The user has the conversation on screen: its notifications are read.
     * The id is not resolved as a model, so probing ids reveals nothing.
     */
    public function readConversation(Request $request, int $conversation, NotificationCenter $center): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json([
            'changed' => $center->markConversationRead($user, $conversation),
            'unread' => $center->unreadSummary($user),
        ]);
    }
}
