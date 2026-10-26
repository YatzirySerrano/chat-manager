<?php

namespace App\Http\Requests\Admin;

use App\Actions\Connections\ConnectionSecrets;
use App\Enums\ConnectionChannel;
use App\Enums\RecordState;
use App\Models\Connection;
use App\Models\Department;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Validation for creating and updating connections. Secret fields are
 * write-only: a blank value keeps the stored one. The channel is chosen when
 * the connection is created and never changes; the Meta identifiers and
 * secrets only exist for WhatsApp (a web chat connection is configured
 * through its widget, see WebChatWidgetRequest).
 */
class ConnectionRequest extends FormRequest
{
    /**
     * Maximum length of the automatic messages (well below the 4096 limit
     * of a WhatsApp text message, so they stay readable).
     */
    public const MESSAGE_MAX_LENGTH = 1024;

    /**
     * Longest reopen window accepted, in minutes (7 days).
     */
    public const REOPEN_WINDOW_MAX_MINUTES = 10080;

    /**
     * Fields that only a WhatsApp connection has (identifiers and secrets of Meta).
     *
     * @var list<string>
     */
    public const META_FIELDS = ['display_phone_number', 'phone_number_id', 'business_account_id', 'meta_app_id', 'access_token', 'app_secret', 'webhook_verify_token'];

    public function authorize(): bool
    {
        $connection = $this->route('connection');

        return $connection instanceof Connection
            ? ($this->user()?->can('update', $connection) ?? false)
            : ($this->user()?->can('create', Connection::class) ?? false);
    }

    protected function prepareForValidation(): void
    {
        $trimmed = [];

        foreach (['name', 'display_phone_number', 'phone_number_id', 'business_account_id', 'meta_app_id', ...array_keys(ConnectionSecrets::FIELDS)] as $field) {
            if (is_string($this->input($field))) {
                $trimmed[$field] = trim($this->input($field));
            }
        }

        // Clients that predate the channel choice create WhatsApp connections.
        if (! $this->route('connection') instanceof Connection && ! $this->has('channel')) {
            $trimmed['channel'] = ConnectionChannel::Whatsapp->value;
        }

        $this->merge($trimmed);
    }

    /**
     * Channel of the connection: the stored one when updating, the chosen one
     * when creating (WhatsApp when the value is not a known channel; the
     * rules reject it anyway).
     */
    public function channel(): ConnectionChannel
    {
        $connection = $this->route('connection');

        if ($connection instanceof Connection) {
            return $connection->channel;
        }

        $value = $this->input('channel');

        return (is_string($value) ? ConnectionChannel::tryFrom($value) : null) ?? ConnectionChannel::Whatsapp;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $connection = $this->route('connection');
        $metaId = ['nullable', 'string', 'regex:/^\d{5,64}$/'];

        $rules = [
            'channel' => $connection instanceof Connection ? ['prohibited'] : ['required', Rule::enum(ConnectionChannel::class)->only(ConnectionChannel::formCreatable())],
            'name' => ['required', 'string', 'max:120'],
            'display_phone_number' => ['nullable', 'string', 'max:32', 'regex:/^\+?[0-9][0-9 ()\-]{5,31}$/'],
            // A deleted connection keeps its number reserved: that conflict
            // is explained in after() so it can be restored instead.
            'phone_number_id' => [
                ...$metaId,
                Rule::unique(Connection::class)->ignore($connection instanceof Connection ? $connection->id : null)->whereNull('deleted_at'),
            ],
            'business_account_id' => $metaId,
            'meta_app_id' => $metaId,
            'access_token' => ['nullable', 'string', 'min:20', 'max:2000', 'regex:/^\S+$/'],
            'app_secret' => ['nullable', 'string', 'min:16', 'max:255', 'regex:/^\S+$/'],
            'webhook_verify_token' => ['nullable', 'string', 'min:16', 'max:255', 'regex:/^\S+$/'],
            'department_ids' => ['present', 'array', 'max:100'],
            'department_ids.*' => ['integer', 'distinct', Rule::exists(Department::class, 'id')],
            'default_department_id' => ['nullable', 'integer', Rule::in($this->departmentIds())],
            'welcome_enabled' => ['boolean'],
            'welcome_message' => ['nullable', 'string', 'max:'.self::MESSAGE_MAX_LENGTH, 'required_if_accepted:welcome_enabled'],
            'away_enabled' => ['boolean'],
            'away_message' => ['nullable', 'string', 'max:'.self::MESSAGE_MAX_LENGTH, 'required_if_accepted:away_enabled'],
            'farewell_enabled' => ['boolean'],
            'farewell_message' => ['nullable', 'string', 'max:'.self::MESSAGE_MAX_LENGTH, 'required_if_accepted:farewell_enabled'],
            'reopen_window_minutes' => ['nullable', 'integer', 'min:1', 'max:'.self::REOPEN_WINDOW_MAX_MINUTES],
        ];

        if ($this->channel() !== ConnectionChannel::Whatsapp) {
            foreach (self::META_FIELDS as $field) {
                $rules[$field] = ['prohibited'];
            }
        }

        return $rules;
    }

