import type { AppColor } from './admin';
import type { TagOption } from './tags';
import type { RecordStateValue } from './ui';

export type ContactStatusValue = 'active' | 'archived';

export type ContactSourceValue =
    | 'manual'
    | 'whatsapp'
    | 'import'
    | 'web_chat'
    | 'messenger'
    | 'instagram';

export type ContactOriginTypeValue =
    | 'unknown'
    | 'direct'
    | 'website'
    | 'qr_code'
    | 'physical_ad'
    | 'facebook_ad'
    | 'instagram_ad'
    | 'ctwa_button'
    | 'meta_ad'
    | 'meta_post'
    | 'referral'
    | 'other';

export type AttributionReliabilityValue =
    | 'provider_confirmed'
    | 'manually_declared';

export type ContactEnumOption<T extends string = string> = {
    value: T;
    label: string;
};

export type ContactTag = TagOption;

export type ContactIdentitySummary = {
    id: number;
    channel: string;
    channel_label: string;
    external_id: string;
    display_name: string | null;
};

export type ContactOriginSummary = {
    origin_type: ContactOriginTypeValue;
    origin_label: string;
    reliability: AttributionReliabilityValue;
};

export type Contact = {
    id: number;
    name: string;
    profile_name: string | null;
    phone_e164: string | null;
    email: string | null;
    status: ContactStatusValue;
    is_deleted: boolean;
    deleted_at: string | null;
    source: ContactSourceValue;
    source_label: string;
    /** False for a provisional contact: someone who wrote in but nobody saved into the directory. */
    is_saved: boolean;
    saved_at: string | null;
    custom_fields: Record<string, string>;
    first_interaction_at: string | null;
    last_interaction_at: string | null;
    created_at: string | null;
    creator?: { id: number | null; name: string } | null;
    tags?: ContactTag[];
    identities?: ContactIdentitySummary[];
    origin?: ContactOriginSummary | null;
    /** What the viewer may do with this contact (resolved by the server). */
    can?: {
        view: boolean;
        update: boolean;
        delete: boolean;
        restore: boolean;
        /** Save this provisional contact into the directory. */
        save: boolean;
    };
};

/** A connection (number) through which the contact wrote to the company. */
export type ContactReceivingConnection = {
    id: number;
    name: string;
    channel_label: string;
    display_phone_number: string | null;
    first_interaction_at: string | null;
    last_interaction_at: string | null;
};

export type ContactSort = 'recent' | 'name' | 'newest';

export type ContactFilters = {
    search: string;
    record: RecordStateValue;
    tags: number[];
    source: ContactSourceValue | null;
    origin: ContactOriginTypeValue | null;
    sort: ContactSort;
    /** "Incluir no guardados": also list provisional contacts. */
    include_provisional: boolean;
};

export type ContactAttribution = {
    id: number;
    origin_type: ContactOriginTypeValue;
    origin_label: string;
    reliability: AttributionReliabilityValue;
    reliability_label: string;
    provider: string | null;
    ad_platform: string | null;
    source_id: string | null;
    source_type: string | null;
    source_url: string | null;
    headline: string | null;
    body: string | null;
    campaign_id: string | null;
    campaign_name: string | null;
    page_name: string | null;
    media_type: string | null;
    captured_at: string;
    recorded_by?: { id: number; name: string } | null;
};

export type ContactNote = {
    id: number;
    body: string;
    author: { id: number | null; name: string } | null;
    created_at: string | null;
};

export type ContactConversation = {
    id: number;
    status: 'pending' | 'open' | 'closed';
    status_label: string;
    connection: {
        id: number;
        name: string;
        display_phone_number: string | null;
    } | null;
    department: {
        id: number;
        name: string;
        color: AppColor;
        is_active: boolean;
    } | null;
    assignee: { id: number | null; name: string } | null;
    created_at: string | null;
    last_message_at: string | null;
    closed_at: string | null;
};

export type DuplicateContact = {
    id: number;
    name: string;
    phone_e164: string | null;
    email: string | null;
    status: ContactStatusValue;
    /** False when the match is a provisional contact that can be saved instead. */
    is_saved: boolean;
    matches: ('phone' | 'email')[];
};

export type DuplicateCheck = {
    contacts: DuplicateContact[];
};

export type ContactReviewStatusValue = 'open' | 'resolved';

export type ContactReviewResolutionValue =
    | 'distinct_people'
    | 'identity_linked'
    | 'duplicate_archived';

export type ContactReviewSummary = {
    id: number;
    name: string;
    phone_e164: string | null;
    email: string | null;
    profile_name: string | null;
    status: ContactStatusValue;
    source: ContactSourceValue;
    source_label: string;
    whatsapp_ids: string[];
    created_at: string | null;
};

export type ContactReviewCase = {
    id: number;
    reason: 'ambiguous_identity_match' | 'possible_duplicate';
    reason_label: string;
    status: ContactReviewStatusValue;
    resolution: ContactReviewResolutionValue | null;
    resolution_label: string | null;
    resolved_contact_id: number | null;
    resolved_by?: string | null;
    resolved_at: string | null;
    created_at: string | null;
    contact: ContactReviewSummary;
    candidates: ContactReviewSummary[];
};
