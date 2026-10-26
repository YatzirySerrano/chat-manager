<?php

namespace App\Actions\Templates;

use App\Enums\MessageTemplateStatus;
use App\Models\Connection;
use App\Models\MessageTemplate;
use App\Services\Meta\ConnectionMonitor;
use App\Services\Meta\MetaGraphClient;
use App\Services\Meta\MetaGraphException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Copies the templates of a connection's WhatsApp Business Account from
 * Meta (GET /{waba-id}/message_templates, every page). Status, category,
 * quality and components are written exactly as Meta reports them; a
 * template Meta no longer returns is logically deleted, and one that comes
 * back is restored. Nothing is marked approved without Meta saying so.
 *
 * Templates are created and edited in WhatsApp Manager (Meta Business
 * Suite); this application synchronizes them.
 */
class SyncMessageTemplates
{
    private const FIELDS = 'id,name,language,status,category,components,quality_score,rejected_reason,parameter_format';

    public function __construct(
        private MetaGraphClient $graph,
        private ConnectionMonitor $monitor,
    ) {}

    /**
     * @return array{created: int, updated: int, removed: int, total: int}
     *
     * @throws MetaGraphException
     */
    public function handle(Connection $connection): array
    {
        if (blank($connection->business_account_id) || blank($connection->access_token)) {
            throw new MetaGraphException('Esta conexión está incompleta: un administrador debe completar sus datos de Meta en Conexiones antes de actualizar plantillas.', rejected: true, kind: MetaGraphException::INVALID_REQUEST);
        }

        try {
            ['templates' => $remote, 'complete' => $complete] = $this->fetch($connection);
        } catch (MetaGraphException $exception) {
            if ($exception->kind === MetaGraphException::TOKEN_INVALID) {
                $this->monitor->credentialsRejected($connection, $exception->getMessage());
            }

            throw $exception;
        }

        return DB::transaction(function () use ($connection, $remote, $complete): array {
            $created = $updated = 0;
            $seen = [];
            $now = now();

            foreach ($remote as $item) {
                $seen[] = $item['meta_template_id'];
                $template = MessageTemplate::query()
                    ->where('connection_id', $connection->id)
                    ->where('meta_template_id', $item['meta_template_id'])
                    ->lockForUpdate()
                    ->first() ?? new MessageTemplate(['connection_id' => $connection->id, 'meta_template_id' => $item['meta_template_id']]);

                $statusChanged = ! $template->exists || $template->status->value !== $item['status'];

                $template->forceFill([
                    ...$item,
                    'business_account_id' => (string) $connection->business_account_id,
                    'deleted_at' => null,
                    'last_synced_at' => $now,
                    ...($statusChanged ? ['status_updated_at' => $now] : []),
                ]);

                if (! $template->exists) {
                    $created++;
                } elseif ($template->isDirty(array_keys($item)) || $template->isDirty('deleted_at')) {
                    $updated++;
                }

                $template->save();
            }

            // Only a complete listing proves a template is gone: when the page
            // limit cut it short, nothing is retired.
            $removed = ! $complete ? 0 : MessageTemplate::query()
                ->where('connection_id', $connection->id)
                ->whereNull('deleted_at')
                ->when($seen !== [], fn ($query) => $query->whereNotIn('meta_template_id', $seen))
                ->update(['deleted_at' => $now, 'last_synced_at' => $now, 'updated_at' => $now]);

            return ['created' => $created, 'updated' => $updated, 'removed' => $removed, 'total' => count($remote)];
        });
    }

    /**
     * Every page of the business account's templates, and whether the
     * listing is complete (false when whatsapp.templates.max_pages cut it).
     *
     * @return array{templates: list<array{meta_template_id: string, name: string, language: string, category: string|null, status: string, quality_score: string|null, parameter_format: string|null, components: list<array<string, mixed>>, rejected_reason: string|null}>, complete: bool}
     *
     * @throws MetaGraphException
     */
    private function fetch(Connection $connection): array
    {
        $templates = [];
        $after = null;
        $pages = 0;
        $maxPages = max(1, (int) config('whatsapp.templates.max_pages', 50));

        do {
            $page = $this->graph->get((string) $connection->access_token, rawurlencode((string) $connection->business_account_id).'/message_templates', array_filter([
                'fields' => self::FIELDS,
                'limit' => (int) config('whatsapp.templates.page_size', 100),
                'after' => $after,
            ], fn (mixed $value): bool => $value !== null), 'la actualización de plantillas desde Meta');

            foreach (is_array($page['data'] ?? null) ? $page['data'] : [] as $item) {
                $parsed = is_array($item) ? $this->parse($item) : null;

                if ($parsed !== null) {
                    $templates[] = $parsed;
                }
            }

            $next = $page['paging']['next'] ?? null;
            $cursor = $page['paging']['cursors']['after'] ?? null;
            $after = is_string($next) && is_string($cursor) && $cursor !== '' ? $cursor : null;
            $pages++;
        } while ($after !== null && $pages < $maxPages);

        if ($after !== null) {
            Log::warning('Template synchronization stopped at the page limit; templates missing from the listing were not retired.', ['connection_id' => $connection->id, 'pages' => $pages]);
        }

        return ['templates' => $templates, 'complete' => $after === null];
    }

    /**
     * @param  array<array-key, mixed>  $item
     * @return array{meta_template_id: string, name: string, language: string, category: string|null, status: string, quality_score: string|null, parameter_format: string|null, components: list<array<string, mixed>>, rejected_reason: string|null}|null
     */
    private function parse(array $item): ?array
    {
        $id = isset($item['id']) && is_scalar($item['id']) ? (string) $item['id'] : '';
        $name = is_string($item['name'] ?? null) ? $item['name'] : '';
        $language = is_string($item['language'] ?? null) ? $item['language'] : '';

        if ($id === '' || $name === '' || $language === '') {
            return null;
        }

        $quality = $item['quality_score']['score'] ?? $item['quality_score'] ?? null;
        $reason = is_string($item['rejected_reason'] ?? null) && ! in_array($item['rejected_reason'], ['NONE', ''], true) ? $item['rejected_reason'] : null;

        return [
            'meta_template_id' => Str::limit($id, 64, ''),
            'name' => Str::limit($name, 512, ''),
            'language' => Str::limit($language, 15, ''),
            'category' => is_string($item['category'] ?? null) ? Str::limit(strtoupper($item['category']), 30, '') : null,
            'status' => MessageTemplateStatus::fromMeta($item['status'] ?? null)->value,
            'quality_score' => is_string($quality) ? Str::limit($quality, 20, '') : null,
            'parameter_format' => is_string($item['parameter_format'] ?? null) ? Str::limit(strtoupper($item['parameter_format']), 20, '') : null,
            'components' => array_values(array_filter(is_array($item['components'] ?? null) ? $item['components'] : [], 'is_array')),
            'rejected_reason' => $reason === null ? null : Str::limit($reason, 255, ''),
        ];
    }
}
