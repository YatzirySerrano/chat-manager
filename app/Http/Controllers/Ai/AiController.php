<?php

namespace App\Http\Controllers\Ai;

use App\Actions\Ai\SaveAiSettings;
use App\Enums\AiVendor;
use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Http\Requests\Ai\AiSettingsRequest;
use App\Models\Ai\AiConnectionSetting;
use App\Models\Ai\AiSetting;
use App\Models\Connection;
use App\Models\Department;
use App\Models\Message;
use App\Models\User;
use App\Services\Ai\AiAssistant;
use App\Services\Ai\AiProviderException;
use App\Services\Ai\AiProviders;
use App\Services\Automation\AutomaticMessageKind;
use App\Support\SaveToast;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * «Inteligencia artificial» (Administración): the provider and API key, the
 * business information and catalog, and the connections where the
 * assistant answers. Everything it does at runtime lives in
 * App\Services\Ai\AiAssistant. The API key never travels to the browser:
 * only whether one is saved and its last four characters.
 */
class AiController extends Controller
{
    /** Days of the activity figures shown on the page. */
    public const ACTIVITY_DAYS = 7;

    public function index(Request $request): Response
    {
        $this->authorizeAi($request);

        $settings = AiSetting::current();
        $connectionSettings = AiConnectionSetting::query()->get()->keyBy('connection_id');
        $replies = Message::query()
            ->where('messages.created_at', '>=', now()->subDays(self::ACTIVITY_DAYS))
            ->where('messages.metadata->automatic_kind', AutomaticMessageKind::AiReply->value)
            ->join('conversations', 'conversations.id', '=', 'messages.conversation_id')
            ->selectRaw('conversations.connection_id, count(*) as total')
            ->groupBy('conversations.connection_id')
            ->pluck('total', 'conversations.connection_id');

        $connections = Connection::query()->notDeleted()->orderBy('name')->orderBy('id')->get();

        return Inertia::render('ai/Index', [
            'settings' => [
                'provider' => $settings->provider->value,
                'model' => $settings->resolvedModel(),
                'has_api_key' => $settings->hasApiKey(),
                'api_key_hint' => $settings->maskedApiKey(),
                'business_info' => $settings->business_info ?? '',
                'catalog' => $settings->catalog ?? [],
                'instructions' => $settings->instructions ?? '',
                'assistant_name' => $settings->assistant_name ?? '',
                'handoff_message' => $settings->handoff_message ?? '',
                'max_replies' => $settings->max_replies,
                'is_ready' => $settings->isReady(),
                'last_error' => $settings->last_error_type === null ? null : [
                    'message' => AiProviderException::describe($settings->last_error_type),
                    'at' => $settings->last_error_at?->toIso8601String(),
                ],
                'last_reply_at' => $settings->last_reply_at?->toIso8601String(),
            ],
            'vendors' => AiVendor::options(),
            'connections' => $connections->map(function (Connection $connection) use ($connectionSettings, $replies): array {
                /** @var AiConnectionSetting|null $setting */
                $setting = $connectionSettings->get($connection->id);

                return [
                    'id' => $connection->id,
                    'name' => $connection->name,
                    'channel' => $connection->provider->label(),
                    'is_active' => $connection->is_active,
                    'supported' => AiAssistant::supports($connection),
                    'is_enabled' => ($setting->is_enabled ?? false) && AiAssistant::supports($connection),
                    'handoff_department_id' => $setting?->handoff_department_id,
                    'only_outside_hours' => $setting->only_outside_hours ?? false,
                    'replies' => (int) $replies->get($connection->id, 0),
                ];
            })->values()->all(),
            'departments' => Department::query()->active()->orderBy('name')->get(['id', 'name'])
                ->map(fn (Department $department): array => ['id' => $department->id, 'name' => $department->name])
                ->values()->all(),
            'defaults' => [
                'assistant_name' => AiSetting::DEFAULT_ASSISTANT_NAME,
                'handoff_message' => AiSetting::DEFAULT_HANDOFF_MESSAGE,
            ],
            'limits' => [
                'business_info' => AiSettingsRequest::BUSINESS_INFO_MAX_LENGTH,
                'catalog' => AiSettingsRequest::MAX_CATALOG_ENTRIES,
                'question' => AiSettingsRequest::QUESTION_MAX_LENGTH,
                'answer' => AiSettingsRequest::ANSWER_MAX_LENGTH,
                'instructions' => AiSettingsRequest::INSTRUCTIONS_MAX_LENGTH,
                'handoff' => AiSettingsRequest::HANDOFF_MAX_LENGTH,
                'max_replies' => AiSettingsRequest::MAX_REPLIES_LIMIT,
            ],
            'activityDays' => self::ACTIVITY_DAYS,
        ]);
    }

