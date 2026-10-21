<?php

use App\Enums\ContactOriginType;
use App\Enums\ConversationStatus;
use App\Enums\CustomerWindowStatus;
use App\Models\AuditLog;
use App\Models\Connection;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Department;
use App\Models\Tag;
use App\Models\User;
use App\Services\Conversations\CustomerServiceWindow;
use App\Support\ManualValidation;
use App\Support\ManualValidationCleanup;
use Database\Seeders\Local\ManualValidationSeeder;

/*
| The manual validation scenario (local development only). Its entry points
| refuse this environment, so the scenario itself is built here directly.
*/

beforeEach(function () {
    $this->admin = User::factory()->administrator()->create();
    $this->credentials = storage_path('framework/testing/demo-b-credentials.txt');
    @unlink($this->credentials);
});

afterEach(fn () => @unlink($this->credentials));

test('the seeder and the local tools refuse to run outside a local machine on the application database', function () {
    expect(fn () => (new ManualValidationSeeder)->run())->toThrow(RuntimeException::class, 'only runs with APP_ENV=local');

    $this->artisan('chat-manager:simulate-inbound', ['from' => 'laura', '--confirm' => true])->assertFailed();
    $this->artisan('chat-manager:demo-cleanup', ['--confirm' => true])->assertFailed();

    // Even with APP_ENV=local, the testing database is not the application one.
    app()->detectEnvironment(fn () => 'local');
    expect(fn () => ManualValidation::ensureAllowed())->toThrow(RuntimeException::class, 'only runs on the local application database "chatmanager"');

    app()->detectEnvironment(fn () => 'production');
    expect(fn () => ManualValidation::ensureAllowed())->toThrow(RuntimeException::class, 'never runs in production');

    expect(Connection::query()->count())->toBe(0)
        ->and(Contact::query()->count())->toBe(0);
});

test('the scenario is small, goes through the real services and can be built again without duplicates', function () {
    $first = (new ManualValidationSeeder)->build($this->credentials);
    $second = (new ManualValidationSeeder)->build($this->credentials);

    expect($second['conversations'])->toBe($first['conversations'])
        ->and(User::query()->where('email', 'like', '%@'.ManualValidation::EMAIL_DOMAIN)->count())->toBe(2)
        ->and(Department::query()->whereIn('slug', ManualValidation::DEPARTMENTS)->count())->toBe(2)
        ->and(Connection::query()->count())->toBe(1)
        ->and(Contact::query()->count())->toBe(3)
        ->and(Conversation::query()->count())->toBe(3)
        ->and(Tag::query()->whereIn('slug', ManualValidation::TAGS)->count())->toBe(2)
        ->and(Contact::query()->where('phone_e164', 'like', ManualValidation::demoPhonePattern())->count())->toBe(3);

    $window = app(CustomerServiceWindow::class);
    $pending = Conversation::query()->findOrFail($first['conversations']['pending']);
    $onHold = Conversation::query()->findOrFail($first['conversations']['on_hold']);
    $closed = Conversation::query()->findOrFail($first['conversations']['closed']);

    expect($pending->status)->toBe(ConversationStatus::Pending)
        ->and($pending->assigned_user_id)->toBeNull()
        ->and($window->for($pending)->status)->toBe(CustomerWindowStatus::Open)
        ->and($pending->contact->attributions()->first()?->origin_type)->toBe(ContactOriginType::MetaAd)
        ->and($onHold->status)->toBe(ConversationStatus::OnHold)
        ->and($onHold->notes()->count())->toBe(1)
        ->and($onHold->contact->source->value)->toBe('manual')
        ->and($onHold->contact->identities()->count())->toBe(1)
        ->and($closed->status)->toBe(ConversationStatus::Closed)
        ->and($closed->department?->slug)->toBe(ManualValidation::DEPARTMENTS['support'])
        ->and($window->for($closed)->status)->toBe(CustomerWindowStatus::Expired)
        ->and(file_exists($this->credentials))->toBeTrue()
        ->and(decoct(fileperms($this->credentials) & 0777))->toBe('600');
});

test('the cleanup removes only the scenario and keeps real data and the audit trail', function () {
    $realDepartment = Department::factory()->create(['name' => 'Ventas reales']);
    $realContact = Contact::factory()->create(['name' => 'Cliente real', 'phone_e164' => '+527774428209']);
    $realTag = Tag::factory()->create();
    $realContact->tags()->attach($realTag->id);

    (new ManualValidationSeeder)->build($this->credentials);
    $audits = AuditLog::query()->count();

    $plan = app(ManualValidationCleanup::class)->execute();

    expect($plan['blockers'])->toBe([])
        ->and(Connection::query()->count())->toBe(0)
        ->and(Conversation::query()->count())->toBe(0)
        ->and(Contact::query()->pluck('id')->all())->toBe([$realContact->id])
        ->and(User::query()->pluck('id')->all())->toBe([$this->admin->id])
        ->and(Department::query()->pluck('id')->all())->toBe([$realDepartment->id])
        ->and(Tag::query()->pluck('id')->all())->toBe([$realTag->id])
        ->and($realContact->tags()->pluck('tags.id')->all())->toBe([$realTag->id])
        ->and($this->admin->fresh()->role()?->is_root)->toBeTrue()
        ->and(AuditLog::query()->count())->toBe($audits);
});

test('the cleanup removes nothing while real data depends on the scenario', function () {
    (new ManualValidationSeeder)->build($this->credentials);
    $demoAgent = User::query()->where('email', 'like', '%@'.ManualValidation::EMAIL_DOMAIN)->firstOrFail();
    $realConversation = Conversation::factory()->assignedTo($demoAgent)->create();

    $plan = app(ManualValidationCleanup::class)->execute();

    expect($plan['blockers'])->toHaveCount(1)
        ->and($plan['blockers'][0])->toContain("#{$realConversation->id}")
        ->and(Connection::query()->where('phone_number_id', ManualValidation::PHONE_NUMBER_ID)->exists())->toBeTrue()
        ->and(Conversation::query()->count())->toBe(4)
        ->and($demoAgent->fresh())->not->toBeNull();
});
