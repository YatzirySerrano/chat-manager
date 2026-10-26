<?php

namespace App\Support\Audit;

use App\Enums\AuditAction;
use App\Enums\Permission;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Meta\MetaErrorCatalog;
use App\Services\PlatformSettings;
use App\Support\ProtectedAccounts;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Gate;

/**
 * Presentation of an audit entry in plain Spanish, shared by the audit screen
 * (AuditLogResource) and the audit export (AuditReport). It only reads the
 * stored entry: actions, property keys and values are never modified.
 *
 * - summary: one sentence with what happened, on which entity, who and when.
 * - subject: entity type and label, with a link when the entity has a page
 *   the viewer may open.
 * - changes: before/after per field, whatever shape was stored ({old,new},
 *   {from,to}, {added,removed}, {changed}, 'removed'/'replaced', dotted paths).
 * - details: the remaining properties with Spanish labels and values.
 * - result / error: outcome of verifications and the reason of failures.
 * - technical: action key, raw properties (always sanitized again with the
 *   AuditLogger rules) and the browser, only for viewers who may read them.
 *
 * @phpstan-type AuditChange array{field: string, label: string, before: string, after: string}
 * @phpstan-type AuditDetail array{key: string, label: string, value: string}
 * @phpstan-type AuditSubject array{type: string|null, type_label: string|null, id: int|null, label: string|null, url: string|null}
 * @phpstan-type AuditResult array{tone: 'success'|'warning'|'danger', label: string}
 * @phpstan-type AuditTechnical array{action: string, properties: array<array-key, mixed>, user_agent: string|null, error: string|null}
 * @phpstan-type AuditPresentation array{summary: string, subject: AuditSubject, changes: list<AuditChange>, details: list<AuditDetail>, result: AuditResult|null, error: string|null, technical: AuditTechnical|null}
 */
final class AuditPresenter
{
    /**
     * Identifiers and diagnostics: useful to support staff, noise to
     * everyone else. They are listed in the technical section only.
     *
     * @var list<string>
     */
    public const TECHNICAL_KEYS = [
        'meta_code', 'http_status', 'uuid', 'job', 'queue', 'note_id', 'attribution_id', 'member_id',
        'review_case_id', 'resolved_contact_id', 'from_contact_id', 'transfer_id', 'message_id',
        'connection_id', 'public_id', 'apps', 'report', 'owner_user_id', 'item_id', 'contact_id',
        'conversation_id', 'assigned_user_id',
    ];

    /**
     * Properties shown as the result or the error instead of a detail.
     *
     * @var list<string>
     */
    private const OUTCOME_KEYS = ['verification_result'];

    private ?AuditValueFormatter $formatter = null;

    public function __construct(
        private AuditFieldLabels $labels,
        private AuditLogger $logger,
        private PlatformSettings $settings,
    ) {}

    /**
     * Technical data (raw properties, error codes, identifiers) is shown to
     * those who operate the platform (system.view) or may export the audit
     * trail (audit.export, whose file carries the same data). Everyone else
     * with audit.view reads the plain-language presentation only.
     */
    public function canViewTechnical(?User $viewer): bool
    {
        return $viewer !== null
            && ($viewer->holdsPermission(Permission::ViewSystem) || $viewer->holdsPermission(Permission::ExportAuditLog));
    }

    /**
     * @param  Model|null  $subject  The audited model when it was loaded, used for the link.
     * @return AuditPresentation
     */
    public function present(AuditLog $log, ?User $viewer = null, ?Model $subject = null): array
    {
        $properties = $this->logger->sanitize($log->properties ?? []);
        $technical = $this->canViewTechnical($viewer);
        [$error, $errorDetail] = $this->error($log, $properties);

        return [
            'summary' => $this->summary($log, $viewer),
            'subject' => $this->subject($log, $viewer, $subject),
            'changes' => $this->changes($log, $properties),
            'details' => $this->details($log, $properties, $technical),
            'result' => $this->result($properties),
            'error' => $error,
            'technical' => $technical ? [
                'action' => $log->action->value,
                'properties' => $properties,
                'user_agent' => $log->user_agent,
                'error' => $errorDetail,
            ] : null,
        ];
    }

