<?php

namespace App\Services\Meta;

use App\Support\InboundMessage;
use Carbon\CarbonImmutable;

/**
 * One item of the "messaging" array of a Messenger (object "page") or
 * Instagram (object "instagram") webhook, already validated:
 *
 * - message: a customer message ($message);
 * - echo: a message the account sent ($mids, the customer is the recipient);
 * - delivery: Messenger delivery receipt ($mids when Meta lists them, and/or $watermark);
 * - read: Messenger read watermark ($watermark) or Instagram seen ($mids);
 * - policy: Messenger messaging_policy_enforcement notice about the page
 *   ($ignoredReason holds the action, e.g. "block", and $policyReason Meta's text);
 * - ignored: anything this application does not process (reactions, optins…).
 */
final readonly class MetaMessagingEvent
{
    public const MESSAGE = 'message';

    public const ECHO = 'echo';

    public const DELIVERY = 'delivery';

    public const READ = 'read';

    public const POLICY = 'policy';

    public const IGNORED = 'ignored';

    /**
     * @param  list<string>  $mids  Normalized provider message ids.
     */
    public function __construct(
        public string $kind,
        public string $accountId,
        public ?string $customerId,
        public CarbonImmutable $occurredAt,
        public ?InboundMessage $message = null,
        public array $mids = [],
        public ?CarbonImmutable $watermark = null,
        public ?string $ignoredReason = null,
        public ?string $policyReason = null,
    ) {}
}
