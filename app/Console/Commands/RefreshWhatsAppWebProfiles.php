<?php

namespace App\Console\Commands;

use App\Enums\ConnectionChannel;
use App\Enums\WhatsAppWebState;
use App\Jobs\RefreshWhatsAppWebContactProfile;
use App\Models\Connection;
use App\Models\ContactIdentity;
use App\Services\WhatsAppWeb\WhatsAppWebContactProfiles;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

/**
 * Reads the WhatsApp profile (picture, "about") of the contacts that never
 * got one or whose copy is stale, through a connected WhatsApp Web (QR)
 * session of each contact. A refresh normally follows an inbound message or
 * opening the conversation; this fills in the contacts that already existed
 * (or stopped writing) without waiting for them.
 *
 * Bounded and paced: at most --limit contacts per run, the jobs spread a
 * few seconds apart on the media queue, so WhatsApp is never flooded.
 */
class RefreshWhatsAppWebProfiles extends Command
{
    protected $signature = 'whatsapp-web:refresh-profiles
        {--limit=60 : Contacts queued per run}
        {--spacing=4 : Seconds between two queued reads}
        {--days=90 : Only contacts that interacted in the last N days (0: all)}';

    protected $description = 'Queue a WhatsApp profile (picture) refresh for contacts of WhatsApp Web connections that lack one or have a stale one';

    public function handle(WhatsAppWebContactProfiles $profiles): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $spacing = max(0, (int) $this->option('spacing'));
        $days = max(0, (int) $this->option('days'));

        $connected = Connection::query()
            ->notDeleted()
            ->get()
            ->filter(fn (Connection $connection): bool => $connection->isWhatsAppWeb()
                && $connection->webSessionState() === WhatsAppWebState::Connected);

        if ($connected->isEmpty()) {
            $this->line('No hay sesiones de WhatsApp Web conectadas: nada que actualizar.');

            return self::SUCCESS;
        }

        $connectionIds = $connected->modelKeys();
        $hasPicture = now()->subHours(WhatsAppWebContactProfiles::STALE_AFTER_HOURS);
        $noPicture = now()->subHours(WhatsAppWebContactProfiles::STALE_WITHOUT_PICTURE_HOURS);

        $identities = ContactIdentity::query()
            ->where('channel', ConnectionChannel::Whatsapp->value)
            ->whereHas('contact.connections', function (Builder $query) use ($connectionIds, $days): void {
                $query->whereIn('connections.id', $connectionIds);

                if ($days > 0) {
                    $query->where('connection_contact.last_interaction_at', '>=', now()->subDays($days));
                }
            })
            ->where(fn (Builder $stale) => $stale
                ->whereNull('profile_fetched_at')
                ->orWhere(fn (Builder $without) => $without->whereNull('avatar_path')->where('profile_fetched_at', '<', $noPicture))
                ->orWhere(fn (Builder $with) => $with->whereNotNull('avatar_path')->where('profile_fetched_at', '<', $hasPicture)))
            // Never read first, then the oldest reads.
            ->orderByRaw('profile_fetched_at is not null')
            ->orderBy('profile_fetched_at')
            ->limit($limit)
            ->with('contact')
            ->get();

        $queued = 0;

        foreach ($identities as $identity) {
            $connection = $identity->contact === null ? null : $profiles->connectionFor($identity->contact);

            if ($connection === null || ! in_array($connection->id, $connectionIds, true)) {
                continue;
            }

            RefreshWhatsAppWebContactProfile::dispatch($identity->id, $connection->id)
                ->delay(now()->addSeconds($queued * $spacing));
            $queued++;
        }

        $this->info("Perfiles de WhatsApp en cola: {$queued}");

        return self::SUCCESS;
    }
}
