<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Referral metadata sent by the provider with an inbound message (for Meta,
 * the `referral` object of a Click-to-WhatsApp message). Only fields the
 * provider actually sent are kept; nothing is inferred. Meta does not send the
 * campaign, the page or whether the ad ran on Facebook or Instagram.
 */
final readonly class ProviderReferral
{
    /**
     * @param  array<string, string>  $raw  The referral as received, scalar values only.
     */
    public function __construct(
        public ?string $sourceType,
        public ?string $sourceId,
        public ?string $sourceUrl,
        public ?string $headline,
        public ?string $body,
        public ?string $mediaType,
        public ?string $ctwaClid,
        public array $raw,
    ) {}

    /**
     * Build it from the provider's referral object. Returns null when the
     * object carries no usable field.
     *
     * @param  array<array-key, mixed>  $referral
     */
    public static function fromArray(array $referral): ?self
    {
        $raw = [];

        foreach ($referral as $key => $value) {
            if (is_string($key) && (is_string($value) || is_int($value) || is_float($value))) {
                $raw[Str::limit($key, 64, '')] = Str::limit((string) $value, 2048, '');
            }
        }

        $sourceUrl = self::text($referral['source_url'] ?? null, 2048);

        $instance = new self(
            sourceType: self::text($referral['source_type'] ?? null, 40),
            sourceId: self::text($referral['source_id'] ?? null, 128),
            sourceUrl: $sourceUrl !== null && preg_match('#^https?://#i', $sourceUrl) === 1 ? $sourceUrl : null,
            headline: self::text($referral['headline'] ?? null, 255),
            body: self::text($referral['body'] ?? null, 1000),
            mediaType: self::text($referral['media_type'] ?? null, 40),
            ctwaClid: self::text($referral['ctwa_clid'] ?? null, 255),
            raw: $raw,
        );

        return $instance->sourceType === null && $instance->sourceId === null && $instance->sourceUrl === null
            && $instance->ctwaClid === null && $instance->headline === null
            ? null
            : $instance;
    }

    private static function text(mixed $value, int $limit): ?string
    {
        if (! is_string($value) && ! is_int($value)) {
            return null;
        }

        $value = Str::squish(preg_replace('/[\p{Cc}\p{Cf}]+/u', ' ', (string) $value) ?? '');

        return $value === '' ? null : Str::limit($value, $limit, '');
    }
}
