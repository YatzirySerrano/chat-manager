<?php

namespace App\Services\Meta;

use App\Jobs\SimulateMessageStatuses;
use App\Models\Message;
use Illuminate\Support\Str;

/**
 * SIMULACIÓN — local development only. Nothing is sent to WhatsApp: the
 * message gets a "sim." provider id and is marked as simulated in its
 * metadata. When enabled (whatsapp.simulation.status_progression) it then
 * reports sent → delivered (→ read) through the same status processing
 * Meta's webhooks use. It refuses to run in production.
 */
class SimulatedWhatsAppTransport implements WhatsAppTransport
{
    public function name(): string
    {
        return 'simulated';
    }

    public function send(Message $message): SendResult
    {
        if (app()->isProduction()) {
            throw new WhatsAppTransportUnavailable('El transporte simulado de WhatsApp no puede usarse en producción.');
        }

        $id = 'sim.'.Str::lower((string) Str::ulid());

        if (config('whatsapp.simulation.status_progression')) {
            SimulateMessageStatuses::dispatch($message->id, $id)->delay(now()->addSeconds(2))->afterCommit();
        }

        return new SendResult($id, simulated: true);
    }
}
