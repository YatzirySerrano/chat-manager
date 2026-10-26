<?php

namespace App\Services\Migration\History;

use App\Enums\AuditAction;
use App\Enums\HistoryImportDecision;
use App\Jobs\RebuildHistoryImportInventory;
use App\Models\DataTransfer;
use App\Models\HistoryImportConversation;
use App\Models\HistoryImportRule;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\Queues;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

/**
 * Classification of the inventory of a draft batch: one conversation at a
 * time, a selection or a whole filter at once, or a reviewable rule
 * (previewed with counts before it is applied). Every change is allowed
 * until the batch is confirmed. A destination a conversation does not
 * allow (import for a group chat, anything but "No importar" for an empty
 * one) is never set: bulk actions and rules skip it and say how many.
 * Recommendations are only shown; the admin always decides.
 *
 * @phpstan-import-type InventoryFilters from HistoryInventory
 */
class HistoryClassifier
{
    public const SOURCE_MANUAL = 'manual';

    public const SOURCE_BULK = 'bulk';

    public const SOURCE_RULE = 'rule';

    public function __construct(private HistoryInventory $inventory, private AuditLogger $audit) {}

    /**
     * Decide one conversation (null = back to undecided), optionally with
     * the participants who are the company and whether its contact is saved
     * to the directory.
     *
     * @param  array{decision?: string|null, business_participants?: list<string>|null, save_contact?: bool}  $changes
     *
     * @throws ValidationException
     * @throws HistoryImportConflict
     */
    public function update(DataTransfer $batch, HistoryImportConversation $row, array $changes, User $actor): HistoryImportConversation
    {
        $rebuild = HistoryImportBatches::whileDraft($batch, function () use ($batch, $row, $changes, $actor): bool {
            $row = HistoryImportConversation::query()->lockForUpdate()->findOrFail($row->id);
            $rebuild = false;

            if (array_key_exists('business_participants', $changes)) {
                $business = $changes['business_participants'];

                if ($business !== null && array_diff($business, $row->participants ?? []) !== []) {
                    throw ValidationException::withMessages(['business_participants' => 'Elige participantes de la lista de esta conversación.']);
                }

                $row->business_participants = $business === null ? null : array_values(array_unique($business));
                $rebuild = $row->isDirty('business_participants');

                if ($row->source_kind === HistoryImportConversation::KIND_CHAT_TEXT) {
                    // Known at once (the rebuild that follows recounts the rest).
                    $row->is_group = HistoryInventory::isGroupChat($row->participants ?? [], $row->business_participants);
                }
            }

            if (array_key_exists('save_contact', $changes)) {
                $row->save_contact = (bool) $changes['save_contact'];
            }

            if (array_key_exists('decision', $changes)) {
                $decision = $changes['decision'] === null ? null : HistoryImportDecision::from($changes['decision']);

                if ($decision !== null && ! $row->allows($decision)) {
                    throw ValidationException::withMessages(['decision' => $this->notAllowedMessage($row)]);
                }

                $row->forceFill([
                    'decision' => $decision,
                    'decision_source' => $decision === null ? null : self::SOURCE_MANUAL,
                    'decided_by_user_id' => $decision === null ? null : $actor->id,
                    'decided_at' => $decision === null ? null : now(),
                    'applied_rule_id' => null,
                ]);

                $this->audit->record(AuditAction::HistoryImportClassified, null, [
                    'transfer_id' => $batch->id,
                    'inventory_id' => $row->id,
                    'decision' => $decision?->value,
                    'count' => 1,
                ], $actor);
            }

            $row->save();

            return $rebuild;
        });

        if ($rebuild) {
            // Who wrote each message changes: recounted in the background
            // (the decision is re-checked there and reset if no longer allowed).
            RebuildHistoryImportInventory::dispatch($batch->id)->onQueue(Queues::name(Queues::EXPORTS));
        }

        return $row->refresh();
    }

    /**
     * Decide a selection (ids) or everything a filter matches ($decide
     * false only changes save_contact; a null decision means undecided).
     *
     * @param  list<int>|null  $ids
     * @param  InventoryFilters|null  $filters
     * @return array{matched: int, updated: int, not_allowed: int}
     *
     * @throws HistoryImportConflict
     */
    public function bulk(DataTransfer $batch, bool $decide, ?HistoryImportDecision $decision, ?array $ids, ?array $filters, User $actor, ?bool $saveContact = null): array
    {
        return HistoryImportBatches::whileDraft($batch, fn (): array => $this->bulkLocked($batch, $decide, $decision, $ids, $filters, $actor, $saveContact));
    }

    /**
     * @param  list<int>|null  $ids
     * @param  InventoryFilters|null  $filters
     * @return array{matched: int, updated: int, not_allowed: int}
     */
    private function bulkLocked(DataTransfer $batch, bool $decide, ?HistoryImportDecision $decision, ?array $ids, ?array $filters, User $actor, ?bool $saveContact): array
    {
        $scope = $this->scope($batch, $ids, $filters);
        $matched = (clone $scope)->count();

        if ($saveContact !== null) {
            (clone $scope)->update(['save_contact' => $saveContact, 'updated_at' => now()]);
        }

        if (! $decide) {
            return ['matched' => $matched, 'updated' => $matched, 'not_allowed' => 0];
        }

        $allowed = $this->allowing((clone $scope), $decision);
        $updated = $allowed->update([
            'decision' => $decision?->value,
            'decision_source' => $decision === null ? null : self::SOURCE_BULK,
            'decided_by_user_id' => $decision === null ? null : $actor->id,
            'decided_at' => $decision === null ? null : now(),
            'applied_rule_id' => null,
            'updated_at' => now(),
        ]);

        $this->audit->record(AuditAction::HistoryImportClassified, null, [
            'transfer_id' => $batch->id,
            'decision' => $decision?->value,
            'by' => $ids !== null ? 'selection' : 'filter',
            'count' => $updated,
            'not_allowed' => $matched - $updated,
        ], $actor);

        return ['matched' => $matched, 'updated' => $updated, 'not_allowed' => $matched - $updated];
    }

