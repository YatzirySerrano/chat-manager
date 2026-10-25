<?php

namespace App\Support\Audit;

use App\Enums\AgentAvailability;
use App\Enums\ApiAbility;
use App\Enums\AttributionReliability;
use App\Enums\ColorSwatch;
use App\Enums\ConnectionChannel;
use App\Enums\ConnectionHealth;
use App\Enums\ContactOriginType;
use App\Enums\ContactReviewReason;
use App\Enums\ContactReviewResolution;
use App\Enums\ContactSource;
use App\Enums\ConversationStatus;
use App\Enums\DistributionMode;
use App\Enums\ExportFormat;
use App\Enums\Permission;
use App\Enums\TeamChat\TeamParticipantRole;
use App\Enums\ThemePalette;
use App\Enums\WebChatAvailabilityMode;
use App\Enums\WebChatIdentityMode;
use App\Enums\WebChatPosition;
use App\Services\Imports\ContactImportAnalyzer;
use App\Services\Imports\ContactImporter;
use App\Services\Meta\MetaErrorCatalog;
use BackedEnum;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Throwable;

/**
 * Turns stored audit values into readable Spanish text: enums through their
 * label(), Meta statuses with the wording of the Cloud API documentation,
 * booleans as Sí/No, dates in the platform time zone and lists joined.
 * Values that are not recognized are shown as they were stored.
 */
final class AuditValueFormatter
{
    /**
     * Characters kept of a single free-text value.
     */
    private const MAX_LENGTH = 200;

    /**
     * Outcome of a credentials verification (CredentialVerification).
     *
     * @var array<string, string>
     */
    public const VERIFICATION_RESULTS = [
        'verified' => 'Correcto',
        'rejected' => 'Rechazado',
        'unreachable' => 'Sin respuesta de Meta',
    ];

    /**
     * Phone number `status` of the WhatsApp Cloud API (GET /{phone-number-id}).
     *
     * @var array<string, string>
     */
    private const PHONE_STATUSES = [
        'CONNECTED' => 'Conectado',
        'DISCONNECTED' => 'Desconectado',
        'PENDING' => 'Pendiente de registro',
        'FLAGGED' => 'Marcado por baja calidad',
        'RESTRICTED' => 'Restringido',
        'BANNED' => 'Bloqueado por Meta',
        'DELETED' => 'Eliminado',
        'MIGRATED' => 'Migrado',
        'RATE_LIMITED' => 'Con límite de envíos',
        'UNVERIFIED' => 'Sin verificar',
        'OFFLINE' => 'Fuera de línea',
        'UNKNOWN' => 'Desconocido',
    ];

    /**
     * Meta's quality rating describes the quality of the messages the phone
     * number sends (customer blocks and reports in the last days), not the
     * health of the connection; hence the field label "Calidad del número".
     *
     * @var array<string, string>
     */
    private const QUALITY_RATINGS = [
        'GREEN' => 'Alta',
        'YELLOW' => 'Media',
        'RED' => 'Baja',
        'UNKNOWN' => 'Sin calificación',
        'NA' => 'Sin calificación',
    ];

    /**
     * @var array<string, string>
     */
    private const CODE_VERIFICATION_STATUSES = [
        'VERIFIED' => 'Verificado',
        'NOT_VERIFIED' => 'Sin verificar',
        'EXPIRED' => 'Vencido',
    ];

    /**
     * @var array<string, string>
     */
    private const PLATFORM_TYPES = [
        'CLOUD_API' => 'WhatsApp Cloud API',
        'ON_PREMISE' => 'API local (On-Premises)',
        'NOT_APPLICABLE' => 'Sin registrar',
    ];

    /**
     * @var array<string, string>
     */
    private const MATCHED_BY = [
        'phone' => 'Número de teléfono',
        'administrator' => 'Decisión de un administrador',
    ];

    /**
     * @var array<string, string>
     */
    private const EXPORT_MODES = [
        'sync' => 'Descarga inmediata',
        'queued' => 'En segundo plano',
    ];

    /**
     * Message error codes of the platform (messages.error_code).
     *
     * @var array<string, string>
     */
    private const MESSAGE_ERRORS = [
        'delivery_unknown' => 'Entrega sin confirmar',
        'delivery_error' => 'Error de entrega',
        'transport_unavailable' => 'Envío no disponible',
        'provider_failed' => 'El proveedor no entregó el mensaje',
        'connection_unavailable' => 'Conexión no disponible',
        'sender_not_allowed' => 'Quien escribió ya no podía responder la conversación',
        'template_unavailable' => 'Plantilla no disponible',
        'customer_window_closed' => 'Ventana de atención vencida',
    ];

    /**
     * Where a contact was saved into the directory (SaveContactToDirectory).
     *
     * @var array<string, string>
     */
    private const SAVED_VIA = [
        'conversation' => 'Desde la conversación',
        'contact' => 'Desde la ficha del contacto',
        'import' => 'Importación',
    ];

    /**
     * Kind of personal item an administrator changed (supervision.personal_item_*).
     *
     * @var array<string, string>
     */
    private const PERSONAL_ITEM_TYPES = [
        'contact_alias' => 'Alias de contacto',
        'alias' => 'Alias de contacto',
        'chat_appearance' => 'Apariencia del chat',
        'appearance' => 'Apariencia del chat',
        'quick_reply' => 'Respuesta rápida personal',
    ];

