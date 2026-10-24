<?php

namespace App\Http\Requests\WebChat;

use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Shape of a signed visitor identity ({external_id, name?, email?, iat,
 * nonce, signature}). Its signature, freshness and nonce are verified
 * afterwards by VisitorIdentity.
 */
final class VisitorIdentityRules
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public static function rules(string $prefix, bool $required): array
    {
        return [
            $prefix => [$required ? 'required' : 'sometimes', 'array:external_id,name,email,iat,nonce,signature'],
            "{$prefix}.external_id" => ["required_with:{$prefix}", 'string', 'min:1', 'max:191'],
            "{$prefix}.name" => ['nullable', 'string', 'max:80'],
            "{$prefix}.email" => ['nullable', 'string', 'email', 'max:191'],
            "{$prefix}.iat" => ["required_with:{$prefix}", 'integer'],
            "{$prefix}.nonce" => ["required_with:{$prefix}", 'string', 'regex:/^[A-Za-z0-9_-]{16,64}$/'],
            "{$prefix}.signature" => ["required_with:{$prefix}", 'string', 'regex:/^[0-9a-f]{64}$/'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function attributes(string $prefix): array
    {
        return [
            $prefix => 'identidad',
            "{$prefix}.external_id" => 'identificador externo',
            "{$prefix}.name" => 'nombre',
            "{$prefix}.email" => 'correo electrónico',
            "{$prefix}.iat" => 'fecha de emisión',
            "{$prefix}.nonce" => 'nonce',
            "{$prefix}.signature" => 'firma',
        ];
    }

    /**
     * The identity as VisitorIdentity::verify() expects it (iat as integer).
     *
     * @param  array<string, mixed>  $identity
     * @return array<string, mixed>
     */
    public static function payload(array $identity): array
    {
        return [...$identity, 'iat' => (int) ($identity['iat'] ?? 0)];
    }
}
