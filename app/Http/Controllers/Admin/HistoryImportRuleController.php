<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\HistoryImportRuleRequest;
use App\Models\DataTransfer;
use App\Models\HistoryImportRule;
use App\Models\User;
use App\Policies\HistoryImportPolicy;
use App\Services\Migration\History\HistoryClassifier;
use App\Services\Migration\History\HistoryImportSummary;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Classification rules of a batch: list, preview (counts, nothing
 * changes), apply, remove (its decisions go back to undecided).
 */
class HistoryImportRuleController extends Controller
{
    public function __construct(private HistoryImportPolicy $policy) {}

    public function index(Request $request, DataTransfer $transfer): JsonResponse
    {
        $this->policy->view($request->user(), $transfer)->authorize();

        return response()->json(['rules' => self::presentAll($transfer)]);
    }

    public function preview(HistoryImportRuleRequest $request, DataTransfer $transfer, HistoryClassifier $classifier): JsonResponse
    {
        return response()->json(['preview' => $classifier->preview($transfer, $request->criteria(), $request->decision(), $request->boolean('overwrite'))]);
    }

    public function store(HistoryImportRuleRequest $request, DataTransfer $transfer, HistoryClassifier $classifier, HistoryImportSummary $summary): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        $rule = $classifier->applyRule($transfer, $request->string('name')->value(), $request->criteria(), $request->decision(), $request->boolean('overwrite'), $actor);

        return response()->json(['rule' => self::present($rule), 'summary' => $summary->for($transfer)], 201);
    }

    public function destroy(Request $request, DataTransfer $transfer, HistoryImportRule $rule, HistoryClassifier $classifier, HistoryImportSummary $summary): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $this->policy->classify($actor, $transfer)->authorize();
        abort_unless($rule->data_transfer_id === $transfer->id, 404);

        $reverted = $classifier->removeRule($transfer, $rule, $actor);

        return response()->json(['reverted' => $reverted, 'summary' => $summary->for($transfer)]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function presentAll(DataTransfer $batch): array
    {
        return array_values(HistoryImportRule::query()->where('data_transfer_id', $batch->id)->with('creator:id,name')->orderBy('id')->get()
            ->map(fn (HistoryImportRule $rule): array => self::present($rule))->all());
    }

    /**
     * @return array<string, mixed>
     */
    public static function present(HistoryImportRule $rule): array
    {
        return [
            'id' => $rule->id,
            'name' => $rule->name,
            'criteria' => $rule->criteria === [] ? (object) [] : $rule->criteria,
            'decision' => $rule->decision->value,
            'decision_label' => $rule->decision->label(),
            'overwrite' => $rule->overwrite,
            'matched' => $rule->matched_count,
            'applied' => $rule->applied_count,
            'applied_at' => $rule->applied_at?->toIso8601String(),
            'created_by' => $rule->creator?->name,
        ];
    }
}
