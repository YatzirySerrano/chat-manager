<?php

namespace App\Http\Middleware;

use App\Actions\TeamChat\TeamUnreadCounter;
use App\Broadcasting\RealtimeClientConfig;
use App\Enums\Permission;
use App\Models\User;
use App\Services\PlatformSettings;
use App\Support\PasswordPolicy;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    public function __construct(private PlatformSettings $settings) {}

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();
        $settings = $this->settings->all();

        return [
            ...parent::share($request),
            'name' => $settings['general']['platform_name'],
            'platform' => [
                'name' => $settings['general']['platform_name'],
                'timezone' => $settings['general']['timezone'],
                'palette' => $settings['appearance']['palette'],
            ],
            'auth' => [
                'user' => $user,
                'role' => $user instanceof User ? $this->roleSummary($user) : null,
                'permissions' => $user instanceof User ? $user->effectivePermissions() : [],
            ],
            'passwordPolicy' => fn (): array => PasswordPolicy::current(),
            'realtime' => fn (): array => app(RealtimeClientConfig::class)->for($user instanceof User ? $user : null),
            'teamChatUnread' => fn (): ?int => $user instanceof User && $user->holdsPermission(Permission::UseTeamChat)
                ? app(TeamUnreadCounter::class)->total($user)
                : null,
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
        ];
    }

    /**
     * @return array{id: int, label: string, scope: string, is_root: bool, is_active: bool}|null
     */
    private function roleSummary(User $user): ?array
    {
        $role = $user->role();

        return $role === null ? null : [
            'id' => $role->id,
            'label' => $role->label,
            'scope' => $role->scope->value,
            'is_root' => $role->is_root,
            'is_active' => $role->is_active,
        ];
    }
}
