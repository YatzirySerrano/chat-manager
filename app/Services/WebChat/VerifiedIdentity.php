<?php

namespace App\Services\WebChat;

/**
 * A visitor identity the host site signed with the widget's identity secret
 * and VisitorIdentity verified. visitorId is the contact identity of the
 * person on this connection ('wi:…').
 */
final readonly class VerifiedIdentity
{
    public function __construct(
        public string $externalId,
        public string $visitorId,
        public ?string $name = null,
        public ?string $email = null,
    ) {}
}
