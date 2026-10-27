<?php

namespace App\Http\Requests\Personalization;

use App\Enums\ChatAppearancePreset;
use App\Models\ChatAppearance;
use App\Models\Conversation;
use App\Models\TeamChat\TeamThread;
use App\Support\Color\Contrast;
use Illuminate\Auth\Access\Response;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Personal chat colors: a light and a dark set of five #RRGGBB colors, an
 * optional preset and, from a chat (customer conversation or internal
 * thread), whether they apply to it only or to every chat of the user. Unreadable text colors are not rejected:
 * ChatAppearances replaces them with a readable one and says so.
 *
 * @phpstan-import-type ChatColors from ChatAppearancePreset
 */
class ChatAppearanceRequest extends FormRequest
{
    /**
     * From a chat the viewer must see it (otherwise 404, like a missing
     * one): a conversation they may view or an internal thread they
     * currently participate in. Someone else's item goes through its policy
     * (personal items supervision). All run before any validation.
     */
    public function authorize(): Response
    {
        $chat = $this->chat();

        if ($chat !== null) {
            return Gate::forUser($this->user())->allows('view', $chat) ? Response::allow() : Response::denyAsNotFound();
        }

        $item = $this->route('appearance');

        return $item instanceof ChatAppearance ? Gate::forUser($this->user())->inspect('update', $item) : Response::denyAsNotFound();
    }

    public const SCOPE_CONVERSATION = 'conversation';

    public const SCOPE_ALL = 'all';

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $rules = [
            'scope' => [Rule::requiredIf($this->chat() !== null), Rule::in([self::SCOPE_CONVERSATION, self::SCOPE_ALL])],
            'preset' => ['nullable', Rule::enum(ChatAppearancePreset::class)],
            'colors' => ['required', 'array:light,dark'],
            'colors.light' => ['required', 'array:'.implode(',', ChatAppearancePreset::COLOR_KEYS)],
            'colors.dark' => ['required', 'array:'.implode(',', ChatAppearancePreset::COLOR_KEYS)],
        ];

        foreach (['light', 'dark'] as $mode) {
            foreach (ChatAppearancePreset::COLOR_KEYS as $key) {
                $rules["colors.{$mode}.{$key}"] = ['required', 'string', 'regex:'.Contrast::HEX_PATTERN];
            }
        }

        return $rules;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'colors.*.*.regex' => 'Cada color debe tener el formato #RRGGBB (por ejemplo, #1A73E8).',
            'colors.*.*.required' => 'Elige todos los colores del modo claro y del modo oscuro.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'scope' => 'alcance',
            'preset' => 'paleta',
            'colors' => 'colores',
        ];
    }

    /**
     * The chat the viewer is personalizing, when reached from one.
     */
    public function chat(): Conversation|TeamThread|null
    {
        $chat = $this->route('conversation') ?? $this->route('thread');

        return $chat instanceof Conversation || $chat instanceof TeamThread ? $chat : null;
    }

    public function appliesToAll(): bool
    {
        return $this->validated('scope') === self::SCOPE_ALL;
    }

    public function preset(): ?ChatAppearancePreset
    {
        $preset = $this->validated('preset');

        return is_string($preset) ? ChatAppearancePreset::tryFrom($preset) : null;
    }

    /**
     * @return ChatColors
     */
    public function colors(): array
    {
        /** @var ChatColors $colors */
        $colors = $this->validated('colors');

        return $colors;
    }
}
