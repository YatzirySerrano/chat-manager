<?php

namespace App\Http\Controllers\Messaging;

use App\Actions\Templates\MessageTemplateListQuery;
use App\Enums\MessageTemplateCategory;
use App\Enums\MessageTemplateStatus;
use App\Enums\RecordState;
use App\Http\Controllers\Controller;
use App\Http\Resources\MessageTemplateResource;
use App\Models\Connection;
use App\Models\MessageTemplate;
use App\Models\User;
use App\Services\Exports\ReportExporter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * WhatsApp templates synchronized from Meta: list with filters and detail
 * with preview. Templates are created and edited in WhatsApp Manager; this
 * screen never claims an approval Meta did not report.
 */
class MessageTemplateController extends Controller
{
    public function index(Request $request, MessageTemplateListQuery $list, ReportExporter $exporter): Response
    {
        Gate::authorize('viewAny', MessageTemplate::class);

        /** @var User $actor */
        $actor = $request->user();
        $filters = $list->filters($actor, $request->validate(MessageTemplateListQuery::rules()));

        $templates = $list->handle($actor, $filters)
            ->with('channelConnection:id,name,display_phone_number')
            ->paginate(20)
            ->withQueryString();

        $connections = Connection::query()->visibleTo($actor)->notDeleted()->orderBy('name')->get(['id', 'name', 'display_phone_number', 'business_account_id', 'access_token', 'is_active']);
        $lastSynced = MessageTemplate::query()
            ->whereIn('connection_id', $connections->modelKeys())
            ->selectRaw('connection_id, max(last_synced_at) as last_synced_at')
            ->groupBy('connection_id')
            ->pluck('last_synced_at', 'connection_id');
        $counts = MessageTemplate::query()->visibleTo($actor)->notDeleted()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');

        return Inertia::render('templates/Index', [
            'templates' => MessageTemplateResource::collection($templates),
            'filters' => [...$filters, 'record' => $filters['record']->value],
            'recordStates' => RecordState::optionsFor($actor),
            'statuses' => MessageTemplateStatus::options(),
            'categories' => MessageTemplateCategory::options(),
            'languages' => MessageTemplate::query()->visibleTo($actor)->notDeleted()->distinct()->orderBy('language')->pluck('language')->all(),
            'connections' => $connections->map(fn (Connection $connection): array => [
                'id' => $connection->id,
                'name' => $connection->name,
                'display_phone_number' => $connection->display_phone_number,
                'can_sync' => $actor->can('sync', [MessageTemplate::class, $connection]) && filled($connection->business_account_id) && filled($connection->access_token),
                'last_synced_at' => $lastSynced[$connection->id] ?? null,
            ])->values()->all(),
            'stats' => [
                'total' => (int) $counts->sum(),
                'approved' => (int) ($counts[MessageTemplateStatus::Approved->value] ?? 0),
                'pending' => (int) ($counts[MessageTemplateStatus::Pending->value] ?? 0),
                'attention' => (int) collect([MessageTemplateStatus::Rejected, MessageTemplateStatus::Paused, MessageTemplateStatus::Disabled])
                    ->sum(fn (MessageTemplateStatus $status): int => (int) ($counts[$status->value] ?? 0)),
            ],
            'canExport' => $exporter->allowsReport('message-templates', $actor),
        ]);
    }

    public function show(Request $request, MessageTemplate $template): Response
    {
        Gate::authorize('view', $template);

        $template->load('channelConnection:id,name,display_phone_number');

        return Inertia::render('templates/Show', [
            'template' => MessageTemplateResource::make($template)->resolve($request),
            'components' => $template->components ?? [],
        ]);
    }
}