    /**
     * Load, in one query per type, the entities that have their own page so
     * present() can link them. Other subjects are never loaded.
     *
     * @param  iterable<AuditLog>  $logs
     */
    public function loadSubjects(iterable $logs): void
    {
        $byType = [];

        foreach ($logs as $log) {
            if ($log->auditable_id !== null && isset(AuditSubjectTypes::ROUTES[$log->auditable_type ?? ''])) {
                $byType[$log->auditable_type][] = $log;
            }
        }

        foreach ($byType as $type => $entries) {
            $class = Relation::getMorphedModel($type);

            if ($class === null) {
                continue;
            }

            $models = $class::query()->whereKey(array_unique(array_map(fn (AuditLog $log): int => (int) $log->auditable_id, $entries)))->get()->keyBy(fn (Model $model): int => (int) $model->getKey());

            foreach ($entries as $log) {
                $log->setRelation('auditable', $models->get((int) $log->auditable_id));
            }
        }
    }

    /**
     * One readable line per change and detail ("Campo: antes → después"),
     * for exports. Identifiers are included when requested.
     *
     * @return list<string>
     */
    public function lines(AuditLog $log, bool $includeTechnical = false): array
    {
        $properties = $this->logger->sanitize($log->properties ?? []);
        $lines = [];

        if (($result = $this->result($properties)) !== null) {
            $lines[] = 'Resultado: '.$result['label'];
        }

        [$error] = $this->error($log, $properties);

        if ($error !== null) {
            $lines[] = 'Error: '.$error;
        }

        foreach ($this->changes($log, $properties) as $change) {
            $lines[] = "{$change['label']}: {$change['before']} → {$change['after']}";
        }

        foreach ($this->details($log, $properties, $includeTechnical) as $detail) {
            $lines[] = "{$detail['label']}: {$detail['value']}";
        }

        return $lines;
    }

    public function subjectTypeLabel(?string $type): ?string
    {
        return AuditSubjectTypes::label($type);
    }

    /**
     * "Conexión creada: Conexión «Ventas (+52 55 1234 5678)». Lo realizó Ana López el 24/10/2026 a las 12:30."
     */
    public function summary(AuditLog $log, ?User $viewer = null): string
    {
        $what = $log->action->label();
        $type = AuditSubjectTypes::label($log->auditable_type);

        if ($type !== null) {
            $label = $log->auditable_label ?? '#'.$log->auditable_id;
            $what .= ": {$type} «{$label}»";
        }

        $moment = $log->created_at->copy()->setTimezone($this->timezone());
        $when = 'el '.$moment->format('d/m/Y').' a las '.$moment->format('H:i');
        $who = $log->user_id === null && ($log->actor_name === null || $log->actor_name === 'Sistema')
            ? "Lo registró el sistema {$when}."
            : 'Lo realizó '.$this->actorName($log, $viewer)." {$when}.";

        return "{$what}. {$who}";
    }

    /**
     * Who performed the action as the viewer may see it: the entry keeps the
     * stored name, but a protected account (root role) is presented as the
     * system administration to anyone without the root authority.
     */
    public function actorName(AuditLog $log, ?User $viewer): string
    {
        if ($log->user_id !== null && app(ProtectedAccounts::class)->hides($log->user_id, $viewer)) {
            return ProtectedAccounts::SYSTEM_NAME;
        }

        return $log->actor_name ?? 'Sistema';
    }

    /**
     * @return AuditSubject
     */
    private function subject(AuditLog $log, ?User $viewer, ?Model $subject): array
    {
        return [
            'type' => $log->auditable_type,
            'type_label' => AuditSubjectTypes::label($log->auditable_type),
            'id' => $log->auditable_id === null ? null : (int) $log->auditable_id,
            'label' => $log->auditable_label ?? ($log->auditable_id === null ? null : '#'.$log->auditable_id),
            'url' => $this->subjectUrl($log, $viewer, $subject),
        ];
    }

