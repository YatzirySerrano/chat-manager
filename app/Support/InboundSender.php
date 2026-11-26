<?php

namespace App\Support;

use App\Enums\ConnectionChannel;
use App\Enums\ConnectionProvider;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * The sender of an inbound message, already extracted and validated from the
 * provider's payload. It is independent of the HTTP request, so the directory
 * can be fed by a webhook consumer, a queued job or a test alike.
 */
final readonly class InboundSender
{
    /**
     * Provider user ids: the WhatsApp `wa_id` (digits), allowing the letters
     * and separators of business-scoped ids as well.
     */
    private const EXTERNAL_ID_PATTERN = '/^[A-Za-z0-9._:-]{1,64}$/';

    public ?string $profileName;

    /**
     * The phone (E.164) the provider resolved for a sender identified only by
     * a privacy id (WhatsApp LID), when it knows it; null otherwise.
     */
    public ?string $phone;

    /** When the provider says the message was sent, in the application timezone. */
    public CarbonImmutable $occurredAt;

    /**
     * @throws InvalidArgumentException when the identity or the message id is unusable.
     */
    public function __construct(
        public ConnectionChannel $channel,
        public ConnectionProvider $provider,
        public string $externalId,
        ?string $profileName,
        public string $messageId,
        CarbonImmutable $occurredAt,
        public ?ProviderReferral $referral = null,
        ?string $phone = null,
    ) {
        if (preg_match(self::EXTERNAL_ID_PATTERN, $externalId) !== 1) {
            throw new InvalidArgumentException('The sender identity is missing or malformed.');
        }

        if ($messageId === '' || strlen($messageId) > 128) {
            throw new InvalidArgumentException('The provider message id is missing or too long.');
        }

        $this->profileName = self::cleanName($profileName);
        $this->phone = $phone !== null && preg_match('/^\d{5,20}$/', $phone) === 1 ? PhoneNumber::normalize('+'.$phone) : null;
        $this->occurredAt = $occurredAt->setTimezone(date_default_timezone_get());
    }

    /**
     * Profile names are free text chosen by the person: markup and control
     * characters are removed, like names typed in the interface.
     */
    private static function cleanName(?string $name): ?string
    {
        if ($name === null) {
            return null;
        }

        $name = Str::squish(preg_replace('/[\p{Cc}\p{Cf}]+/u', ' ', strip_tags($name)) ?? '');

        return $name === '' ? null : Str::limit($name, 255, '');
    }
}