    /**
     * Enum per property key (values stored as the enum's backing value).
     *
     * @var array<string, class-string<BackedEnum>>
     */
    private const ENUMS = [
        'channel' => ConnectionChannel::class,
        'origin_type' => ContactOriginType::class,
        'reliability' => AttributionReliability::class,
        'resolution' => ContactReviewResolution::class,
        'health' => ConnectionHealth::class,
        'format' => ExportFormat::class,
        'color' => ColorSwatch::class,
        'accent_color' => ColorSwatch::class,
        'distribution_mode' => DistributionMode::class,
        'position' => WebChatPosition::class,
        'identity_mode' => WebChatIdentityMode::class,
        'availability_mode' => WebChatAvailabilityMode::class,
        'appearance.palette' => ThemePalette::class,
        'abilities' => ApiAbility::class,
        'permissions' => Permission::class,
        'lost_permissions' => Permission::class,
        'gained_permissions' => Permission::class,
    ];

    public function __construct(private string $timezone) {}

    /**
     * @param  string  $key  Property key (or dotted path) the value was stored under.
     * @param  string  $action  Audit action value, for keys whose meaning depends on it.
     */
    public function format(string $key, mixed $value, string $action = ''): string
    {
        if ($value === null || $value === '' || $value === []) {
            return $this->empty($key);
        }

        if (is_bool($value)) {
            return $value ? 'Sí' : 'No';
        }

        if (is_array($value)) {
            return $this->list($key, $value, $action);
        }

        if (! is_scalar($value)) {
            return '—';
        }

        $text = (string) $value;
        $translated = $this->translate($key, $text, $action);

        if ($translated !== null) {
            return $translated;
        }

        if (is_string($value) && $this->looksLikeDate($key, $text)) {
            return $this->date($text) ?? $text;
        }

        if (is_int($value) || is_float($value)) {
            return number_format($value, is_float($value) ? 2 : 0, '.', ',');
        }

        return Str::limit($text, self::MAX_LENGTH);
    }

    /**
     * The value translated through the dictionaries and enums, or null when none applies.
     */
    private function translate(string $key, string $value, string $action): ?string
    {
        $leaf = str_contains($key, '.') && ! isset(self::ENUMS[$key]) ? Str::afterLast($key, '.') : $key;

        $dictionary = match ($leaf) {
            'verification_result' => self::VERIFICATION_RESULTS,
            'phone_status' => self::PHONE_STATUSES,
            'quality_rating' => self::QUALITY_RATINGS,
            'code_verification_status' => self::CODE_VERIFICATION_STATUSES,
            'platform_type' => self::PLATFORM_TYPES,
            'matched_by' => self::MATCHED_BY,
            'mode' => self::EXPORT_MODES,
            'previous_error', 'error_code' => self::MESSAGE_ERRORS,
            'via' => self::SAVED_VIA,
            'item_type' => self::PERSONAL_ITEM_TYPES,
            'decision' => ContactImporter::DECISIONS,
            default => null,
        };

        if ($dictionary !== null) {
            $upper = in_array($leaf, ['phone_status', 'quality_rating', 'code_verification_status', 'platform_type'], true) ? Str::upper($value) : $value;

            if (isset($dictionary[$upper])) {
                return $dictionary[$upper];
            }

            if (in_array($leaf, ['previous_error', 'error_code'], true) && preg_match('/^meta_(\d+)$/', $value, $match) === 1) {
                return MetaErrorCatalog::explain((int) $match[1]);
            }

            return $value;
        }

        $enum = match (true) {
            $leaf === 'source' && ! str_starts_with($action, 'import.') => ContactSource::class,
            $leaf === 'reason' && str_starts_with($action, 'contact.review_') => ContactReviewReason::class,
            $leaf === 'role' && str_starts_with($action, 'team_chat.') => TeamParticipantRole::class,
            in_array($leaf, ['from', 'to'], true) && $action === 'user.availability_changed' => AgentAvailability::class,
            in_array($leaf, ['status', 'previous_status', 'new_status', 'target', 'target_status'], true) && str_starts_with($action, 'conversation.') => ConversationStatus::class,
            default => self::ENUMS[$key] ?? self::ENUMS[$leaf] ?? null,
        };

        if ($enum !== null) {
            return $this->enumLabel($enum, $value);
        }

        if ($leaf === 'fields' || ($leaf === 'field' && str_starts_with($action, 'import.'))) {
            return ContactImportAnalyzer::FIELDS[$value] ?? $value;
        }

        return null;
    }

    /**
     * @param  array<array-key, mixed>  $values
     */
    private function list(string $key, array $values, string $action): string
    {
        $items = [];

        foreach ($values as $itemKey => $item) {
            $text = is_array($item)
                ? $this->list($key, $item, $action)
                : $this->format($key, $item, $action);

            $items[] = is_string($itemKey) && ! array_is_list($values)
                ? AuditFieldLabels::humanize($itemKey).': '.$text
                : $text;
        }

        return Str::limit(implode(', ', $items), self::MAX_LENGTH * 2);
    }

    /**
     * @param  class-string<BackedEnum>  $enum
     */
    private function enumLabel(string $enum, string $value): string
    {
        $case = $enum::tryFrom($value);

        return $case !== null && method_exists($case, 'label') ? (string) $case->label() : $value;
    }

    private function empty(string $key): string
    {
        return match ($key) {
            'connection_ids', 'department_ids' => 'Todas',
            'allowed_ips' => 'Cualquiera',
            default => '—',
        };
    }

    private function looksLikeDate(string $key, string $value): bool
    {
        return (str_ends_with($key, '_at') || str_ends_with($key, '.date'))
            && preg_match('/^\d{4}-\d{2}-\d{2}/', $value) === 1;
    }

    private function date(string $value): ?string
    {
        try {
            $date = CarbonImmutable::parse($value);
        } catch (Throwable) {
            return null;
        }

        if (strlen($value) === 10) {
            return $date->format('d/m/Y');
        }

        return $date->setTimezone($this->timezone)->format('d/m/Y H:i');
    }
}