    /**
     * Only entities with their own page, loaded and visible to the viewer
     * (the policy's view ability), get a link; the page authorizes again.
     */
    private function subjectUrl(AuditLog $log, ?User $viewer, ?Model $subject): ?string
    {
        $route = AuditSubjectTypes::ROUTES[$log->auditable_type ?? ''] ?? null;

        if ($route === null || $viewer === null || $subject === null || $subject->getMorphClass() !== $log->auditable_type) {
            return null;
        }

        return Gate::forUser($viewer)->allows('view', $subject) ? route($route, $subject->getKey()) : null;
    }

    /**
     * @param  array<array-key, mixed>  $properties
     * @return list<AuditChange>
     */
    private function changes(AuditLog $log, array $properties): array
    {
        $raw = $properties['changes'] ?? null;
        $action = $log->action->value;
        $changes = [];

        if (! is_array($raw)) {
            return [...$this->transitionChanges($log->action, $properties), ...$this->listChanges($log->action, $properties)];
        }

        foreach ($raw as $field => $value) {
            $field = (string) $field;
            [$before, $after] = $this->beforeAfter($field, $value, $action);
            $changes[] = ['field' => $field, 'label' => $this->labels->label($field), 'before' => $before, 'after' => $after];
        }

        return [...$changes, ...$this->listChanges($log->action, $properties)];
    }

    /**
     * Entries that record a transition as top-level from/to values (role,
     * availability) are shown as one change.
     *
     * @param  array<array-key, mixed>  $properties
     * @return list<AuditChange>
     */
    private function transitionChanges(AuditAction $action, array $properties): array
    {
        if (! array_key_exists('from', $properties) || ! array_key_exists('to', $properties)) {
            return [];
        }

        $field = match ($action) {
            AuditAction::UserRoleChanged => 'role',
            AuditAction::UserAvailabilityChanged => 'availability',
            default => 'value',
        };
        [$before, $after] = $this->beforeAfter('to', ['from' => $properties['from'], 'to' => $properties['to']], $action->value);

        return [['field' => $field, 'label' => $this->labels->label($field), 'before' => $before, 'after' => $after]];
    }

