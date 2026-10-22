<?php

namespace App\Http\Controllers\Messaging;

use App\Actions\Templates\SyncMessageTemplates;
use App\Enums\AuditAction;
use App\Http\Controllers\Controller;
use App\Models\Connection;
use App\Models\MessageTemplate;
use App\Services\AuditLogger;
use App\Services\Meta\MetaGraphException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Inertia\Inertia;

/**
 * Manual "Sincronizar" of a connection's templates with Meta.
 */
class MessageTemplateSyncController extends Controller
{
    private const ATTEMPTS_PER_MINUTE = 3;

    public function store(Request $request, Connection $connection, SyncMessageTemplates $sync, AuditLogger $audit): RedirectResponse
    {
        abort_unless(Connection::query()->visibleTo($request->user())->whereKey($connection->id)->exists(), 404);
        Gate::authorize('sync', [MessageTemplate::class, $connection]);

        $key = "templates-sync:{$request->user()->id}:{$connection->id}";

        if (RateLimiter::tooManyAttempts($key, self::ATTEMPTS_PER_MINUTE)) {
            Inertia::flash('toast', ['type' => 'warning', 'message' => 'Demasiadas sincronizaciones seguidas. Espera '.RateLimiter::availableIn($key).' segundos.']);

            return back();
        }

        RateLimiter::hit($key, 60);

        try {
            $result = $sync->handle($connection);
        } catch (MetaGraphException $exception) {
            Inertia::flash('toast', ['type' => $exception->rejected ? 'error' : 'warning', 'message' => $exception->getMessage()]);

            return back();
        }

        $audit->record(AuditAction::TemplatesSynchronized, $connection, $result, $request->user());

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => "Plantillas sincronizadas con Meta: {$result['total']} en total, {$result['created']} nuevas, {$result['updated']} actualizadas y {$result['removed']} retiradas.",
        ]);

        return back();
    }
}
