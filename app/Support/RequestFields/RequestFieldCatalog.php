<?php

namespace App\Support\RequestFields;

/**
 * The labels of the request data the team picks out of the messages
 * («Cliente», «Producto», «Monto solicitado»...) and how a written label is
 * recognized as one of them.
 *
 * A label is compared once normalized: WhatsApp markers (*, _, ~, `),
 * emojis and bullets are dropped, accents removed, case and spaces folded,
 * so «*CLIENTE* :», «▶️ Cliente» and «cliente» are the same label. Known
 * labels have a fixed key and canonical label; any other label is a custom
 * field whose key is the slug of its normalized text («Domicilio» →
 * "domicilio").
 *
 * Labels are matched tolerantly (FuzzyText): typos and truncations
 * («Desenbolso», «Monto autorisado», «Cant a pagar») name the same field,
 * the exact aliases always winning over a fuzzy reading. A few labels mean
 * something else depending on the message they are read in: in a request
 * of a field manager a bare «Monto» is the amount requested, in the
 * authorization the team sends back («Se autoriza …», RESOLUTION) it is the
 * amount authorized, and «Pago» / «Cuota» is the amount to pay.
 *
 * Pure functions: no database, no container.
 */
final class RequestFieldCatalog
{
    /**
     * Known fields: key => [canonical label, normalized aliases]. The key is
     * the slug of the canonical label, so a custom field named like a known
     * one always lands on the same key.
     *
     * @var array<string, array{0: string, 1: list<string>}>
     */
    private const FIELDS = [
        'cliente' => ['Cliente', ['cliente', 'clienta', 'nombre', 'nombre del cliente', 'nombre de cliente', 'nombre cliente', 'nombre del acreditado', 'acreditado', 'acreditada']],
        // The group of a group credit («Nombre del grupo EMPRENDEDORAS»).
        'grupo' => ['Grupo', ['grupo', 'nombre del grupo', 'nombre de grupo', 'nombre grupo', 'grupo solidario']],
        'sucursal' => ['Sucursal', ['sucursal', 'suc']],
        'monto_anterior' => ['Monto anterior', ['monto anterior', 'monto previo']],
        'monto_solicitado' => ['Monto solicitado', ['monto solicitado', 'monto a solicitar', 'monto requerido', 'monto que solicita']],
        'ciclo' => ['Ciclo', ['ciclo']],
        'producto' => ['Producto', ['producto']],
        'giro_de_negocio' => ['Giro de negocio', ['giro de negocio', 'giro del negocio', 'giro', 'actividad', 'actividad del negocio']],
        'telefono' => ['Teléfono', ['telefono', 'tel', 'celular', 'cel', 'numero de telefono', 'numero telefonico', 'whatsapp']],
        'dia_de_pago' => ['Día de pago', ['dia de pago', 'dia pago', 'dia de cobro']],
        'tipo' => ['Tipo', ['tipo', 'tipo de credito', 'tipo de solicitud']],
        // The resolution the team sends back («Se autoriza …»).
        'monto_autorizado' => ['Monto autorizado', ['monto autorizado', 'monto autorizada', 'monto aut', 'monto autorizo', 'monto aprobado', 'monto aprobada']],
        'desembolso' => ['Desembolso', ['desembolso', 'monto a desembolsar', 'monto desembolsado', 'monto de desembolso', 'monto a entregar']],
        'cantidad_a_pagar' => ['Cantidad a pagar', ['cantidad a pagar', 'cant a pagar', 'cantidad pagar', 'cantidad de pago', 'monto a pagar', 'pago semanal', 'cuota semanal']],
        'plazo' => ['Plazo', ['plazo', 'numero de pagos', 'semanas']],
    ];

    /** A request written by a field manager (the default reading). */
    public const REQUEST = 'request';

    /** The authorization or rejection the team sends back. */
    public const RESOLUTION = 'resolution';

