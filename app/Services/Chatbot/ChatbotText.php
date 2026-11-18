<?php

namespace App\Services\Chatbot;

use App\Models\Conversation;
use App\Services\BusinessHours;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Variables of the automatic texts (chatbot menu, option replies, keyword
 * replies and the welcome, away and farewell messages), replaced by plain
 * text when the message is queued:
 *
 * - {{nombre}}: the contact's name (aliases {{userName}} and {{name}}, as
 *   written in Whaticket). A provisional contact is called by the name of
 *   their WhatsApp profile; a name that is only a phone number is left out;
 * - {{departamento}}: the conversation's department;
 * - {{conexion}}: the connection's name;
 * - {{saludo}}: «Buenos días», «Buenas tardes» or «Buenas noches» by the
 *   platform's time zone.
 *
 * Names are case-insensitive and may carry spaces inside the braces
 * ({{ Nombre }}); anything else between braces is left as written.
 */
class ChatbotText
{
    /**
     * Placeholder => variable it reads. Lowercase.
     *
     * @var array<string, 'name'|'department'|'connection'|'greeting'>
     */
    private const VARIABLES = [
        'nombre' => 'name',
        'username' => 'name',
        'name' => 'name',
        'departamento' => 'department',
        'conexion' => 'connection',
        'conexión' => 'connection',
        'saludo' => 'greeting',
    ];

    /**
     * Catalog for the editors: token, meaning and the sample used by the
     * previews.
     *
     * @var list<array{token: string, label: string, example: string}>
     */
    public const CATALOG = [
        ['token' => '{{nombre}}', 'label' => 'Nombre del contacto', 'example' => 'Laura'],
        ['token' => '{{departamento}}', 'label' => 'Departamento de la conversación', 'example' => 'Mesa de control'],
        ['token' => '{{conexion}}', 'label' => 'Nombre de la conexión', 'example' => 'Solicitudes MC'],
        ['token' => '{{saludo}}', 'label' => 'Buenos días, tardes o noches', 'example' => 'Buenas tardes'],
    ];

    /** Marker of a variable without value while rendering. */
    private const EMPTY = "\u{0}";

    public function __construct(private BusinessHours $hours) {}

    public function render(string $text, Conversation $conversation, ?CarbonInterface $at = null): string
    {
        if (! str_contains($text, '{{')) {
            return $text;
        }

        $values = null;

        $rendered = preg_replace_callback('/\{\{\s*([\p{L}_]+)\s*\}\}/u', function (array $match) use ($conversation, $at, &$values): string {
            $variable = self::VARIABLES[mb_strtolower($match[1])] ?? null;

            if ($variable === null) {
                return $match[0];
            }

            $values ??= $this->values($conversation, $at);

            // An empty value is marked so the space before it goes too.
            return $values[$variable] === '' ? self::EMPTY : $values[$variable];
        }, $text);

        // «¡Hola {{nombre}}!» without a name reads «¡Hola!», not «¡Hola !».
        return str_replace([' '.self::EMPTY, self::EMPTY], '', (string) $rendered);
    }

    /**
     * «Buenos días» (5:00-11:59), «Buenas tardes» (12:00-18:59) or «Buenas
     * noches», in the platform's time zone.
     */
    public function greeting(?CarbonInterface $at = null): string
    {
        $hour = (int) CarbonImmutable::instance($at ?? now())->setTimezone($this->hours->timezone())->format('G');

        return match (true) {
            $hour >= 5 && $hour < 12 => 'Buenos días',
            $hour >= 12 && $hour < 19 => 'Buenas tardes',
            default => 'Buenas noches',
        };
    }

    /**
     * @return array{name: string, department: string, connection: string, greeting: string}
     */
    private function values(Conversation $conversation, ?CarbonInterface $at): array
    {
        $conversation->loadMissing(['contact:id,name,profile_name,phone_e164,saved_at', 'department:id,name', 'channelConnection:id,name']);

        return [
            'name' => $this->contactName($conversation),
            'department' => (string) $conversation->department?->name,
            'connection' => (string) $conversation->channelConnection?->name,
            'greeting' => $this->greeting($at),
        ];
    }

    private function contactName(Conversation $conversation): string
    {
        $contact = $conversation->contact;

        if ($contact === null) {
            return '';
        }

        // A provisional contact (never saved to the directory) is named by
        // their WhatsApp profile; a saved one by the name the company chose.
        $candidates = $contact->saved_at === null
            ? [$contact->profile_name, $contact->name]
            : [$contact->name, $contact->profile_name];

        foreach ($candidates as $candidate) {
            $candidate = trim((string) $candidate);

            // A phone number is not a name to greet someone with.
            if ($candidate !== '' && preg_match('/^[\d\s()+\-.]+$/', $candidate) !== 1) {
                return $candidate;
            }
        }

        return '';
    }
}
