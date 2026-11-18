<?php

namespace App\Services\Conversations;

use App\Services\PlatformSettings;

/**
 * «Marcar que necesita información»: the catalog of missing information the
 * agent picks from (Configuración › Información faltante, platform setting
 * `missing_info`) and the texts built from what was picked: the reason
 * recorded on the readiness change and the message sent to the customer.
 */
class MissingInformation
{
    public const MAX_ITEMS = 30;

    public const ITEM_MAX_LENGTH = 80;

    public const INTRO_MAX_LENGTH = 300;

    /** Free text of «Otro». */
    public const OTHER_MIN_LENGTH = 5;

    public const OTHER_MAX_LENGTH = 200;

    /** Length of the recorded reason (conversation_events.note). */
    public const REASON_MAX_LENGTH = 500;

    public function __construct(private PlatformSettings $settings) {}

    /**
     * The items of the catalog, in the order the administrators gave them.
     *
     * @return list<string>
     */
    public function items(): array
    {
        $items = $this->settings->get('missing_info.items', []);

        return is_array($items) ? array_values(array_filter($items, 'is_string')) : [];
    }

    public function intro(): string
    {
        $intro = trim((string) $this->settings->get('missing_info.intro', ''));

        return $intro === '' ? $this->settings->defaults()['missing_info']['intro'] : $intro;
    }

    /**
     * The reason recorded on the event and in the audit log.
     *
     * @param  list<string>  $missing
     */
    public function reason(array $missing): string
    {
        return implode('; ', $missing);
    }

    /**
     * The message for the customer: the intro and one bullet per item.
     *
     * @param  list<string>  $missing
     */
    public function message(array $missing): string
    {
        return $this->intro()."\n".implode("\n", array_map(fn (string $item): string => '• '.$item, $missing));
    }

    /**
     * @return array{intro: string, items: list<string>}
     */
    public function describe(): array
    {
        return ['intro' => $this->intro(), 'items' => $this->items()];
    }
}