    public function update(AiSettingsRequest $request, SaveAiSettings $save): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        SaveToast::flash($save->handle($request->payload(), $actor), 'Inteligencia artificial guardada.');

        return back();
    }

    public function updateConnection(Request $request, Connection $connection, SaveAiSettings $save): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $this->authorizeAi($request);
        abort_if($connection->isDeleted(), 404);

        $data = $request->validate([
            'is_enabled' => ['required', 'boolean'],
            'handoff_department_id' => ['nullable', 'integer', Rule::exists('departments', 'id')],
            'only_outside_hours' => ['required', 'boolean'],
        ]);

        if ($data['is_enabled'] && ! AiAssistant::supports($connection)) {
            Inertia::flash('toast', ['type' => 'info', 'message' => 'Próximamente en conexiones de Meta.']);

            return back();
        }

        $changed = $save->connection($connection, [
            'is_enabled' => (bool) $data['is_enabled'],
            'handoff_department_id' => isset($data['handoff_department_id']) ? (int) $data['handoff_department_id'] : null,
            'only_outside_hours' => (bool) $data['only_outside_hours'],
        ], $actor);

        SaveToast::flash($changed, $data['is_enabled'] ? "La IA contesta en {$connection->name}." : "La IA ya no contesta en {$connection->name}.");

        return back();
    }

    /**
     * «Probar conexión»: the key typed in the form (or the saved one) with
     * the chosen model, through the smallest possible request.
     */
    public function test(Request $request, AiProviders $providers): RedirectResponse
    {
        $this->authorizeAi($request);

        $data = $request->validate([
            'provider' => ['required', Rule::enum(AiVendor::class)],
            'model' => ['nullable', 'string', 'max:100', 'regex:/^[A-Za-z0-9._:\-\/]+$/'],
            'api_key' => ['nullable', 'string', 'max:300'],
        ]);

        $vendor = AiVendor::from((string) $data['provider']);
        $settings = AiSetting::current();
        $typed = is_string($data['api_key'] ?? null) ? trim($data['api_key']) : '';
        $key = $typed !== '' ? $typed : ($settings->provider === $vendor ? (string) $settings->api_key : '');
        $model = is_string($data['model'] ?? null) && trim($data['model']) !== '' ? trim($data['model']) : $vendor->defaultModel();

        if ($key === '') {
            Inertia::flash('toast', ['type' => 'error', 'message' => 'Escribe la clave de API para probarla.']);

            return back();
        }

        try {
            $providers->for($vendor)->test($key, $model);
            Inertia::flash('toast', ['type' => 'success', 'message' => "Conexión correcta con {$vendor->label()} ({$model})."]);
        } catch (AiProviderException $exception) {
            Inertia::flash('toast', ['type' => 'error', 'message' => $exception->getMessage()]);
        }

        return back();
    }

    private function authorizeAi(Request $request): void
    {
        $user = $request->user();

        abort_unless($user instanceof User && $user->holdsPermission(Permission::ManageAi), 403);
    }
}
