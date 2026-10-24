<?php

namespace App\Http\Controllers\Admin;

use App\Actions\WebChat\UpdateWidgetSettings;
use App\Enums\AuditAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\WebChatWidgetRequest;
use App\Models\Connection;
use App\Models\WebChatSession;
use App\Models\WebChatWidget;
use App\Services\AuditLogger;
use App\Support\SaveToast;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

/**
 * Widget of a web chat connection: its settings, the rotation of the public
 * key (the old snippet stops working and every visitor session is revoked)
 * and the rotation of the identity secret (shown once, never again).
 */
class WebChatWidgetController extends Controller
{
    public function __construct(private AuditLogger $audit) {}

    public function update(WebChatWidgetRequest $request, Connection $connection, UpdateWidgetSettings $updateSettings): RedirectResponse
    {
        $changed = $updateSettings->handle($this->widget($connection), $request->payload(), $request->user());

        SaveToast::flash($changed, 'Configuración del chat web guardada.');

        return back();
    }

    public function rotateKey(Request $request, Connection $connection): RedirectResponse
    {
        Gate::authorize('manageWidget', $connection);

        $widget = $this->widget($connection);
        $open = WebChatSession::query()->where('connection_id', $connection->id)->whereNull('revoked_at')->count();
        $widget->rotatePublicKey();

        $this->audit->record(AuditAction::WebChatKeyRotated, $connection, ['revoked_sessions' => $open], $request->user());

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => 'Clave pública rotada. Reemplaza el fragmento de código en tu sitio: el anterior dejó de funcionar y se cerraron las sesiones de los visitantes.',
        ]);

        return back();
    }

    public function rotateIdentitySecret(Request $request, Connection $connection): RedirectResponse
    {
        Gate::authorize('manageWidget', $connection);

        $secret = $this->widget($connection)->rotateIdentitySecret();

        $this->audit->record(AuditAction::WebChatIdentitySecretRotated, $connection, [], $request->user());

        Inertia::flash('identity_secret', $secret);
        Inertia::flash('toast', [
            'type' => 'success',
            'message' => 'Secreto de identificación generado. Cópialo ahora: no se volverá a mostrar.',
        ]);

        return back();
    }

    private function widget(Connection $connection): WebChatWidget
    {
        $widget = $connection->webChatWidget()->firstOrFail();
        $widget->setRelation('connection', $connection);

        return $widget;
    }
}
