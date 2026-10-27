import { formatFileSize } from '@/lib/format';

/**
 * What both composers (customer replies and the team chat) say about file
 * uploads, with the codes the server answers (AttachmentRejected,
 * ServerUploadLimits).
 */

/** Error bag key that carries the code of a refused upload. */
export const UPLOAD_CODE_FIELD = 'upload_code';

/**
 * Causes that may go away by sending the same file again (a dropped
 * connection, a temporary server problem, a malformed upload). Any other
 * code (type, size, server limit) is a definitive refusal of that file.
 */
const RETRYABLE_UPLOAD_CODES = [
    'upload_interrupted',
    'server_upload_failure',
    'upload_invalid',
];

export function isRetryableUpload(
    errors: Record<string, string | undefined>,
): boolean {
    return RETRYABLE_UPLOAD_CODES.includes(errors[UPLOAD_CODE_FIELD] ?? '');
}

/**
 * Before uploading: the file is larger than this server's PHP accepts.
 * Present tense (nothing was sent) and never a rule of the channel.
 */
export function serverLimitNotice(
    size: number,
    max: number,
    subject = 'Este archivo',
): string {
    return `${subject} pesa ${formatFileSize(size)} y el servidor de Chat Manager acepta hasta ${formatFileSize(max)}; un administrador puede ampliar el límite.`;
}
