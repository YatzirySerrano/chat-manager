<?php

namespace App\Support;

use App\Enums\InboundContactOutcome;
use App\Models\Connection;
use App\Models\Contact;
use App\Models\ContactAttribution;
use App\Models\ContactIdentity;
use App\Models\ContactReviewCase;

/**
 * Result of registering the sender of an inbound message: the contact the
 * conversation must be attached to and what happened to the directory.
 */
final readonly class InboundContactRegistration
{
    public function __construct(
        public Contact $contact,
        public ContactIdentity $identity,
        public Connection $connection,
        public InboundContactOutcome $outcome,
        public ?ContactAttribution $attribution = null,
        public ?ContactReviewCase $reviewCase = null,
    ) {}
}
