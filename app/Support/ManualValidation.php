<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Fictitious scenario for validating increments 01, A and B by hand on a
 * developer machine. Every record it creates carries one of these markers,
 * so it can be told apart from real data and removed selectively:
 *
 * - users:        e-mail ending in "@demo-b.chatmanager.test";
 * - departments:  slugs "demo-b-ventas" and "demo-b-soporte";
 * - connection:   Phone number ID 990000000000100 (never contacts Meta);
 * - contacts:     WhatsApp numbers +52 55 0000 01xx (wa_id 52155000001xx);
 * - tags, quick replies and role: names starting with "Demo B · ".
 *
 * It only runs locally, on the application database, never in production
 * or testing (see ensureAllowed()).
 */
final class ManualValidation
{
    public const PREFIX = 'Demo B · ';

    public const EMAIL_DOMAIN = 'demo-b.chatmanager.test';

    public const DEPARTMENTS = ['sales' => 'demo-b-ventas', 'support' => 'demo-b-soporte'];

    public const PHONE_NUMBER_ID = '990000000000100';

    public const DISPLAY_PHONE_NUMBER = '+52 55 0000 0100';

    public const TAGS = ['interested' => 'demo-b-interesado', 'invoice' => 'demo-b-requiere-factura'];

    public const QUICK_REPLIES = ['sales' => 'demo-ventas', 'support' => 'demo-soporte'];

    public const ROLE_LABEL = self::PREFIX.'Agente';

    /**
     * Customers of the scenario. New simulated customers must use a number
     * inside the same fictitious range (wa_id 5215500000100 to 5215500000199).
     */
    public const CUSTOMERS = [
        'laura' => ['wa_id' => '5215500000101', 'name' => self::PREFIX.'Laura Méndez'],
        'mario' => ['wa_id' => '5215500000102', 'name' => self::PREFIX.'Mario Ruiz'],
        'sofia' => ['wa_id' => '5215500000103', 'name' => self::PREFIX.'Sofía Torres'],
    ];

    /**
     * File (outside the repository) with the local passwords of the demo accounts.
     */
    public static function credentialsPath(): string
    {
        return (getenv('HOME') ?: sys_get_temp_dir()).'/ChatManagerDemo/credenciales-demo-b.txt';
    }

    /**
     * Log of the simulated message ids, outside version control.
     */
    public static function inboundLogPath(): string
    {
        return storage_path('logs/demo-b-inbound.log');
    }

    public static function isDemoSender(string $waId): bool
    {
        return preg_match('/^52155000001\d\d$/', $waId) === 1;
    }

    /**
     * SQL LIKE pattern of the fictitious range as stored on contacts (E.164).
     */
    public static function demoPhonePattern(): string
    {
        return '+5255000001__';
    }

    /**
     * Refuse to run anywhere but a local machine connected to the application database.
     */
    public static function ensureAllowed(): void
    {
        $environment = (string) app()->environment();
        $connection = DB::connection();
        $database = $connection->getDatabaseName();

        $refusal = match (true) {
            app()->isProduction() => 'it never runs in production',
            app()->runningUnitTests() || $environment !== 'local' => "it only runs with APP_ENV=local (current: {$environment})",
            $connection->getDriverName() !== 'mariadb' => 'it only runs on MariaDB',
            $database !== 'chatmanager' || $database === config('database.testing_database') => "it only runs on the local application database \"chatmanager\" (current: {$database})",
            default => null,
        };

        if ($refusal !== null) {
            throw new RuntimeException("The manual validation scenario is disabled here: {$refusal}.");
        }
    }

    /**
     * A text message in the documented WhatsApp Cloud API webhook format.
     *
     * @param  array<string, string>|null  $referral  Click-to-WhatsApp ad data, when the message comes from an ad.
     * @return array<string, mixed>
     */
    public static function inboundPayload(string $waId, string $profileName, string $messageId, string $text, int $timestamp, ?array $referral = null): array
    {
        $message = [
            'from' => $waId,
            'id' => $messageId,
            'timestamp' => (string) $timestamp,
            'type' => 'text',
            'text' => ['body' => $text],
        ];

        if ($referral !== null) {
            $message['referral'] = $referral;
        }

        return [
            'object' => 'whatsapp_business_account',
            'entry' => [[
                'id' => 'DEMO-B-WABA',
                'changes' => [[
                    'field' => 'messages',
                    'value' => [
                        'messaging_product' => 'whatsapp',
                        'metadata' => ['display_phone_number' => '5215500000100', 'phone_number_id' => self::PHONE_NUMBER_ID],
                        'contacts' => [['profile' => ['name' => $profileName], 'wa_id' => $waId]],
                        'messages' => [$message],
                    ],
                ]],
            ]],
        ];
    }

    /**
     * Referral of a fictitious click-to-WhatsApp ad (no real campaign is named).
     *
     * @return array<string, string>
     */
    public static function demoAdReferral(string $suffix): array
    {
        return [
            'source_type' => 'ad',
            'source_id' => "demo-b-ad-{$suffix}",
            'source_url' => 'https://example.com/demo-b/anuncio',
            'headline' => 'Anuncio ficticio de la validación Demo B',
            'body' => 'Escríbenos por WhatsApp (dato de prueba).',
            'media_type' => 'image',
            'ctwa_clid' => "demo-b-ctwa-{$suffix}",
        ];
    }
}
