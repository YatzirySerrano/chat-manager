<?php

use App\Enums\DataScope;
use App\Models\Connection;
use App\Models\Contact;
use App\Models\ContactReviewCase;
use App\Models\Conversation;
use App\Models\ConversationNote;
use App\Models\DataTransfer;
use App\Models\Department;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Models\PushSubscription;
use App\Models\QuickReply;
use App\Models\Role;
use App\Models\Tag;
use App\Models\TeamChat\TeamAttachment;
use App\Models\TeamChat\TeamMessage;
use App\Models\TeamChat\TeamThread;
use App\Models\User;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as Router;
use Illuminate\Support\Str;
use Tests\Feature\Templates\TemplateFixtures;

/*
| Route-wide authorization sweep. Every route of the application (vendor
| routes excluded) is called by a guest and by a signed in user whose role
| grants no permission at all, against real records created by somebody
| else. Nothing may succeed, redirect into data, fail validation before
| authorization or crash: the only acceptable answers are "sign in", 403 and
| 404 (410 for expired files). New routes are covered automatically; a route
| that is legitimately self-service must be listed explicitly below.
*/

/**
 * Routes that answer without a session by design.
 *
 * @var list<string>
 */
const PUBLIC_ROUTES = [
    'home',
    'health.ready',
    'webhooks.whatsapp.verify',
    'webhooks.whatsapp.receive',
];

/**
 * Self-service routes every signed in, active user may use on their own
 * account (profile, password, own notifications and preferences, availability).
 *
 * @var list<string>
 */
const SELF_SERVICE_ROUTES = [
    'dashboard',
    'profile.edit',
    'profile.update',
    'security.edit',
    'user-password.update',
    'notifications.index',
    'notifications.summary',
    'notifications.read',
    'notifications.read-all',
    'notifications.conversations.read',
    'notifications.preferences.edit',
    'notifications.preferences.update',
    'notifications.push-subscriptions.store',
    'conversations.availability.update',
];

/**
 * @return list<Route>
 */
function applicationRoutes(): array
{
    return collect(Router::getRoutes()->getRoutes())
        ->filter(fn (Route $route): bool => str_starts_with($route->getActionName(), 'App\\')
            || ($route->getName() !== null && in_array($route->getName(), ['home'], true))
            || $route->uri() === 'settings')
        ->values()
        ->all();
}

/**
 * @param  array<string, int|string>  $parameters
 */
function sweepUri(Route $route, array $parameters): string
{
    return '/'.ltrim((string) preg_replace_callback('/\{(\w+)\??\}/', function (array $match) use ($route, $parameters): string {
        $name = $match[1];

        if (! array_key_exists($name, $parameters)) {
            throw new LogicException("The sweep has no value for the [{$name}] parameter of [{$route->uri()}].");
        }

        return (string) $parameters[$name];
    }, $route->uri()), '/');
}

function sweepMethod(Route $route): string
{
    return collect($route->methods())->reject(fn (string $method): bool => $method === 'HEAD')->first() ?? 'GET';
}

