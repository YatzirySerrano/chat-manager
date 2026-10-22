<?php

namespace App\Enums;

/**
 * Review status of a WhatsApp template exactly as Meta reports it. Only
 * APPROVED templates can be sent.
 */
enum MessageTemplateStatus: string
{
    case Approved = 'APPROVED';
    case Pending = 'PENDING';
    case Rejected = 'REJECTED';
    case Paused = 'PAUSED';
    case Disabled = 'DISABLED';
    case InAppeal = 'IN_APPEAL';
    case PendingDeletion = 'PENDING_DELETION';
    case Deleted = 'DELETED';
    case LimitExceeded = 'LIMIT_EXCEEDED';
    case Archived = 'ARCHIVED';
    case Unknown = 'UNKNOWN';

    /**
     * Status reported by Meta (status field or status-update webhook event).
     */
    public static function fromMeta(mixed $value): self
    {
        $value = is_string($value) ? strtoupper(trim($value)) : '';

        return match ($value) {
            'REINSTATED' => self::Approved,
            default => self::tryFrom($value) ?? self::Unknown,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Approved => 'Aprobada',
            self::Pending => 'En revisión',
            self::Rejected => 'Rechazada',
            self::Paused => 'Pausada',
            self::Disabled => 'Deshabilitada',
            self::InAppeal => 'En apelación',
            self::PendingDeletion => 'Eliminación pendiente',
            self::Deleted => 'Eliminada en Meta',
            self::LimitExceeded => 'Límite excedido',
            self::Archived => 'Archivada',
            self::Unknown => 'Estado no reconocido',
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::Approved => 'success',
            self::Pending, self::InAppeal => 'primary',
            self::Paused, self::LimitExceeded, self::PendingDeletion => 'warning',
            self::Rejected, self::Disabled => 'danger',
            default => 'neutral',
        };
    }

    public function isSendable(): bool
    {
        return $this === self::Approved;
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $status): array => ['value' => $status->value, 'label' => $status->label()], self::cases());
    }
}
