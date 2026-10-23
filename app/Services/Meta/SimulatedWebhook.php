<?php

namespace App\Services\Meta;

use App\Enums\AttachmentStatus;
use App\Models\MessageAttachment;
use Illuminate\Support\Str;

/**
 * SIMULACIÓN — builds webhook bodies in Meta's documented WhatsApp Cloud
 * API format for local development (chat-manager:simulate-inbound) so they
 * go through the very same processing as real deliveries. Media ids are
 * fictitious ("sim." prefix): the download job marks them unavailable, or a
 * local sample file is attached through the same validation as a real
 * download (attachLocalFile).
 */
final class SimulatedWebhook
{
    /**
     * Message types the simulator can produce.
     *
     * @var list<string>
     */
    public const TYPES = ['text', 'image', 'document', 'audio', 'video', 'sticker', 'location', 'contacts', 'interactive', 'button', 'reaction', 'unsupported'];

    /**
     * Error codes a simulated "failed" status can carry, with Meta's title.
     * 131047 is the rejection of a free-form message outside the 24-hour
     * customer service window.
     *
     * @var array<int, string>
     */
    public const ERROR_CODES = [
        131026 => 'Message undeliverable',
        131047 => 'Re-engagement message',
        131049 => 'This message was not delivered to maintain healthy ecosystem engagement',
        131050 => 'User has stopped marketing messages',
        131056 => 'Pair rate limit hit',
        130429 => 'Rate limit hit',
        131000 => 'Something went wrong',
        131031 => 'Business Account locked',
    ];

    public const DEFAULT_ERROR_CODE = 131026;

    /**
     * @param  array<string, string>|null  $referral
     * @param  array<string, mixed>|null  $context
     * @return array<string, mixed>
     */
    public static function message(string $phoneNumberId, string $businessAccountId, string $waId, string $profileName, string $messageId, string $type, string $text, int $timestamp, ?array $referral = null, ?array $context = null, ?string $fileName = null): array
    {
        $message = [
            'from' => $waId,
            'id' => $messageId,
            'timestamp' => (string) $timestamp,
            'type' => $type,
            ...self::content($type, $text, $fileName),
        ];

        if ($referral !== null) {
            $message['referral'] = $referral;
        }

        if ($context !== null) {
            $message['context'] = $context;
        }

        return self::envelope($phoneNumberId, $businessAccountId, [
            'contacts' => [['profile' => ['name' => $profileName], 'wa_id' => $waId]],
            'messages' => [$message],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function status(string $phoneNumberId, string $businessAccountId, string $wamid, string $status, int $timestamp, ?string $callbackData = null, int $errorCode = self::DEFAULT_ERROR_CODE): array
    {
        $entry = [
            'id' => $wamid,
            'status' => $status,
            'timestamp' => (string) $timestamp,
            'recipient_id' => '5215500000100',
        ];

        if ($callbackData !== null) {
            $entry['biz_opaque_callback_data'] = $callbackData;
        }

        if ($status === 'failed') {
            $entry['errors'] = [['code' => $errorCode, 'title' => (self::ERROR_CODES[$errorCode] ?? 'Error').' (simulación)']];
        }

        return self::envelope($phoneNumberId, $businessAccountId, ['statuses' => [$entry]]);
    }

    /**
     * Store a local sample file as the simulated attachment, through the
     * same validation (real type, size) as a download from Meta.
     *
     * @throws MediaRejected
     */
    public static function attachLocalFile(MessageAttachment $attachment, string $path, MediaStorage $storage): MessageAttachment
    {
        $stored = $storage->store($path, $attachment->message->type->value);

        $attachment->forceFill([
            ...$stored,
            'file_name' => $attachment->file_name ?? MediaFileName::sanitize(basename($path)),
            'status' => AttachmentStatus::Available,
            'error_code' => null,
            'downloaded_at' => now(),
        ])->save();

        return $attachment;
    }

    /**
     * @return array<string, mixed>
     */
    private static function content(string $type, string $text, ?string $fileName): array
    {
        $mediaId = 'sim.'.Str::lower((string) Str::ulid());

        return match ($type) {
            'image' => ['image' => ['id' => $mediaId, 'mime_type' => 'image/jpeg', 'caption' => $text]],
            'document' => ['document' => ['id' => $mediaId, 'mime_type' => 'application/pdf', 'filename' => $fileName ?? 'documento-simulado.pdf', 'caption' => $text]],
            'audio' => ['audio' => ['id' => $mediaId, 'mime_type' => 'audio/ogg; codecs=opus', 'voice' => true]],
            'video' => ['video' => ['id' => $mediaId, 'mime_type' => 'video/mp4', 'caption' => $text]],
            'sticker' => ['sticker' => ['id' => $mediaId, 'mime_type' => 'image/webp', 'animated' => false]],
            'location' => ['location' => ['latitude' => 19.4326, 'longitude' => -99.1332, 'name' => $text, 'address' => 'Ciudad de México (simulación)']],
            'contacts' => ['contacts' => [['name' => ['formatted_name' => $text, 'first_name' => $text], 'phones' => [['phone' => '+52 55 0000 0199', 'type' => 'CELL']]]]],
            'interactive' => ['interactive' => ['type' => 'button_reply', 'button_reply' => ['id' => 'sim-option', 'title' => $text]]],
            'button' => ['button' => ['payload' => 'sim-payload', 'text' => $text]],
            'reaction' => ['reaction' => ['message_id' => 'wamid.sim.reacted', 'emoji' => '👍']],
            'unsupported' => ['errors' => [['code' => 131051, 'title' => 'Message type unknown', 'message' => 'Message type unknown']]],
            default => ['text' => ['body' => $text]],
        };
    }

    /**
     * @param  array<string, mixed>  $value
     * @return array<string, mixed>
     */
    private static function envelope(string $phoneNumberId, string $businessAccountId, array $value): array
    {
        return [
            'object' => 'whatsapp_business_account',
            'entry' => [[
                'id' => $businessAccountId,
                'changes' => [[
                    'field' => 'messages',
                    'value' => [
                        'messaging_product' => 'whatsapp',
                        'metadata' => ['display_phone_number' => '5215500000100', 'phone_number_id' => $phoneNumberId],
                        ...$value,
                    ],
                ]],
            ]],
        ];
    }
}
