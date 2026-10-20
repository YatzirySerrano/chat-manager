import type { ColorSwatch } from './admin';
import type { TagOption } from './tags';

export type ContactStatusValue = 'active' | 'archived';

export type ContactSourceValue = 'manual' | 'whatsapp' | 'import';

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
    archived_at: string | null;
    source: ContactSourceValue;
    source_label: string;
    custom_fields: Record<string, string>;
    first_interaction_at: string | null;
    last_interaction_at: string | null;
    created_at: string | null;
    creator?: { id: number; name: string } | null;
    tags?: ContactTag[];
    identities?: ContactIdentitySummary[];
    origin?: ContactOriginSummary | null;
};

export type ContactSort = 'recent' | 'name' | 'newest';

export type ContactFilters = {
    search: string;
    status: ContactStatusValue | 'all';
    tags: number[];
    source: ContactSourceValue | null;
    origin: ContactOriginTypeValue | null;
    sort: ContactSort;
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
    author: { id: number; name: string } | null;
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
        color: ColorSwatch;
        is_active: boolean;
    } | null;
    assignee: { id: number; name: string } | null;
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