    /**
     * What a rule would do, without changing anything.
     *
     * @param  InventoryFilters  $criteria
     * @return array{matched: int, would_change: int, already_decided: int, not_allowed: int}
     */
    public function preview(DataTransfer $batch, array $criteria, HistoryImportDecision $decision, bool $overwrite): array
    {
        $scope = $this->inventory->query($batch, $criteria);
        $matched = (clone $scope)->count();
        $allowed = $this->allowing(clone $scope, $decision);
        $allowedCount = (clone $allowed)->count();
        $alreadyDecided = (clone $allowed)->whereNotNull('decision')->where('decision', '!=', $decision)->count();
        $same = (clone $allowed)->where('decision', $decision)->count();

        return [
            'matched' => $matched,
            'would_change' => $overwrite ? $allowedCount - $same : $allowedCount - $same - $alreadyDecided,
            'already_decided' => $overwrite ? 0 : $alreadyDecided,
            'not_allowed' => $matched - $allowedCount,
        ];
    }

    /**
     * Save a rule and apply it (after the admin saw its preview).
     *
     * @param  InventoryFilters  $criteria
     *
     * @throws HistoryImportConflict
     */
    public function applyRule(DataTransfer $batch, string $name, array $criteria, HistoryImportDecision $decision, bool $overwrite, User $actor): HistoryImportRule
    {
        return HistoryImportBatches::whileDraft($batch, function () use ($batch, $name, $criteria, $decision, $overwrite, $actor): HistoryImportRule {
            $rule = new HistoryImportRule;
            $rule->forceFill([
                'data_transfer_id' => $batch->id,
                'name' => mb_substr(trim(strip_tags($name)), 0, 120) ?: 'Regla',
                'criteria' => $criteria,
                'decision' => $decision,
                'overwrite' => $overwrite,
                'created_by_user_id' => $actor->id,
            ])->save();

            $scope = $this->allowing($this->inventory->query($batch, $criteria), $decision)
                ->when(! $overwrite, fn (Builder $query) => $query->whereNull('decision'));

            $applied = $scope->update([
                'decision' => $decision->value,
                'decision_source' => self::SOURCE_RULE,
                'decided_by_user_id' => $actor->id,
                'decided_at' => now(),
                'applied_rule_id' => $rule->id,
                'updated_at' => now(),
            ]);

            $rule->forceFill([
                'matched_count' => $this->inventory->query($batch, $criteria)->count(),
                'applied_count' => $applied,
                'applied_at' => now(),
            ])->save();

            $this->audit->record(AuditAction::HistoryImportRuleApplied, null, [
                'transfer_id' => $batch->id,
                'rule_id' => $rule->id,
                'decision' => $decision->value,
                'overwrite' => $overwrite,
                'matched' => $rule->matched_count,
                'applied' => $applied,
            ], $actor);

            return $rule;
        });
    }

    /**
     * Remove a rule: the conversations it decided (and nobody changed
     * since) go back to undecided.
     *
     * @throws HistoryImportConflict
     */
    public function removeRule(DataTransfer $batch, HistoryImportRule $rule, User $actor): int
    {
        return HistoryImportBatches::whileDraft($batch, function () use ($batch, $rule, $actor): int {
            $reverted = HistoryImportConversation::query()
                ->where('data_transfer_id', $batch->id)
                ->where('applied_rule_id', $rule->id)
                ->where('decision_source', self::SOURCE_RULE)
                ->update(['decision' => null, 'decision_source' => null, 'decided_by_user_id' => null, 'decided_at' => null, 'applied_rule_id' => null, 'updated_at' => now()]);

            $rule->delete();

            $this->audit->record(AuditAction::HistoryImportClassified, null, [
                'transfer_id' => $batch->id,
                'rule_id' => $rule->id,
                'rule_removed' => true,
                'count' => $reverted,
            ], $actor);

            return $reverted;
        });
    }

    /**
     * @param  list<int>|null  $ids
     * @param  InventoryFilters|null  $filters
     * @return Builder<HistoryImportConversation>
     */
    private function scope(DataTransfer $batch, ?array $ids, ?array $filters): Builder
    {
        return $ids !== null
            ? HistoryImportConversation::query()->where('data_transfer_id', $batch->id)->whereKey($ids)
            : $this->inventory->query($batch, $filters ?? []);
    }

    /**
     * Rows of the scope that allow the decision (same rule as
     * HistoryImportConversation::allowedDecisions()).
     *
     * @param  Builder<HistoryImportConversation>  $scope
     * @return Builder<HistoryImportConversation>
     */
    private function allowing(Builder $scope, ?HistoryImportDecision $decision): Builder
    {
        return match ($decision) {
            HistoryImportDecision::Import => $scope->where('message_count', '>', 0)->where('is_group', false),
            HistoryImportDecision::Archive => $scope->where('message_count', '>', 0),
            HistoryImportDecision::Exclude, null => $scope,
        };
    }

    private function notAllowedMessage(HistoryImportConversation $row): string
    {
        return match (true) {
            $row->message_count === 0 => 'Esta conversación no tiene mensajes en los archivos: solo puede quedar como «No importar».',
            $row->is_group => 'Un chat de grupo solo puede archivarse como historial o quedar como «No importar».',
            default => 'Ese destino no está permitido para esta conversación.',
        };
    }
}