    /**
     * Departments newly authorized must not be deleted; departments already
     * attached may stay even if they were deleted later. A Phone number ID
     * that belongs to a deleted connection is explained, naming it only to
     * people who can consult deleted records.
     *
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->has('phone_number_id') || ! $this->filled('phone_number_id')) {
                    return;
                }

                $connection = $this->route('connection');
                $ignoreId = $connection instanceof Connection ? $connection->id : null;
                $deleted = Connection::query()
                    ->inRecordState(RecordState::Deleted)
                    ->where('phone_number_id', $this->input('phone_number_id'))
                    ->when($ignoreId !== null, fn ($query) => $query->whereKeyNot($ignoreId))
                    ->first(['id', 'name']);

                if ($deleted !== null) {
                    $validator->errors()->add('phone_number_id', $this->user()?->canViewDeletedRecords()
                        ? "Este Phone number ID pertenece a la conexión eliminada «{$deleted->name}». Restáurala en lugar de registrarlo de nuevo."
                        : 'Este Phone number ID pertenece a una conexión eliminada. Pide a quien pueda consultar registros eliminados que la restaure.');
                }
            },
            function (Validator $validator): void {
                if ($validator->errors()->has('department_ids') || $validator->errors()->has('department_ids.*')) {
                    return;
                }

                $connection = $this->route('connection');
                $attached = $connection instanceof Connection ? $connection->departments()->pluck('departments.id')->all() : [];

                $deleted = Department::query()
                    ->whereKey(array_diff($this->departmentIds(), $attached))
                    ->inRecordState(RecordState::Deleted)
                    ->orderBy('name')
                    ->pluck('name');

                if ($deleted->isNotEmpty()) {
                    $validator->errors()->add('department_ids', 'No es posible autorizar departamentos eliminados: '.$deleted->implode(', ').'.');
                }

                $defaultId = $this->filled('default_department_id') ? $this->integer('default_department_id') : null;
                $defaultChanges = $defaultId !== null && $defaultId !== ($connection instanceof Connection ? $connection->default_department_id : null);

                if ($defaultChanges && Department::query()->whereKey($defaultId)->inRecordState(RecordState::Deleted)->exists()) {
                    $validator->errors()->add('default_department_id', 'No es posible elegir como predeterminado un departamento eliminado.');
                }
            },
        ];
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'phone_number_id.unique' => 'Este Phone number ID ya está registrado en otra conexión.',
            'phone_number_id.regex' => 'El Phone number ID solo contiene dígitos (lo encuentras en WhatsApp Manager o en la configuración de la API de tu app de Meta).',
            'business_account_id.regex' => 'El ID de la cuenta de WhatsApp Business (WABA ID) solo contiene dígitos.',
            'meta_app_id.regex' => 'El ID de la app de Meta (App ID) solo contiene dígitos.',
            'display_phone_number.regex' => 'Escribe el número con lada internacional, por ejemplo +52 55 1234 5678.',
            'access_token.regex' => 'El token de acceso no debe contener espacios.',
            'app_secret.regex' => 'El secreto de la app de Meta (App secret) no debe contener espacios.',
            'webhook_verify_token.regex' => 'El token de verificación no debe contener espacios.',
            'default_department_id.in' => 'El departamento predeterminado debe ser uno de los departamentos autorizados.',
            '*.required_if_accepted' => 'Escribe el mensaje o desactívalo.',
            'channel.prohibited' => 'El canal de una conexión no se puede cambiar. Crea una conexión nueva para otro canal.',
            '*.prohibited' => 'Este dato solo existe en las conexiones de WhatsApp.',
        ];
    }

    /**
     * Get custom attributes for validator errors.
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'channel' => 'canal',
            'name' => 'nombre',
            'display_phone_number' => 'número visible',
            'phone_number_id' => 'Phone number ID',
            'business_account_id' => 'WABA ID',
            'meta_app_id' => 'App ID',
            'access_token' => 'token de acceso',
            'app_secret' => 'app secret',
            'webhook_verify_token' => 'token de verificación del webhook',
            'department_ids' => 'departamentos autorizados',
            'department_ids.*' => 'departamento',
            'default_department_id' => 'departamento predeterminado',
            'welcome_message' => 'mensaje de bienvenida',
            'away_message' => 'mensaje fuera de horario',
            'farewell_message' => 'mensaje de despedida',
            'reopen_window_minutes' => 'ventana de reapertura',
        ];
    }

    /**
     * Attributes ready to fill, the secrets that were provided (blank ones are
     * left out so the stored value is kept) and the authorized departments.
     * Creating also fills the channel and its provider; a web chat
     * connection never fills Meta identifiers or secrets.
     *
     * @return array{attributes: array<string, mixed>, secrets: array<string, string>, department_ids: list<int>}
     */
    public function payload(): array
    {
        $channel = $this->channel();
        $isWhatsapp = $channel === ConnectionChannel::Whatsapp;
        $secrets = [];

        foreach (array_keys(ConnectionSecrets::FIELDS) as $field) {
            if ($isWhatsapp && $this->filled($field)) {
                $secrets[$field] = (string) $this->input($field);
            }
        }

        $creation = $this->route('connection') instanceof Connection
            ? []
            : ['channel' => $channel, 'provider' => $channel->defaultProvider()];

        $meta = $isWhatsapp ? [
            'display_phone_number' => $this->nullableString('display_phone_number'),
            'phone_number_id' => $this->nullableString('phone_number_id'),
            'business_account_id' => $this->nullableString('business_account_id'),
            'meta_app_id' => $this->nullableString('meta_app_id'),
        ] : [];

        return [
            'attributes' => [
                ...$creation,
                'name' => $this->string('name')->value(),
                ...$meta,
                'default_department_id' => $this->filled('default_department_id') ? $this->integer('default_department_id') : null,
                'welcome_enabled' => $this->boolean('welcome_enabled'),
                'welcome_message' => $this->nullableString('welcome_message'),
                'away_enabled' => $this->boolean('away_enabled'),
                'away_message' => $this->nullableString('away_message'),
                'farewell_enabled' => $this->boolean('farewell_enabled'),
                'farewell_message' => $this->nullableString('farewell_message'),
                'reopen_window_minutes' => $this->filled('reopen_window_minutes') ? $this->integer('reopen_window_minutes') : null,
            ],
            'secrets' => $secrets,
            'department_ids' => $this->departmentIds(),
        ];
    }

    /**
     * @return list<int>
     */
    private function departmentIds(): array
    {
        $ids = $this->input('department_ids');

        if (! is_array($ids)) {
            return [];
        }

        return array_values(array_unique(array_map('intval', array_filter($ids, 'is_numeric'))));
    }

    private function nullableString(string $field): ?string
    {
        $value = $this->input($field);

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return trim($value);
    }
}