beforeEach(function () {
    $root = User::factory()->superAdministrator()->create();
    $department = Department::factory()->create();
    $colleague = User::factory()->agent()->inDepartments($department)->create();
    $connection = Connection::factory()->operational()->create();
    $contact = Contact::factory()->create();
    $conversation = Conversation::factory()->inDepartment($department)->assignedTo($colleague)->create(['contact_id' => $contact->id, 'connection_id' => $connection->id]);
    $message = Message::factory()->create(['conversation_id' => $conversation->id]);
    $attachment = new MessageAttachment(['disk' => 'local', 'path' => 'media/sweep/'.Str::uuid(), 'mime_type' => 'image/png', 'file_name' => 'foto.png', 'size_bytes' => 10]);
    $attachment->forceFill(['message_id' => $message->id])->save();
    $note = ConversationNote::factory()->create(['conversation_id' => $conversation->id, 'user_id' => $colleague->id]);
    $thread = TeamThread::factory()->withParticipants([$colleague], [$root])->create();
    $teamMessage = TeamMessage::factory()->in($thread, $root)->create();
    $teamAttachment = TeamAttachment::factory()->create(['team_message_id' => $teamMessage->id]);
    $export = DataTransfer::factory()->completed()->create(['user_id' => $root->id]);
    $template = TemplateFixtures::stored($connection);

    $this->parameters = [
        'role' => Role::query()->where('is_root', false)->value('id'),
        'user' => $colleague->id,
        'member' => $colleague->id,
        'connection' => $connection->id,
        'contact' => $contact->id,
        'contactReviewCase' => ContactReviewCase::factory()->create()->id,
        'transfer' => $export->id,
        'conversation' => $conversation->id,
        'message' => $message->id,
        'attachment' => $attachment->id,
        'note' => $note->id,
        'department' => $department->id,
        'quickReply' => QuickReply::factory()->create()->id,
        'tag' => Tag::factory()->create()->id,
        'thread' => $thread->id,
        'template' => $template->id,
        'uuid' => (string) Str::uuid(),
        'notification' => (string) Str::uuid(),
        'subscription' => PushSubscription::factory()->create(['user_id' => $colleague->id])->id,
        'report' => 'contacts',
        'format' => 'xlsx',
        'webhookKey' => (string) $connection->webhook_key,
    ];

    $this->teamParameters = [...$this->parameters, 'message' => $teamMessage->id, 'attachment' => $teamAttachment->id];
});

/**
 * @return array<string, int|string>
 */
function parametersFor(Route $route, object $test): array
{
    return str_starts_with((string) $route->getName(), 'team-chat.') ? $test->teamParameters : $test->parameters;
}

test('every application route asks a guest to sign in', function () {
    $failures = [];

    foreach (applicationRoutes() as $route) {
        if (in_array($route->getName(), PUBLIC_ROUTES, true)) {
            continue;
        }

        $method = sweepMethod($route);
        $response = $this->json($method, sweepUri($route, parametersFor($route, $this)));

        if ($response->getStatusCode() !== 401) {
            $failures[] = "{$method} {$route->uri()} answered {$response->getStatusCode()}";
        }
    }

    expect($failures)->toBe([]);
});

test('a user whose role grants nothing reaches no record and no operation', function () {
    $nobody = User::factory()->withRole(Role::factory()->scope(DataScope::Assigned)->withPermissions([])->create())->create();
    $failures = [];

    foreach (applicationRoutes() as $route) {
        if (in_array($route->getName(), [...PUBLIC_ROUTES, ...SELF_SERVICE_ROUTES], true) || $route->uri() === 'settings') {
            continue;
        }

        $method = sweepMethod($route);
        $response = $this->actingAs($nobody)->json($method, sweepUri($route, parametersFor($route, $this)));

        if (! in_array($response->getStatusCode(), [403, 404], true)) {
            $failures[] = "{$method} {$route->uri()} answered {$response->getStatusCode()}";
        }
    }

    expect($failures)->toBe([]);
});

test('an ordinary agent reaches no record of a colleague through any nested id', function () {
    $outsider = User::factory()->agent()->inDepartments(Department::factory()->create())->create();
    $failures = [];

    foreach (applicationRoutes() as $route) {
        if (in_array($route->getName(), [...PUBLIC_ROUTES, ...SELF_SERVICE_ROUTES], true)
            || preg_match('/\{(conversation|thread|transfer|contact|note|attachment|contactReviewCase)\}/', $route->uri()) !== 1) {
            continue;
        }

        $method = sweepMethod($route);
        $response = $this->actingAs($outsider)->json($method, sweepUri($route, parametersFor($route, $this)));

        if (! in_array($response->getStatusCode(), [403, 404], true)) {
            $failures[] = "{$method} {$route->uri()} answered {$response->getStatusCode()}";
        }
    }

    expect($failures)->toBe([]);
});