    /**
     * Entries that record a membership change as top-level added/removed
     * lists (tags, departments, permissions) are shown as one change.
     *
     * @param  array<array-key, mixed>  $properties
     * @return list<AuditChange>
     */
    private function listChanges(AuditAction $action, array $properties): array
    {
        if (! array_key_exists('added', $properties) && ! array_key_exists('removed', $properties)) {
            return [];
        }

        $field = match ($action) {
            AuditAction::RolePermissionsChanged => 'permissions',
            AuditAction::UserDepartmentsChanged => 'departments',
            AuditAction::ContactTagsChanged, AuditAction::ConversationTagsChanged => 'tags',
            default => 'items',
        };

        return [[
            'field' => $field,
            'label' => $this->labels->label($field),
            ...$this->addedRemoved($field, $properties['added'] ?? [], $properties['removed'] ?? [], $action->value),
        ]];
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function beforeAfter(string $field, mixed $value, string $action): array
    {
        $format = $this->formatter();

        if (is_string($value)) {
            return match ($value) {
                'removed' => ['Tenía uno', 'Eliminado'],
                'replaced' => ['Anterior', 'Reemplazado'],
                default => ['—', $format->format($field, $value, $action)],
            };
        }

        if (! is_array($value)) {
            return ['—', $format->format($field, $value, $action)];
        }

        if (array_key_exists('old', $value) || array_key_exists('new', $value)) {
            return [$format->format($field, $value['old'] ?? null, $action), $format->format($field, $value['new'] ?? null, $action)];
        }

        if (array_key_exists('from', $value) || array_key_exists('to', $value)) {
            return [$format->format($field, $value['from'] ?? null, $action), $format->format($field, $value['to'] ?? null, $action)];
        }

        if (array_key_exists('added', $value) || array_key_exists('removed', $value)) {
            $pair = $this->addedRemoved($field, $value['added'] ?? [], $value['removed'] ?? [], $action);

            return [$pair['before'], $pair['after']];
        }

        if (($value['changed'] ?? null) === true) {
            return ['—', 'Modificado (el contenido no se guarda en la auditoría)'];
        }

        return ['—', $format->format($field, $value, $action)];
    }

    /**
     * Removed items go in "before" and added items in "after".
     *
     * @return array{before: string, after: string}
     */
    private function addedRemoved(string $field, mixed $added, mixed $removed, string $action): array
    {
        $format = $this->formatter();
        $added = is_array($added) ? $added : [$added];
        $removed = is_array($removed) ? $removed : [$removed];

        return [
            'before' => $removed === [] ? '—' : 'Se retiró: '.$format->format($field, array_values($removed), $action),
            'after' => $added === [] ? '—' : 'Se agregó: '.$format->format($field, array_values($added), $action),
        ];
    }

    /**
     * @param  array<array-key, mixed>  $properties
     * @return list<AuditDetail>
     */
    private function details(AuditLog $log, array $properties, bool $includeTechnical): array
    {
        $action = $log->action->value;
        $listed = $this->listChanges($log->action, $properties) !== [];
        $details = [];

        foreach ($properties as $key => $value) {
            $key = (string) $key;

            if ($key === 'changes' || in_array($key, self::OUTCOME_KEYS, true) || $this->isErrorKey($log, $key)) {
                continue;
            }

            if ($listed && in_array($key, ['added', 'removed'], true)) {
                continue;
            }

            if (in_array($key, ['from', 'to'], true) && $this->transitionChanges($log->action, $properties) !== []) {
                continue;
            }

            if (! $includeTechnical && in_array($key, self::TECHNICAL_KEYS, true)) {
                continue;
            }

            $details[] = ['key' => $key, 'label' => $this->labels->label($key), 'value' => $this->formatter()->format($key, $value, $action)];
        }

        return $details;
    }

    /**
     * @param  array<array-key, mixed>  $properties
     * @return AuditResult|null
     */
    private function result(array $properties): ?array
    {
        $result = $properties['verification_result'] ?? null;

        if (! is_string($result)) {
            return null;
        }

        return [
            'tone' => match ($result) {
                'verified' => 'success',
                'unreachable' => 'warning',
                default => 'danger',
            },
            'label' => AuditValueFormatter::VERIFICATION_RESULTS[$result] ?? $result,
        ];
    }

    /**
     * The reason of a failed operation, in plain Spanish, and its technical
     * part (Meta code and detail kept by older entries) for the technical section.
     *
     * @param  array<array-key, mixed>  $properties
     * @return array{0: string|null, 1: string|null}
     */
    private function error(AuditLog $log, array $properties): array
    {
        $reason = $properties['reason'] ?? null;

        if (! $this->isErrorKey($log, 'reason') || ! is_string($reason) || $reason === '') {
            return [null, null];
        }

        return MetaErrorCatalog::separateTechnicalDetail($reason);
    }

    /**
     * "reason" is the error of failed operations; elsewhere (an API token's
     * revocation, a contact review) it is an ordinary detail.
     */
    private function isErrorKey(AuditLog $log, string $key): bool
    {
        return $key === 'reason' && preg_match('/[._]failed$/', $log->action->value) === 1;
    }

    private function formatter(): AuditValueFormatter
    {
        return $this->formatter ??= new AuditValueFormatter($this->timezone());
    }

    private function timezone(): string
    {
        $timezone = $this->settings->get('general.timezone');

        return is_string($timezone) && $timezone !== '' ? $timezone : (string) config('app.timezone');
    }
}
