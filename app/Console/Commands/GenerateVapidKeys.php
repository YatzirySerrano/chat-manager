<?php

namespace App\Console\Commands;

use App\Notifications\Push\Vapid;
use Illuminate\Console\Command;

/**
 * Prints a new VAPID key pair for Web Push. It never writes .env: the
 * operator copies the lines (changing the keys invalidates every existing
 * browser subscription).
 */
class GenerateVapidKeys extends Command
{
    protected $signature = 'webpush:vapid';

    protected $description = 'Generate a VAPID key pair for Web Push and print the .env lines';

    public function handle(): int
    {
        $keys = Vapid::generateKeys();

        $this->line("VAPID_PUBLIC_KEY={$keys['public_key']}");
        $this->line("VAPID_PRIVATE_KEY={$keys['private_key']}");
        $this->newLine();
        $this->comment('Copia estas líneas en .env junto con VAPID_SUBJECT (mailto: o URL https). La clave privada es secreta. Si cambias las claves, cada navegador tendrá que volver a activar las notificaciones push.');

        return self::SUCCESS;
    }
}
