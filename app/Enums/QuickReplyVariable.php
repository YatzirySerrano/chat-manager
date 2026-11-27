<?php

namespace App\Enums;

/**
 * Fixed catalog of placeholders a quick reply may contain. They are replaced
 * by plain text; nothing else inside braces is ever evaluated.
 *
 * The «solicitud.*» ones are the request data the team took from the
 * messages of the conversation («Datos de la solicitud»); without a value
 * they are left blank and reported, like any other variable.
 */
enum QuickReplyVariable: string
{
    case ContactName = 'contacto.nombre';
    case AgentName = 'agente.nombre';
    case DepartmentName = 'departamento.nombre';
    case CompanyName = 'empresa.nombre';
    case RequestClient = 'solicitud.cliente';
    case RequestBranch = 'solicitud.sucursal';
    case RequestProduct = 'solicitud.producto';
    case RequestCycle = 'solicitud.ciclo';
    case RequestAmount = 'solicitud.monto_solicitado';
    case RequestPreviousAmount = 'solicitud.monto_anterior';
    case RequestPhone = 'solicitud.telefono';
    case RequestBusiness = 'solicitud.giro_de_negocio';
    case RequestPaymentDay = 'solicitud.dia_de_pago';
    case RequestType = 'solicitud.tipo';
    case RequestAuthorizedAmount = 'solicitud.monto_autorizado';
    case RequestDisbursement = 'solicitud.desembolso';
    case RequestPayment = 'solicitud.cantidad_a_pagar';

    /** Prefix of the variables filled from the request data. */
    public const REQUEST_PREFIX = 'solicitud.';

    /**
     * The placeholder as written in the body, e.g. "{contacto.nombre}".
     */
    public function token(): string
    {
        return '{'.$this->value.'}';
    }

    public function label(): string
    {
        return match ($this) {
            self::ContactName => 'Nombre del contacto',
            self::AgentName => 'Nombre del agente',
            self::DepartmentName => 'Nombre del departamento',
            self::CompanyName => 'Nombre de la empresa',
            self::RequestClient => 'Cliente',
            self::RequestBranch => 'Sucursal',
            self::RequestProduct => 'Producto',
            self::RequestCycle => 'Ciclo',
            self::RequestAmount => 'Monto solicitado',
            self::RequestPreviousAmount => 'Monto anterior',
            self::RequestPhone => 'Teléfono',
            self::RequestBusiness => 'Giro de negocio',
            self::RequestPaymentDay => 'Día de pago',
            self::RequestType => 'Tipo de solicitud',
            self::RequestAuthorizedAmount => 'Monto autorizado',
            self::RequestDisbursement => 'Desembolso',
            self::RequestPayment => 'Cantidad a pagar',
        };
    }

    /**
     * Sample value used by previews.
     */
    public function example(): string
    {
        return match ($this) {
            self::ContactName => 'María López',
            self::AgentName => 'Carlos Ruiz',
            self::DepartmentName => 'Ventas',
            self::CompanyName => 'Mi Empresa',
            self::RequestClient => 'Beatriz Guerrero Olvera',
            self::RequestBranch => 'Tula',
            self::RequestProduct => 'S12',
            self::RequestCycle => '01',
            self::RequestAmount => '$5,000',
            self::RequestPreviousAmount => '$3,000',
            self::RequestPhone => '773 153 6979',
            self::RequestBusiness => 'Tortillas',
            self::RequestPaymentDay => 'Lunes',
            self::RequestType => 'Nuevo',
            self::RequestAuthorizedAmount => '$3,000',
            self::RequestDisbursement => '$2,130',
            self::RequestPayment => '$398',
        };
    }

    /**
     * «general» (contact, agent, department, company) or «solicitud» (the
     * request data of the conversation), to group the form buttons.
     */
    public function group(): string
    {
        return str_starts_with($this->value, self::REQUEST_PREFIX) ? 'solicitud' : 'general';
    }

    /**
     * Key of the request field it reads («solicitud.producto» → "producto"),
     * null for the other variables.
     */
    public function requestKey(): ?string
    {
        return $this->group() === 'solicitud' ? substr($this->value, strlen(self::REQUEST_PREFIX)) : null;
    }

    /**
     * @return list<array{value: string, token: string, label: string, example: string, group: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $variable): array => [
            'value' => $variable->value,
            'token' => $variable->token(),
            'label' => $variable->label(),
            'example' => $variable->example(),
            'group' => $variable->group(),
        ], self::cases());
    }
}
