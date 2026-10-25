<?php

namespace App\Actions\Departments;

use App\Enums\ConversationStatus;
use App\Models\Department;
use App\Services\PlatformSettings;

class SummarizeDepartmentConversations
{
    public function __construct(private PlatformSettings $settings) {}

    /**
     * Real conversation counts of the department computed with a single
     * aggregate query. "Today" starts at midnight in the platform timezone.
     *
     * @return array{total: int, pending: int, open: int, unassigned: int, closed_today: int, closed_total: int}
     */
    public function handle(Department $department): array
    {
        $startOfToday = now((string) $this->settings->get('general.timezone'))->startOfDay()->utc();
        $closed = ConversationStatus::Closed->value;

        /** @var object{total: int|string|null, pending: int|string|null, open: int|string|null, unassigned: int|string|null, closed_today: int|string|null, closed_total: int|string|null} $counts */
        $counts = $department->conversations()
            ->toBase()
            ->selectRaw('COUNT(*) AS total')
            ->selectRaw('SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) AS pending', [ConversationStatus::Pending->value])
            ->selectRaw('SUM(CASE WHEN status IN (?, ?) THEN 1 ELSE 0 END) AS open', array_map(fn (ConversationStatus $status): string => $status->value, ConversationStatus::attending()))
            ->selectRaw('SUM(CASE WHEN status != ? AND assigned_user_id IS NULL THEN 1 ELSE 0 END) AS unassigned', [$closed])
            ->selectRaw('SUM(CASE WHEN status = ? AND closed_at >= ? THEN 1 ELSE 0 END) AS closed_today', [$closed, $startOfToday])
            ->selectRaw('SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) AS closed_total', [$closed])
            ->first();

        return [
            'total' => (int) $counts->total,
            'pending' => (int) $counts->pending,
            'open' => (int) $counts->open,
            'unassigned' => (int) $counts->unassigned,
            'closed_today' => (int) $counts->closed_today,
            'closed_total' => (int) $counts->closed_total,
        ];
    }
}
