<?php

namespace App\Http\Controllers\Tags;

use App\Actions\Tags\ChangeTagStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateStatusRequest;
use App\Models\Tag;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

class TagStatusController extends Controller
{
    /**
     * Activate or deactivate a tag.
     */
    public function update(UpdateStatusRequest $request, Tag $tag, ChangeTagStatus $changeStatus): RedirectResponse
    {
        Gate::authorize('changeStatus', $tag);

        $tag = $changeStatus->handle($tag, $request->boolean('is_active'), $request->user());

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $tag->is_active ? 'Etiqueta activada.' : 'Etiqueta desactivada. Se conserva donde ya estaba aplicada.',
        ]);

        return back();
    }
}
