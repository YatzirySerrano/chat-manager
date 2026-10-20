<?php

namespace App\Enums;

/**
 * How a person reached the company. A contact without attribution has an
 * unidentified origin; it is never assumed to be "organic".
 */
enum ContactOriginType: string
{
    case Unknown = 'unknown';
    case Direct = 'direct';
    case Website = 'website';
    case QrCode = 'qr_code';
    case PhysicalAd = 'physical_ad';
    case FacebookAd = 'facebook_ad';
    case InstagramAd = 'instagram_ad';
    case CtwaButton = 'ctwa_button';

    /**
     * Click-to-WhatsApp ad reported by Meta. Meta does not say whether the ad
     * ran on Facebook or Instagram, so the platform is never guessed.
     */
    case MetaAd = 'meta_ad';

    /** Click-to-WhatsApp from a Facebook or Instagram post, reported by Meta. */
    case MetaPost = 'meta_post';
    case Referral = 'referral';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Unknown => 'Origen no identificado',
            self::Direct => 'Contacto directo',
            self::Website => 'Sitio web',
            self::QrCode => 'Código QR',
            self::PhysicalAd => 'Publicidad física',
            self::FacebookAd => 'Anuncio de Facebook',
            self::InstagramAd => 'Anuncio de Instagram',
            self::CtwaButton => 'Botón de clic a WhatsApp',
            self::MetaAd => 'Anuncio de Meta (Facebook o Instagram)',
            self::MetaPost => 'Publicación de Meta (Facebook o Instagram)',
            self::Referral => 'Recomendación',
            self::Other => 'Otro',
        };
    }

    /**
     * Types only the provider can report; nobody can declare them by hand.
     */
    public function isProviderReported(): bool
    {
        return in_array($this, [self::MetaAd, self::MetaPost], true);
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $type): array => ['value' => $type->value, 'label' => $type->label()], self::cases());
    }
}
