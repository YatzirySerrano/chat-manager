<?php

namespace App\Services\Migration;

/**
 * Contacts exported from a Whaticket installation (CSV or Excel).
 *
 * PENDIENTE DE VALIDACIÓN: no authentic export of the company's Whaticket
 * has been reviewed yet, so nothing here asserts an official format. The
 * adapter only uses heuristics around the fields a Whaticket contact is
 * commonly exported with (name, number, email) and the WhatsApp number
 * conventions (digits with the country code and no "+", optionally with a
 * "@c.us" / "@s.whatsapp.net" suffix). The suggested mapping is always
 * editable and every row still goes through the contact validation.
 * Once a real export is available its headers must be checked and, if they
 * differ, added to expectedColumns() (see docs/migracion-whaticket.md).
 */
class WhaticketContactsAdapter implements MigrationSourceAdapter
{
    use HeaderMatching;

    public const KEY = 'whaticket-contacts';

    /**
     * Header names that hint at a Whaticket contact export without being
     * contact fields themselves (heuristic signals only).
     *
     * @var array<string, int>
     */
    private const SIGNALS = [
        'profilepicurl' => 15,
        'isgroup' => 15,
        'createdat' => 5,
        'updatedat' => 5,
    ];

    /**
     * Values of an "isGroup" column meaning the row is a group chat.
     *
     * @var list<string>
     */
    private const TRUTHY = ['1', 'true', 't', 'yes', 'y', 'si', 'sí', 'verdadero'];

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'Whaticket · contactos';
    }

    public function description(): string
    {
        return 'Exportación de contactos de Whaticket (nombre, número y correo). Quita sufijos de WhatsApp como «@c.us», agrega el «+» a números con lada internacional y descarta grupos.';
    }

    public function isValidated(): bool
    {
        return false;
    }

    public function validationNote(): ?string
    {
        return 'Pendiente de validación con una exportación real de Whaticket: el mapeo se sugiere por heurística (columnas name, number, email) y puedes cambiarlo. No se garantiza un formato oficial.';
    }

    public function expectedColumns(): array
    {
        return [
            'name' => ['name', 'nome', 'nombre', 'contact name', 'contactname'],
            'phone' => ['number', 'numero', 'phone', 'phonenumber', 'phone number', 'telefone', 'telefono', 'whatsapp'],
            'email' => ['email', 'e-mail', 'correo', 'mail'],
            'tags' => ['tags', 'etiquetas', 'tag'],
            'notes' => ['notes', 'observacoes', 'observaciones', 'notas'],
        ];
    }

    public function detect(array $headers): array
    {
        $normalized = array_map(fn (string $header): string => self::normalizeHeader($header), $headers);
        $score = 0;
        $matched = [];
        $signals = [];

        if (in_array('number', $normalized, true)) {
            $score += 40;
            $matched[] = 'phone';
        } elseif ($this->columnNamed($headers, $this->expectedColumns()['phone']) !== null) {
            $score += 10;
            $matched[] = 'phone';
        }

        if (in_array('name', $normalized, true)) {
            $score += 20;
            $matched[] = 'name';
        }

        if (in_array('email', $normalized, true)) {
            $score += 10;
            $matched[] = 'email';
        }

        foreach (self::SIGNALS as $signal => $weight) {
            if (in_array($signal, $normalized, true)) {
                $score += $weight;
                $signals[] = $headers[(int) array_search($signal, $normalized, true)];
            }
        }

        return ['score' => min(100, $score), 'matched' => $matched, 'signals' => $signals];
    }

    public function suggestMapping(array $headers): array
    {
        return $this->mappingFromHints($headers, $this->expectedColumns());
    }

    public function normalizeRow(array $values, array $cells, array $headers): array
    {
        $warnings = [];
        $errors = [];
        $groupColumn = $this->columnNamed($headers, ['isgroup']);

        if ($groupColumn !== null && in_array(mb_strtolower(trim($cells[$groupColumn] ?? '')), self::TRUTHY, true)) {
            $errors[] = 'Es un grupo de WhatsApp, no un contacto: no se importa.';
        }

        $phone = trim($values['phone'] ?? '');

        if ($phone !== '') {
            if (preg_match('/@g\.us$/i', $phone) === 1) {
                $errors[] = 'El número corresponde a un grupo de WhatsApp («@g.us»): no se importa.';
            } else {
                $stripped = (string) preg_replace('/@(c\.us|s\.whatsapp\.net)$/i', '', $phone);

                if ($stripped !== $phone) {
                    $warnings[] = 'Se quitó el sufijo de WhatsApp del número.';
                }

                $digits = (string) preg_replace('/[\s().\-\x{00A0}]+/u', '', $stripped);

                if (preg_match('/^\d{11,15}$/', $digits) === 1) {
                    $stripped = '+'.$digits;
                    $warnings[] = 'Número sin «+»: se interpretó con lada internacional, como lo guarda WhatsApp.';
                }

                $values['phone'] = $stripped;
            }
        }

        if (($values['email'] ?? '') !== '') {
            $values['email'] = trim($values['email']);
        }

        return ['values' => $values, 'warnings' => $warnings, 'errors' => $errors];
    }
}
