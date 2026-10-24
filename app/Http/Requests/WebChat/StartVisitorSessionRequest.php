<?php

namespace App\Http\Requests\WebChat;

use App\Models\WebChatWidget;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * POST /api/v1/widget/{publicKey}/sessions. The widget is resolved before
 * the body is validated: an unknown key, or the key of a deleted
 * connection, is a 404 whatever was sent.
 */
class StartVisitorSessionRequest extends FormRequest
{
    private ?WebChatWidget $widget = null;

    public function authorize(): bool
    {
        $widget = WebChatWidget::query()
            ->with('connection')
            ->where('public_key', (string) $this->route('publicKey'))
            ->first();

        if ($widget === null || $widget->connection === null || $widget->connection->isDeleted()) {
            throw new NotFoundHttpException;
        }

        $this->widget = $widget;
        $this->attributes->set('webchat_widget', $widget);

        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'ticket' => ['required', 'string', 'max:1024'],
            'profile' => ['sometimes', 'nullable', 'array:name,email'],
            'profile.name' => ['nullable', 'string', 'max:80'],
            'profile.email' => ['nullable', 'string', 'email', 'max:191'],
            'landing_url' => ['nullable', 'string', 'url:http,https', 'max:2048'],
            ...VisitorIdentityRules::rules('identity', false),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'ticket' => 'ticket',
            'profile.name' => 'nombre',
            'profile.email' => 'correo electrónico',
            'landing_url' => 'página de llegada',
            ...VisitorIdentityRules::attributes('identity'),
        ];
    }

    public function widget(): WebChatWidget
    {
        return $this->widget ?? throw new \LogicException('The widget is resolved during authorization.');
    }
}
