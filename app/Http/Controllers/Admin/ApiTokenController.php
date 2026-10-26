<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Integrations\IssueApiToken;
use App\Actions\Integrations\RevokeApiToken;
use App\Enums\ApiAbility;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ApiTokenRequest;
use App\Models\ApiToken;
use App\Models\Connection;
use App\Models\Department;
use App\Models\User;
use App\Support\ProtectedAccounts;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Administración > Integraciones: the integration API tokens. A token's
 * secret is shown once, right after issuing it (one-time flash
 * "issued_token"); the list only shows its public prefix.
 */
class ApiTokenController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', ApiToken::class);

        /** @var User $actor */
        $actor = $request->user();

        $page = ApiToken::query()
            ->with(['creator:id,name', 'revoker:id,name'])
            ->latest('id')
            ->paginate(20)
            ->withQueryString();

        /** @var Collection<int, ApiToken> $tokens */
        $tokens = $page->getCollection();
        $connectionNames = Connection::query()
            ->whereIn('id', $tokens->flatMap(fn (ApiToken $token): array => $token->connection_ids)->unique()->values())
            ->pluck('name', 'id');
        $departmentNames = Department::query()
            ->whereIn('id', $tokens->flatMap(fn (ApiToken $token): array => $token->department_ids ?? [])->unique()->values())
            ->pluck('name', 'id');

        $page->through(fn (ApiToken $token): array => $this->summary($token, $connectionNames, $departmentNames));

        $maxDays = (int) config('integrations.tokens.max_days', 365);

        return Inertia::render('admin/integrations/Index', [
            'tokens' => JsonResource::collection($page),
            'abilities' => ApiAbility::options(),
            'connections' => Connection::query()
                ->notDeleted()
                ->orderBy('name')
                ->get(['id', 'name', 'channel'])
                ->map(fn (Connection $connection): array => [
                    'id' => $connection->id,
                    'name' => $connection->name,
                    'channel' => $connection->channel->value,
                    'channel_label' => $connection->channel->label(),
                ])
                ->values(),
            'departments' => Department::query()
                ->notDeleted()
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (Department $department): array => ['id' => $department->id, 'name' => $department->name])
                ->values(),
            'expiry' => [
                'default' => now()->addDays((int) config('integrations.tokens.default_days', 90))->toDateString(),
                'min' => now()->addDay()->toDateString(),
                'max' => now()->addDays($maxDays)->toDateString(),
                'max_days' => $maxDays,
            ],
            'can' => [
                'manage' => $actor->can('create', ApiToken::class),
            ],
        ]);
    }

    public function store(ApiTokenRequest $request, IssueApiToken $issue): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        ['token' => $token, 'plain' => $plain] = $issue->handle($request->payload(), $actor);

        Inertia::flash('issued_token', [
            'id' => $token->id,
            'name' => $token->name,
            'token' => $plain,
        ]);
        Inertia::flash('toast', ['type' => 'success', 'message' => "Clave de acceso «{$token->name}» creada. Cópiala ahora: no se volverá a mostrar."]);

        return to_route('integrations.tokens.index');
    }

    public function destroy(Request $request, ApiToken $apiToken, RevokeApiToken $revoke): RedirectResponse
    {
        Gate::authorize('delete', $apiToken);

        $data = $request->validate([
            'reason' => ['nullable', 'string', 'max:255'],
        ], attributes: ['reason' => 'motivo']);

        /** @var User $actor */
        $actor = $request->user();
        $reason = filled($data['reason'] ?? null) ? trim((string) $data['reason']) : null;

        $revoked = $revoke->handle($apiToken, $actor, $reason);

        Inertia::flash('toast', $revoked
            ? ['type' => 'success', 'message' => "Clave de acceso «{$apiToken->name}» revocada. El sistema que la usaba ya no puede conectarse."]
            : ['type' => 'info', 'message' => "La clave de acceso «{$apiToken->name}» ya estaba revocada."]);

        return back(fallback: route('integrations.tokens.index'));
    }

    /**
     * @param  Collection<int|string, string>  $connectionNames
     * @param  Collection<int|string, string>  $departmentNames
     * @return array<string, mixed>
     */
    private function summary(ApiToken $token, Collection $connectionNames, Collection $departmentNames): array
    {
        $status = match (true) {
            $token->revoked_at !== null => 'revoked',
            $token->expires_at->isPast() => 'expired',
            default => 'active',
        };

        return [
            'id' => $token->id,
            'name' => $token->name,
            'prefix' => ApiToken::TOKEN_PREFIX.$token->public_id,
            'abilities' => $token->abilities,
            'connections' => array_map(fn (int $id): array => [
                'id' => $id,
                'name' => $connectionNames->get($id) ?? "Conexión #{$id}",
            ], array_map('intval', $token->connection_ids)),
            'departments' => $token->department_ids === null ? null : array_map(fn (int $id): array => [
                'id' => $id,
                'name' => $departmentNames->get($id) ?? "Departamento #{$id}",
            ], array_map('intval', $token->department_ids)),
            'allowed_ips' => $token->allowed_ips,
            'status' => $status,
            'expires_at' => $token->expires_at->toIso8601String(),
            'last_used_at' => $token->last_used_at?->toIso8601String(),
            'created_at' => $token->created_at?->toIso8601String(),
            'created_by' => app(ProtectedAccounts::class)->nameForRequest($token->creator, request()),
            'revoked_at' => $token->revoked_at?->toIso8601String(),
            'revoked_by' => app(ProtectedAccounts::class)->nameForRequest($token->revoker, request()),
            'revoke_reason' => $token->revoke_reason,
        ];
    }
}
