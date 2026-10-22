<?php

namespace App\Http\Requests\Notifications;

use App\Notifications\NotificationKind;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Every user manages their own notification preferences. Only known kinds
 * are accepted; each one needs the three switches.
 */
class UpdateNotificationPreferencesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * Sent as a list (kind values contain dots, which validation keys cannot).
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $kinds = array_map(fn (NotificationKind $kind): string => $kind->value, NotificationKind::cases());

        return [
            'preferences' => ['required', 'array', 'list', 'min:1', 'max:'.count($kinds)],
            'preferences.*' => ['required', 'array:kind,in_app,browser,sound'],
            'preferences.*.kind' => ['required', 'string', 'distinct', Rule::in($kinds)],
            'preferences.*.in_app' => ['required', 'boolean'],
            'preferences.*.browser' => ['required', 'boolean'],
            'preferences.*.sound' => ['required', 'boolean'],
        ];
    }

    /**
     * Keyed by kind value.
     *
     * @return array<string, array{in_app: bool, browser: bool, sound: bool}>
     */
    public function payload(): array
    {
        $payload = [];

        foreach (array_keys((array) $this->input('preferences', [])) as $index) {
            $payload[(string) $this->input("preferences.{$index}.kind")] = [
                'in_app' => $this->boolean("preferences.{$index}.in_app"),
                'browser' => $this->boolean("preferences.{$index}.browser"),
                'sound' => $this->boolean("preferences.{$index}.sound"),
            ];
        }

        return $payload;
    }
}
