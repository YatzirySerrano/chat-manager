<?php

namespace App\Http\Requests\Ai;

use App\Enums\AiVendor;
use App\Enums\Permission;
use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * The configuration of «Inteligencia artificial». The API key arrives only
 * when the administrator types a new one (`api_key`) or removes the saved
 * one (`remove_api_key`); otherwise the saved key is kept.
 */
class AiSettingsRequest extends FormRequest
{
    public const BUSINESS_INFO_MAX_LENGTH = 20000;

    public const MAX_CATALOG_ENTRIES = 100;

    public const QUESTION_MAX_LENGTH = 300;

    public const ANSWER_MAX_LENGTH = 2000;

    public const INSTRUCTIONS_MAX_LENGTH = 2000;

    public const HANDOFF_MAX_LENGTH = 500;

    public const MAX_REPLIES_LIMIT = 50;

    public function authorize(): bool
    {
        $user = $this->user();

        return $user instanceof User && $user->holdsPermission(Permission::ManageAi);
    }

    protected function prepareForValidation(): void
    {
        $catalog = $this->input('catalog');

        $this->merge([
            'api_key' => is_string($this->input('api_key')) ? trim($this->input('api_key')) : null,
            'model' => is_string($this->input('model')) ? trim($this->input('model')) : null,
            'assistant_name' => is_string($this->input('assistant_name')) ? Str::squish($this->input('assistant_name')) : null,
            // Rows left completely blank are dropped, not rejected.
            'catalog' => is_array($catalog) ? array_values(array_filter($catalog, fn (mixed $entry): bool => is_array($entry)
                && (trim((string) ($entry['question'] ?? '')) !== '' || trim((string) ($entry['answer'] ?? '')) !== ''))) : $catalog,
        ]);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'provider' => ['required', Rule::enum(AiVendor::class)],
            'model' => ['nullable', 'string', 'max:100', 'regex:/^[A-Za-z0-9._:\-\/]+$/'],
            'api_key' => ['nullable', 'string', 'min:20', 'max:300', 'regex:/^\S+$/'],
            'remove_api_key' => ['boolean'],
            'business_info' => ['nullable', 'string', 'max:'.self::BUSINESS_INFO_MAX_LENGTH],
            'catalog' => ['present', 'array', 'max:'.self::MAX_CATALOG_ENTRIES],
            'catalog.*.question' => ['required', 'string', 'max:'.self::QUESTION_MAX_LENGTH],
            'catalog.*.answer' => ['required', 'string', 'max:'.self::ANSWER_MAX_LENGTH],
            'instructions' => ['nullable', 'string', 'max:'.self::INSTRUCTIONS_MAX_LENGTH],
            'assistant_name' => ['nullable', 'string', 'max:80'],
            'handoff_message' => ['nullable', 'string', 'max:'.self::HANDOFF_MAX_LENGTH],
            'max_replies' => ['required', 'integer', 'min:1', 'max:'.self::MAX_REPLIES_LIMIT],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'provider' => 'proveedor',
            'model' => 'modelo',
            'api_key' => 'clave de API',
            'business_info' => 'información del negocio',
            'catalog' => 'catálogo de respuestas',
            'catalog.*.question' => 'pregunta',
            'catalog.*.answer' => 'respuesta',
            'instructions' => 'tono e indicaciones',
            'assistant_name' => 'nombre del asistente',
            'handoff_message' => 'mensaje al pasar con un asesor',
            'max_replies' => 'respuestas por conversación',
        ];
    }

    /**
     * @return array{provider: AiVendor, model: string|null, api_key: string|null, remove_api_key: bool, business_info: string|null, catalog: list<array{question: string, answer: string}>, instructions: string|null, assistant_name: string|null, handoff_message: string|null, max_replies: int}
     */
    public function payload(): array
    {
        /** @var array<string, mixed> $data */
        $data = $this->validated();
        $text = fn (string $key): ?string => is_string($data[$key] ?? null) && trim($data[$key]) !== '' ? trim($data[$key]) : null;

        /** @var list<array{question: string, answer: string}> $catalog */
        $catalog = array_map(fn (array $entry): array => [
            'question' => trim((string) $entry['question']),
            'answer' => trim((string) $entry['answer']),
        ], array_values($data['catalog']));

        return [
            'provider' => AiVendor::from((string) $data['provider']),
            'model' => $text('model'),
            'api_key' => $text('api_key'),
            'remove_api_key' => (bool) ($data['remove_api_key'] ?? false),
            'business_info' => $text('business_info'),
            'catalog' => $catalog,
            'instructions' => $text('instructions'),
            'assistant_name' => $text('assistant_name'),
            'handoff_message' => $text('handoff_message'),
            'max_replies' => (int) $data['max_replies'],
        ];
    }
}
