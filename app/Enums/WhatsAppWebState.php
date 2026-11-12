<?php

namespace App\Enums;

/**
 * State of the WhatsApp Web (QR) session of a connection, as reported by the
 * Node service (docs/whatsapp-web-qr-contrato.md §3). Only Connected may
 * send: a running service says nothing about a session.
 */
enum WhatsAppWebState: string
{
    case Unconfigured = 'unconfigured';
    case Starting = 'starting';
    case QrPending = 'qr_pending';
    case Pairing = 'pairing';
    case Connected = 'connected';
    case Reconnecting = 'reconnecting';
    case Disconnected = 'disconnected';
    case LoggedOut = 'logged_out';
    case Error = 'error';

    public function label(): string
    {
        return match ($this) {
            self::Unconfigured => 'Sin vincular',
            self::Starting => 'Generando QR',
            self::QrPending => 'Esperando escaneo',
            self::Pairing => 'Vinculando',
            self::Connected => 'Conectada',
            self::Reconnecting => 'Reconectando',
            self::Disconnected => 'Desconectada',
            self::LoggedOut => 'Requiere nueva vinculación',
            self::Error => 'Con error',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Unconfigured => 'Ningún teléfono está vinculado. Genera un código QR y escanéalo desde WhatsApp.',
            self::Starting => 'El servicio está preparando la sesión.',
            self::QrPending => 'Hay un código QR vigente: escanéalo desde WhatsApp, en Dispositivos vinculados.',
            self::Pairing => 'El teléfono escaneó el código; WhatsApp está terminando la vinculación.',
            self::Connected => 'El teléfono está vinculado y la sesión puede recibir y enviar mensajes.',
            self::Reconnecting => 'Se perdió la conexión con WhatsApp; se está reintentando.',
            self::Disconnected => 'La sesión se detuvo desde ChatManager. El teléfono sigue vinculado: reconéctala cuando quieras.',
            self::LoggedOut => 'WhatsApp cerró la sesión de este dispositivo. Hay que vincular el teléfono de nuevo con un código QR.',
            self::Error => 'La sesión no pudo recuperarse sola. Revisa el último error y reconecta.',
        };
    }

    /**
     * Visual severity (success | warning | danger | neutral | info).
     */
    public function tone(): string
    {
        return match ($this) {
            self::Connected => 'success',
            self::Starting, self::Pairing => 'info',
            self::QrPending, self::Reconnecting => 'warning',
            self::LoggedOut, self::Error => 'danger',
            self::Unconfigured, self::Disconnected => 'neutral',
        };
    }

    public function canSend(): bool
    {
        return $this === self::Connected;
    }

    /**
     * Whether the service is in the middle of a linking or a recovery, so the
     * interface polls quickly and the server asks the service for news.
     */
    public function isTransitional(): bool
    {
        return in_array($this, [self::Starting, self::QrPending, self::Pairing, self::Reconnecting], true);
    }

    /**
     * Whether the session keeps the credentials of a linked phone.
     */
    public function isLinked(): bool
    {
        return in_array($this, [self::Connected, self::Reconnecting, self::Disconnected, self::Error], true);
    }
}
