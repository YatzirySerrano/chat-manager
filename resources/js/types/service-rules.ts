import type { AppColor } from './admin';
import type { RecordStateValue } from './ui';

/** Rule keys mirrored from App\Services\ServiceRules::KEYS. */
export type ServiceRuleKey =
    | 'sla.first_response_minutes'
    | 'sla.response_minutes'
    | 'sla.resolution_minutes'
    | 'sla.warning_percent'
    | 'sla.business_hours_only'
    | 'intake.confirmation_required'
    | 'queue.min_wait_minutes'
    | 'queue.chronological_order';

/** Where a rule value comes from; a category wins over a department, which wins over the platform. */
export type ServiceRuleLevel = 'platform' | 'department' | 'category';

/** Minutes, a percentage or a switch; null = no objective (platform) or inherit (overrides). */
export type ServiceRuleValue = number | boolean | null;

/** One rule as ServiceRules::describe() sends it for the level being edited. */
export type ServiceRuleDescription = {
    /** Effective value at this level. */
    value: ServiceRuleValue;
    source: ServiceRuleLevel;
    /** What applies when this level does not set the rule (null at the platform level). */
    inherited_value: ServiceRuleValue;
    inherited_source: ServiceRuleLevel | null;
    label: string;
    unit: string | null;
    min: number | null;
    max: number | null;
    type: 'integer' | 'boolean';
    /** A nullable rule left empty at the platform means "no objective". */
    nullable: boolean;
    /** Whether this level may set the rule at all. */
    overridable: boolean;
};

export type ServiceRuleSet = Record<ServiceRuleKey, ServiceRuleDescription>;

/** Values being edited, by rule key. */
export type ServiceRuleValues = Partial<
    Record<ServiceRuleKey, ServiceRuleValue>
>;

/** Rules grouped like the settings, as the forms hold and send them. */
export type ServiceRuleGroups = {
    sla: Record<string, ServiceRuleValue>;
    intake: Record<string, ServiceRuleValue>;
    queue: Record<string, ServiceRuleValue>;
};

/** Entry of the active catalog for select inputs (ConversationCategory::options()). */
export type ConversationCategoryOption = {
    id: number;
    name: string;
    color: AppColor;
};

export type ConversationCategorySummary = ConversationCategoryOption & {
    description: string | null;
    is_active: boolean;
    is_deleted: boolean;
    conversations_count?: number;
    /** Rules of the category for its editor. */
    rules: ServiceRuleSet;
    /** How many rules the category sets on its own. */
    overrides_count: number;
    created_at: string | null;
    can: { update: boolean; delete: boolean; restore: boolean };
};

export type ConversationCategoryFilters = {
    search: string;
    record: RecordStateValue;
};

/** Request readiness mirrored from App\Enums\ConversationReadiness. */
export type ConversationReadinessValue = 'ready' | 'needs_info';