    /**
     * Labels whose field depends on the message they are read in:
     * context => [normalized alias => key].
     *
     * @var array<string, array<string, string>>
     */
    private const CONTEXT_ALIASES = [
        self::REQUEST => [
            'monto' => 'monto_solicitado',
        ],
        self::RESOLUTION => [
            'monto' => 'monto_autorizado',
            'autorizado' => 'monto_autorizado',
            'pago' => 'cantidad_a_pagar',
            'cuota' => 'cantidad_a_pagar',
            'abono' => 'cantidad_a_pagar',
        ],
    ];

    /**
     * Request types written under «Solicitud de crédito»: normalized => shown.
     *
     * @var array<string, string>
     */
    public const TYPES = [
        'nuevo' => 'Nuevo',
        'nueva' => 'Nuevo',
        'reactivacion' => 'Reactivación',
        'renovacion' => 'Renovación',
        'refinanciamiento' => 'Refinanciamiento',
        'adicional' => 'Adicional',
        'adicion' => 'Adicional',
    ];

    /** Longest label kept (characters). */
    public const LABEL_MAX = 40;

    /** Longest value kept (characters). */
    public const VALUE_MAX = 255;

    /** Most fields a conversation keeps. */
    public const FIELDS_MAX = 20;

    /**
     * Known fields for the label selector, in the order of a request.
     *
     * @return list<array{key: string, label: string}>
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::FIELDS as $key => [$label]) {
            $options[] = ['key' => $key, 'label' => $label];
        }

        return $options;
    }

    /**
     * The fields stored on a conversation (conversations.request_fields),
     * read defensively: anything that is not a well-formed field is skipped.
     *
     * @return list<array{key: string, label: string, value: string, message_id: int|null, captured_at: string|null}>
     */
    public static function fromStored(mixed $stored): array
    {
        if (! is_array($stored)) {
            return [];
        }

        $fields = [];

        foreach ($stored as $field) {
            if (! is_array($field) || ! is_string($field['key'] ?? null) || ! is_string($field['label'] ?? null) || ! is_scalar($field['value'] ?? null)) {
                continue;
            }

            $fields[] = [
                'key' => $field['key'],
                'label' => $field['label'],
                'value' => (string) $field['value'],
                'message_id' => is_int($field['message_id'] ?? null) ? $field['message_id'] : null,
                'captured_at' => is_string($field['captured_at'] ?? null) ? $field['captured_at'] : null,
            ];
        }

        return $fields;
    }

    /**
     * Stored values keyed by field key («producto» => «S12»), the context
     * quick replies are filled from.
     *
     * @return array<string, string>
     */
    public static function values(mixed $stored): array
    {
        $values = [];

        foreach (self::fromStored($stored) as $field) {
            if (trim($field['value']) !== '') {
                $values[$field['key']] = $field['value'];
            }
        }

        return $values;
    }

    public static function isKnown(string $key): bool
    {
        return isset(self::FIELDS[$key]);
    }

    /**
     * Canonical label of a known key, null for a custom one.
     */
    public static function labelOf(string $key): ?string
    {
        return self::FIELDS[$key][0] ?? null;
    }

    /**
     * Key of a written label: the known field it names, or its slug. Empty
     * when nothing readable remains (only emojis or punctuation).
     */
    public static function keyFor(string $label, string $context = self::REQUEST): string
    {
        $normalized = self::normalize($label);

        return self::knownKey($normalized, $context) ?? self::slug($normalized);
    }

    /**
     * Known key of an already normalized label, or null: an exact alias
     * first, otherwise the closest fuzzy reading (FuzzyText, truncations
     * allowed), the field listed first on a tie.
     */
    public static function knownKey(string $normalized, string $context = self::REQUEST): ?string
    {
        if ($normalized === '') {
            return null;
        }

        $best = null;
        $bestDistance = PHP_INT_MAX;

        foreach (self::aliasesOf($context) as [$alias, $key]) {
            $distance = FuzzyText::phraseDistance($normalized, $alias, prefixes: true);

            if ($distance === 0) {
                return $key;
            }

            if ($distance !== null && $distance < $bestDistance) {
                $best = $key;
                $bestDistance = $distance;
            }
        }

        return $best;
    }

