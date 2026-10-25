<?php

namespace App\Console\Commands;

use App\Actions\Conversations\RetireOnHoldConversation;
use App\Enums\ConversationStatus;
use App\Models\Conversation;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('conversations:retire-on-hold
    {--dry-run : Only list what would change (default)}
    {--apply : Migrate the conversations (writes)}
    {--chunk=200 : Conversations read per batch}')]
#[Description('Move the conversations still "en espera" (retired state) back to attention or to their queue; dry-run unless --apply')]
class RetireOnHoldConversations extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(RetireOnHoldConversation $retire): int
    {
        $apply = (bool) $this->option('apply');

        if ($apply && $this->option('dry-run')) {
            $this->components->error('Usa --dry-run o --apply, no ambos.');

            return self::FAILURE;
        }

        $chunk = max(1, (int) $this->option('chunk'));
        $total = Conversation::query()->where('status', ConversationStatus::OnHold)->count();

        if ($total === 0) {
            $this->components->info('No hay conversaciones en espera: no hay nada que migrar.');

            return self::SUCCESS;
        }

        $this->components->info($apply
            ? "Migrando {$total} conversaciones en espera."
            : "Simulación: {$total} conversaciones en espera cambiarían así (nada se escribe; usa --apply para migrarlas).");

        $rows = [];
        $failed = 0;
        $lastId = 0;

        do {
            $batch = Conversation::query()
                ->where('status', ConversationStatus::OnHold)
                ->where('id', '>', $lastId)
                ->with(['department:id,name,is_active', 'assignee:id,name'])
                ->orderBy('id')
                ->limit($chunk)
                ->get();

            foreach ($batch as $conversation) {
                $lastId = $conversation->id;

                try {
                    $plan = $apply ? $retire->handle($conversation) : $retire->plan($conversation);
                } catch (Throwable $exception) {
                    report($exception);
                    $failed++;
                    $rows[] = [$conversation->id, $conversation->department->name ?? 'Sin departamento', $conversation->assignee->name ?? '—', 'error', 'No se pudo migrar; vuelve a ejecutar el comando.'];

                    continue;
                }

                if ($plan === null) {
                    continue;
                }

                $rows[] = [
                    $conversation->id,
                    $conversation->department->name ?? 'Sin departamento',
                    $conversation->assignee->name ?? '—',
                    $plan['target'] === RetireOnHoldConversation::TARGET_OPEN ? 'En atención' : 'Pendiente (cola)',
                    $plan['reason'],
                ];
            }
        } while ($batch->count() === $chunk);

        $this->table(['Conversación', 'Departamento', 'Agente', 'Nuevo estado', 'Motivo'], $rows);

        if (! $apply) {
            return self::SUCCESS;
        }

        if ($failed > 0) {
            $this->components->warn("{$failed} conversaciones no se migraron; vuelve a ejecutar el comando.");

            return self::FAILURE;
        }

        $this->components->info('Listo. Ejecuta php artisan analytics:rebuild para recalcular los reportes.');

        return self::SUCCESS;
    }
}
