<?php

namespace App\Actions\Contacts;

use App\Enums\ConnectionChannel;
use App\Models\Connection;
use App\Services\Meta\MetaInboundPayload;
use App\Support\InboundContactRegistration;
use Illuminate\Support\Facades\Log;

/**
 * Entry point for the future Meta webhook consumer: registers in the directory
 * every sender of an authenticated webhook body. Re-processing the same body
 * is harmless, so a failed delivery or job can simply be retried.
 */
class ProcessInboundContacts
{
    public function __construct(private RegisterInboundContact $registerInboundContact) {}

    /**
     * @param  array<array-key, mixed>  $payload
     * @return list<InboundContactRegistration>
     */
    public function handle(array $payload): array
    {
        $registrations = [];
        $connections = [];

        foreach (MetaInboundPayload::senders($payload) as ['phone_number_id' => $phoneNumberId, 'sender' => $sender]) {
            $connections[$phoneNumberId] ??= Connection::query()
                ->where('channel', ConnectionChannel::Whatsapp)
                ->where('phone_number_id', $phoneNumberId)
                ->first();

            if ($connections[$phoneNumberId] === null) {
                Log::warning('Inbound message ignored: no connection uses this phone number id.', [
                    'phone_number_id' => $phoneNumberId,
                    'message_id' => $sender->messageId,
                ]);

                continue;
            }

            $registrations[] = $this->registerInboundContact->handle($connections[$phoneNumberId], $sender);
        }

        return $registrations;
    }
}
