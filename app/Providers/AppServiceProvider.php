<?php

namespace App\Providers;

use App\Enums\Permission;
use App\Models\Connection;
use App\Models\Contact;
use App\Models\ContactAttribution;
use App\Models\ContactNote;
use App\Models\Conversation;
use App\Models\Department;
use App\Models\Message;
use App\Models\QuickReply;
use App\Models\Role;
use App\Models\Tag;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureAuthorization();
    }

    /**
     * Destructive database commands are always blocked in production and,
     * elsewhere, only allowed on a dedicated "_testing" database or when
     * explicitly enabled through DB_ALLOW_DESTRUCTIVE_COMMANDS.
     */
    protected function destructiveCommandsAreProhibited(): bool
    {
        if (app()->isProduction()) {
            return true;
        }

        $database = (string) config('database.connections.'.config('database.default').'.database');

        return ! str_ends_with($database, '_testing') && ! config('database.allow_destructive_commands');
    }

    /**
     * Register gates that are not tied to a model.
     */
    protected function configureAuthorization(): void
    {
        Gate::define('view-settings', fn (User $user): bool => $user->holdsPermission(Permission::ViewSettings));
        Gate::define('update-settings', fn (User $user): bool => $user->holdsPermission(Permission::UpdateSettings));
        Gate::define('view-appearance', fn (User $user): bool => $user->holdsPermission(Permission::ViewAppearance));
        Gate::define('update-appearance', fn (User $user): bool => $user->holdsPermission(Permission::UpdateAppearance));
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        Model::preventLazyLoading(! app()->isProduction());

        Relation::morphMap([
            'user' => User::class,
            'department' => Department::class,
            'connection' => Connection::class,
            'contact' => Contact::class,
            'contact_note' => ContactNote::class,
            'contact_attribution' => ContactAttribution::class,
            'conversation' => Conversation::class,
            'message' => Message::class,
            'role' => Role::class,
            'tag' => Tag::class,
            'quick_reply' => QuickReply::class,
        ]);

        DB::prohibitDestructiveCommands($this->destructiveCommandsAreProhibited());

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
