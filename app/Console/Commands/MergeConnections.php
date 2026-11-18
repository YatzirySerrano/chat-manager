<?php

namespace App\Console\Commands;

use App\Actions\Connections\ConnectionConversations;
use App\Actions\Connections\DeleteConnectionPermanently;
use App\Actions\Connections\MoveConnectionConversations;
use App\Enums\Permission;
use App\Models\Connection;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

/**
 * Leaves every conversation of a channel under a single connection: moves
 * everything (live, imported and archived history) from the other
 * connections into the target (MoveConnectionConversations, so a contact
 * active on both sides keeps its most recent conversation active and the
 * other one is closed) and, with --purge, erases the emptied connections
 * for good. Runs as a Superadministrador and is audited like the screen.
 *
 *   php artisan connections:merge 7 --all --show-archived --purge
 *   php artisan connections:merge 7 --from=3 --from=12
 */
class MergeConnections extends Command
{
    protected $signature = 'connections:merge
        {target : ID de la conexión que se queda con todo}
        {--from=* : ID de una conexión a vaciar (se puede repetir)}
        {--all : Vaciar todas las demás conexiones del mismo canal, incluidas las eliminadas}
        {--show-archived : El historial archivado de Whaticket pasa a verse en la bandeja (cerradas)}
        {--purge : Eliminar definitivamente las conexiones que quedan vacías}
        {--user= : ID del Superadministrador que firma la operación (por defecto, el primero)}
        {--force : No pedir confirmación}';

    protected $description = 'Pasa todas las conversaciones a una sola conexión y, opcionalmente, elimina definitivamente las demás';

    public function handle(ConnectionConversations $list, MoveConnectionConversations $move, DeleteConnectionPermanently $delete): int
    {
        $target = Connection::query()->find((int) $this->argument('target'));

        if ($target === null) {
            $this->error('No existe la conexión de destino.');

            return self::FAILURE;
        }

        if ($target->isDeleted()) {
            $this->error("La conexión «{$target->name}» está eliminada: restáurala antes de usarla como destino.");

            return self::FAILURE;
        }

        $actor = $this->actor();

        if ($actor === null) {
            $this->error('No hay un Superadministrador con el que firmar la operación (usa --user=ID).');

            return self::FAILURE;
        }

        /** @var list<int> $from */
        $from = array_values(array_map('intval', (array) $this->option('from')));

        $sources = Connection::query()
            ->whereKeyNot($target->id)
            ->when($this->option('all'), fn (Builder $query) => $query->where('channel', $target->channel->value), fn (Builder $query) => $query->whereKey($from))
            ->orderBy('id')
            ->get();

        if ($sources->isEmpty()) {
            $this->warn('No hay conexiones que vaciar: indica --from=ID o --all.');

            return self::SUCCESS;
        }

        $this->table(['ID', 'Conexión', 'Canal', 'Estado', 'Conversaciones'], $sources->map(fn (Connection $source): array => [
            $source->id,
            $source->name,
            $source->channel->label(),
            $source->isDeleted() ? 'eliminada' : ($source->is_active ? 'activa' : 'inactiva'),
            $list->counts($source)['all'],
        ])->all());

        $this->line("Destino: #{$target->id} «{$target->name}»".($target->is_active ? '' : ' (inactiva: actívala para poder enviar)'));

        if ($this->option('purge')) {
            $this->warn('Después de moverlas, las conexiones de la tabla se eliminarán DEFINITIVAMENTE.');
        }

        if (! $this->option('force') && ! $this->confirm('¿Continuar?')) {
            return self::FAILURE;
        }

        $failed = false;

        foreach ($sources as $source) {
            try {
                $ids = $list->query($source, [])->pluck('conversations.id')->map(fn (mixed $id): int => (int) $id)->all();
                $result = $move->handle($source, $target, $ids, (bool) $this->option('show-archived'), $actor);
            } catch (ValidationException $exception) {
                $this->error("#{$source->id} «{$source->name}»: ".collect($exception->errors())->flatten()->first());
                $failed = true;

                continue;
            }

            $this->info("#{$source->id} «{$source->name}»: {$result['moved']} movidas, {$result['closed']} cerradas por duplicado activo, {$result['unarchived']} pasadas a la bandeja.");

            if ($this->option('purge')) {
                $left = $delete->start($source, $actor);
                $this->line($left === 0 ? '  Eliminada definitivamente.' : "  Quedaron {$left} conversaciones: se borran en segundo plano.");
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    private function actor(): ?User
    {
        $query = User::query()->whereHas('roles', fn (Builder $roles) => $roles->where('is_root', true))->orderBy('id');
        $actor = $this->option('user') !== null ? User::query()->find((int) $this->option('user')) : $query->first();

        if ($actor === null || ! $actor->holdsPermission(Permission::ManageConnections)) {
            return null;
        }

        if ($this->option('purge') && ! $actor->holdsPermission(Permission::PurgeConnections)) {
            return null;
        }

        return $actor;
    }
}
