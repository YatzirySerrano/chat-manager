<?php

namespace App\Http\Controllers\Categories;

use App\Actions\Categories\UpdateConversationCategoryRules;
use App\Http\Controllers\Controller;
use App\Http\Requests\Categories\UpdateConversationCategoryRulesRequest;
use App\Models\ConversationCategory;
use App\Support\SaveToast;
use Illuminate\Http\RedirectResponse;

class ConversationCategoryRulesController extends Controller
{
    /**
     * Replace the service rules the category overrides.
     */
    public function update(UpdateConversationCategoryRulesRequest $request, ConversationCategory $category, UpdateConversationCategoryRules $updateRules): RedirectResponse
    {
        $changes = $updateRules->handle($category, $request->overrides(), $request->user());

        SaveToast::flash($changes !== [], 'Reglas de la categoría actualizadas.');

        return back();
    }
}
