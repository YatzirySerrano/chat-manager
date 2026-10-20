<?php

namespace App\Http\Requests\Contacts;

use App\Enums\ContactOriginType;
use Illuminate\Auth\Access\Response;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Manual declaration of how a contact reached the company.
 */
class ClassifyContactOriginRequest extends FormRequest
{
    /**
     * A contact outside the actor's scope answers 404 (see ContactPolicy).
     */
    public function authorize(): Response
    {
        return Gate::forUser($this->user())->inspect('classifyOrigin', $this->route('contact'));
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'origin_type' => ['required', Rule::enum(ContactOriginType::class)->except([ContactOriginType::Unknown, ContactOriginType::MetaAd, ContactOriginType::MetaPost])],
            'ad_platform' => ['nullable', 'string', 'max:40'],
            'campaign_name' => ['nullable', 'string', 'max:255'],
            'page_name' => ['nullable', 'string', 'max:255'],
            'source_url' => ['nullable', 'string', 'max:2048', 'url:http,https'],
            'notes' => ['nullable', 'string', 'max:1000'],
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
            'origin_type' => 'tipo de origen',
            'ad_platform' => 'plataforma',
            'campaign_name' => 'campaña',
            'page_name' => 'página',
            'source_url' => 'URL de referencia',
            'notes' => 'notas',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'source_url.url' => 'La URL de referencia debe comenzar con http:// o https://.',
        ];
    }

    /**
     * @return array{origin_type: ContactOriginType, ad_platform: string|null, campaign_name: string|null, page_name: string|null, source_url: string|null, notes: string|null}
     */
    public function payload(): array
    {
        $optional = fn (string $key): ?string => $this->filled($key) ? $this->string($key)->squish()->value() : null;

        return [
            'origin_type' => $this->enum('origin_type', ContactOriginType::class) ?? ContactOriginType::Other,
            'ad_platform' => $optional('ad_platform'),
            'campaign_name' => $optional('campaign_name'),
            'page_name' => $optional('page_name'),
            'source_url' => $this->filled('source_url') ? $this->string('source_url')->trim()->value() : null,
            'notes' => $this->filled('notes') ? $this->string('notes')->trim()->value() : null,
        ];
    }
}
