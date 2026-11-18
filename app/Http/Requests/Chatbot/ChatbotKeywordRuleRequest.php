<?php

namespace App\Http\Requests\Chatbot;

use App\Http\Requests\Admin\ConnectionRequest;
use App\Models\ChatbotKeywordRule;
use App\Services\Chatbot\ChatbotKeywords;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Validator;

/**
 * A keyword rule of the chatbot. Keywords arrive comma separated (or as a
 * list); blanks and repetitions are dropped (ChatbotKeywords::parse()).
 */
class ChatbotKeywordRuleRequest extends FormRequest
{
    public const NAME_MAX_LENGTH = 80;

    public const KEYWORD_MAX_LENGTH = 60;

    public const MAX_KEYWORDS = 30;

    public const MAX_THROTTLE_MINUTES = 1440;

    public function authorize(): bool
    {
        $rule = $this->route('rule');

        return $rule instanceof ChatbotKeywordRule
            ? ($this->user()?->can('update', $rule) ?? false)
            : ($this->user()?->can('create', ChatbotKeywordRule::class) ?? false);
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('name'))) {
            $this->merge(['name' => Str::squish($this->input('name'))]);
        }
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:'.self::NAME_MAX_LENGTH],
            'keywords' => ['required'],
            'reply_message' => ['required', 'string', 'max:'.ConnectionRequest::MESSAGE_MAX_LENGTH],
            'is_active' => ['boolean'],
            'throttle_minutes' => ['required', 'integer', 'min:1', 'max:'.self::MAX_THROTTLE_MINUTES],
            'connection_ids' => ['present', 'array'],
            'connection_ids.*' => ['integer', 'distinct', 'exists:connections,id'],
            'department_ids' => ['present', 'array'],
            'department_ids.*' => ['integer', 'distinct', 'exists:departments,id'],
        ];
    }

    /**
     * @return list<Closure(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->has('keywords')) {
                    return;
                }

                $keywords = $this->keywords();

                if ($keywords === []) {
                    $validator->errors()->add('keywords', 'Escribe al menos una palabra clave.');
                } elseif (count($keywords) > self::MAX_KEYWORDS) {
                    $validator->errors()->add('keywords', 'Una regla admite hasta '.self::MAX_KEYWORDS.' palabras clave.');
                } elseif (collect($keywords)->contains(fn (string $keyword): bool => mb_strlen($keyword) > self::KEYWORD_MAX_LENGTH)) {
                    $validator->errors()->add('keywords', 'Cada palabra clave admite hasta '.self::KEYWORD_MAX_LENGTH.' caracteres.');
                }
            },
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name' => 'nombre',
            'keywords' => 'palabras clave',
            'reply_message' => 'respuesta',
            'throttle_minutes' => 'espera entre respuestas',
            'connection_ids' => 'conexiones',
            'department_ids' => 'departamentos',
        ];
    }

    /**
     * @return list<string>
     */
    public function keywords(): array
    {
        $keywords = $this->input('keywords');

        if (is_array($keywords)) {
            return ChatbotKeywords::parse(array_values(array_filter($keywords, 'is_string')));
        }

        return is_string($keywords) ? ChatbotKeywords::parse($keywords) : [];
    }

    /**
     * @return array{name: string, keywords: list<string>, reply_message: string, is_active: bool, throttle_minutes: int, connection_ids: list<int>, department_ids: list<int>}
     */
    public function payload(): array
    {
        return [
            'name' => $this->string('name')->trim()->value(),
            'keywords' => $this->keywords(),
            'reply_message' => trim(str_replace("\r\n", "\n", $this->string('reply_message')->value())),
            'is_active' => $this->boolean('is_active', true),
            'throttle_minutes' => $this->integer('throttle_minutes'),
            'connection_ids' => array_values(array_unique(array_map('intval', (array) $this->input('connection_ids', [])))),
            'department_ids' => array_values(array_unique(array_map('intval', (array) $this->input('department_ids', [])))),
        ];
    }
}
