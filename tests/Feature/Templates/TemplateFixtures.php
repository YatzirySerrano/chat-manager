<?php

namespace Tests\Feature\Templates;

use App\Models\Connection;
use App\Models\MessageTemplate;

/**
 * Templates as Meta's GET /{waba-id}/message_templates returns them.
 */
final class TemplateFixtures
{
    /**
     * @return array<string, mixed>
     */
    public static function orderUpdate(string $id = '564321987654321', string $status = 'APPROVED'): array
    {
        return [
            'id' => $id,
            'name' => 'order_update',
            'language' => 'es_MX',
            'status' => $status,
            'category' => 'UTILITY',
            'parameter_format' => 'POSITIONAL',
            'quality_score' => ['score' => 'GREEN'],
            'components' => [
                ['type' => 'HEADER', 'format' => 'TEXT', 'text' => 'Pedido {{1}}', 'example' => ['header_text' => ['A-100']]],
                ['type' => 'BODY', 'text' => 'Hola {{1}}, tu pedido llegará el {{2}}.', 'example' => ['body_text' => [['Ana', 'lunes']]]],
                ['type' => 'FOOTER', 'text' => 'Gracias por tu compra'],
                ['type' => 'BUTTONS', 'buttons' => [
                    ['type' => 'URL', 'text' => 'Rastrear', 'url' => 'https://example.com/track/{{1}}', 'example' => ['https://example.com/track/A-100']],
                    ['type' => 'QUICK_REPLY', 'text' => 'Hablar con un agente'],
                ]],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public static function stored(Connection $connection, array $attributes = []): MessageTemplate
    {
        $remote = self::orderUpdate();

        return MessageTemplate::query()->create([
            'connection_id' => $connection->id,
            'business_account_id' => (string) $connection->business_account_id,
            'meta_template_id' => (string) fake()->unique()->numerify('5###############'),
            'name' => $remote['name'],
            'language' => $remote['language'],
            'category' => $remote['category'],
            'status' => 'APPROVED',
            'parameter_format' => 'POSITIONAL',
            'components' => $remote['components'],
            'last_synced_at' => now(),
            ...$attributes,
        ]);
    }
}
