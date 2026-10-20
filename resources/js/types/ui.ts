export type Appearance = 'light' | 'dark' | 'system';
export type ResolvedAppearance = 'light' | 'dark';

export type AppVariant = 'header' | 'sidebar';

export type FlashToast = {
    type: 'success' | 'info' | 'warning' | 'error';
    message: string;
};

/**
 * Contextual help shown next to a form field.
 */
export type FieldHelpContent = {
    /** Heading of the help; defaults to the field label. */
    title?: string;
    /** What the field is for. */
    description: string;
    /** How to fill it in. */
    howTo?: string;
    /** A valid example value. */
    example?: string;
    /** Consequence worth knowing before saving. */
    warning?: string;
};
