<?php

namespace App\Http\Controllers\QuickReplies;

use App\Actions\QuickReplies\ChangeQuickReplyStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateStatusRequest;
use App\Models\QuickReply;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

class QuickReplyStatusController extends Controller
{
    /**
     * Activate or deactivate a quick reply.
     */
    public function update(UpdateStatusRequest $request, QuickReply $quickReply, ChangeQuickReplyStatus $changeStatus): RedirectResponse
    {
        Gate::authorize('changeStatus', $quickReply);

        $quickReply = $changeStatus->handle($quickReply, $request->boolean('is_active'), $request->user());

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $quickReply->is_active ? 'Respuesta rápida activada.' : 'Respuesta rápida desactivada.',
        ]);

        return back();
    }
}