    /**
     * Normalized aliases of every known field, longest first (so «giro de
     * negocio» is tried before «giro»), each with its key; with those of
     * the context.
     *
     * @return list<array{0: string, 1: string}>
     */
    public static function aliases(string $context = self::REQUEST): array
    {
        $aliases = self::aliasesOf($context);

        usort($aliases, fn (array $a, array $b): int => mb_strlen($b[0]) <=> mb_strlen($a[0]));

        return $aliases;
    }

    /**
     * The known field a line written without the colon starts with
     * («Giro de negocio Tortillas», «Desenbolso $2,130»), with how many
     * words its label takes, or null. The longest label is tried first; a
     * fuzzy reading never takes truncations (a sentence must not start a
     * field by chance) and needs something after the label.
     *
     * @return array{key: string, words: int}|null
     */
    public static function leadingKey(string $normalizedLine, string $context = self::REQUEST): ?array
    {
        $words = FuzzyText::words($normalizedLine);
        $aliases = self::aliases($context);

        foreach ($aliases as [$alias, $key]) {
            if (str_starts_with($normalizedLine.' ', $alias.' ')) {
                return ['key' => $key, 'words' => substr_count($alias, ' ') + 1];
            }
        }

        foreach ($aliases as [$alias, $key]) {
            $count = substr_count($alias, ' ') + 1;

            if (count($words) > $count && FuzzyText::phraseDistance(implode(' ', array_slice($words, 0, $count)), $alias) !== null) {
                return ['key' => $key, 'words' => $count];
            }
        }

        return null;
    }

    /**
     * Aliases in the order the fields are listed, those of the context last.
     *
     * @return list<array{0: string, 1: string}>
     */
    private static function aliasesOf(string $context): array
    {
        $aliases = [];

        foreach (self::FIELDS as $key => [, $names]) {
            foreach ($names as $name) {
                $aliases[] = [$name, $key];
            }
        }

        foreach (self::CONTEXT_ALIASES[$context] ?? [] as $name => $key) {
            $aliases[] = [$name, $key];
        }

        return $aliases;
    }

    /**
     * Lowercase, accentless, single-spaced text without WhatsApp markers,
     * emojis or leading bullets.
     */
    public static function normalize(string $text): string
    {
        $text = mb_strtolower(self::stripDecorations($text));
        $text = strtr($text, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n', 'à' => 'a', 'è' => 'e', 'ì' => 'i', 'ò' => 'o', 'ù' => 'u']);
        $text = (string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', $text);

        return trim($text);
    }

    /**
     * The text as written, without WhatsApp markers, emojis, leading bullets
     * or repeated spaces (accents and case are kept).
     */
    public static function stripDecorations(string $text): string
    {
        // Emojis and pictographs (▶️, ✅, 📌...), their variation selectors,
        // joiners and keycaps, and invisible format characters.
        $text = (string) preg_replace('/[\p{So}\p{Sk}\p{Cs}\p{Co}\x{FE0E}\x{FE0F}\x{200B}-\x{200F}\x{20E3}\x{2060}\x{FEFF}]/u', '', $text);
        $text = str_replace(['*', '_', '~', '`'], '', $text);
        $text = (string) preg_replace('/^[\s\-–—•·>»▪●◦+]+/u', '', $text);
        $text = (string) preg_replace('/\s+/u', ' ', $text);

        return trim($text);
    }

    /**
     * Slug of a normalized label ("dia de pago" → "dia_de_pago").
     */
    public static function slug(string $normalized): string
    {
        $slug = trim((string) preg_replace('/[^a-z0-9]+/', '_', $normalized), '_');

        return mb_substr($slug, 0, self::LABEL_MAX);
    }
}
