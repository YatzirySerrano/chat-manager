<?php

namespace App\Console\Commands;

use App\Enums\ConnectionProvider;
use App\Enums\WhatsAppWebState;
use App\Models\Connection;
use App\Services\WhatsAppWeb\WhatsAppWebClient;
use App\Services\WhatsAppWeb\WhatsAppWebException;
use App\Services\WhatsAppWeb\WhatsAppWebSessions;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Keeps the stored state of every WhatsApp Web (QR) session equal to what
 * the Node service really has (scheduled every minute). Events normally do
 * that; this is the safety net for what events cannot say:
 *
 * - the service is down: sessions believed connected are marked with an
 *   error ("the service does not answer"), so nothing looks healthy only
 *   because it was a minute ago;
 * - the service came back (restart, deployment, reboot): each session's
 *   current state is read again;
 * - the service lost a session ChatManager believed linked: it is shown as
 *   requiring a new link;
 * - the service still holds a session of a connection that was deleted or
 *   does not exist: it is removed there (ChatManager decides which sessions
 *   exist).
 *
 * It never links, restores or sends anything by itself.
 */
class SyncWhatsAppWebSessions extends Command
{
    protected $signature = 'whatsapp-web:sync';

    protected $description = 'Reconcile the state of the WhatsApp Web (QR) sessions with the Node service';

    public function handle(WhatsAppWebClient $client, WhatsAppWebSessions $sessions): int
    {
        if (! $client->isConfigured()) {
            $this->line('WhatsApp Web (QR) no está configurado (falta WHATSAPP_WEB_SECRET): nada que sincronizar.');

            return self::SUCCESS;
        }

        $connections = Connection::query()->where('provider', ConnectionProvider::WhatsappWeb)->notDeleted()->orderBy('id')->get();

        if (! $sessions->serviceAvailable(fresh: true)) {
            $marked = 0;

            foreach ($connections as $connection) {
                $state = $connection->webSessionState();

                // A phone that stays linked: the session is not usable while the service is down.
                if ($state === WhatsAppWebState::Connected || $state === WhatsAppWebState::Reconnecting) {
                    $sessions->apply($connection, [
                        'state' => WhatsAppWebState::Error->value,
                        'last_error' => ['code' => 'service_unreachable', 'message' => null],
                    ], 'service_unreachable');
                    $marked++;
                }
            }

            if ($connections->isNotEmpty()) {
                Log::warning('The WhatsApp Web service does not answer.', ['sessions_marked' => $marked]);
            }

            $this->warn("El servicio de WhatsApp Web no responde. Sesiones marcadas con error: {$marked}.");

            return self::SUCCESS;
        }

        $synced = 0;

        foreach ($connections as $connection) {
            if ($sessions->sync($connection)) {
                $synced++;
            }
        }

        $removed = $this->removeOrphans($client, array_values(array_map('intval', $connections->modelKeys())));

        $this->info("Sesiones sincronizadas: {$synced}. Sesiones huérfanas eliminadas del servicio: {$removed}.");

        return self::SUCCESS;
    }

    /**
     * Sessions the service holds for connections ChatManager no longer has.
     *
     * @param  list<int>  $known
     */
    private function removeOrphans(WhatsAppWebClient $client, array $known): int
    {
        try {
            $status = $client->status();
        } catch (WhatsAppWebException) {
            return 0;
        }

        $removed = 0;

        foreach ((array) ($status['sessions'] ?? []) as $session) {
            $id = is_array($session) ? ($session['connection_id'] ?? null) : null;

            if (! is_int($id) || in_array($id, $known, true)) {
                continue;
            }

            try {
                $client->delete($id);
                $removed++;
            } catch (WhatsAppWebException) {
                // Tried again on the next run.
            }
        }

        return $removed;
    }
}
