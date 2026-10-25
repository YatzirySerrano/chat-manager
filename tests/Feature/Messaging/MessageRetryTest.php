<?php

use App\Enums\AuditAction;
use App\Enums\ConversationEventType;
use App\Enums\MessageStatus;
use App\Enums\MessageType;
use App\Http\Resources\ConversationMessageResource;
use App\Jobs\DeliverOutboundMessage;
use App\Models\AuditLog;
use App\Models\Conversation;
use App\Models\Department;
use App\Models\Message;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Templates\TemplateFixtures;
use Tests\Support\WhatsAppFixtures;

/*
| Manual retry of a failed outbound message: authorized like a reply, only
| within the same conversation, with explicit confirmation when Meta may
| already have accepted the first attempt.
*/

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-20 15:00:00', 'UTC'));
    $this->department = Department::factory()->create();
    $this->agent = User::factory()->agent()->inDepartments($this->department)->create();
    $this->conversation = Conversation::factory()->inDepartment($this->department)->assignedTo($this->agent)->create(['last_inbound_at' => now()->subHour()]);
    $this->failed = WhatsAppFixtures::outbound($this->conversation, [
        'status' => 'failed',
        'error_code' => 'provider_unreachable',
        'error_message' => 'No fue posible comunicarse con Meta.',
        'failed_at' => now()->subMinute(),
        'sender_user_id' => $this->agent->id,
        'delivery_attempts' => 5,
    ]);
    $this->retry = fn (User $user, Message $message, array $data = [], ?Conversation $conversation = null) => $this->actingAs($user)
        ->postJson(route('conversations.messages.retry', ['conversation' => $conversation ?? $this->conversation, 'message' => $message]), $data);
});

test('the responsible agent requeues a failed message and the attempt is recorded', function () {
    $transport = WhatsAppFixtures::transport();

    ($this->retry)($this->agent, $this->failed)->assertAccepted()->assertJsonPath('data.id', $this->failed->id);

    expect($this->failed->refresh())
        ->status->toBe(MessageStatus::Accepted)
        ->error_code->toBeNull()
        ->whatsapp_message_id->toBe('wamid.out.'.$this->failed->id)
        ->delivery_attempts->toBe(6)
        ->and($this->failed->metadata['delivery']['history'][0])->toMatchArray([
            'error_code' => 'provider_unreachable',
            'retried_by_user_id' => $this->agent->id,
        ])
        ->and($transport->sent)->toBe([$this->failed->id])
        ->and($this->conversation->events()->where('type', ConversationEventType::MessageRetried)->sole()->actor_user_id)->toBe($this->agent->id);
});

test('a manual retry starts a new cycle of automatic attempts', function () {
    Queue::fake();

    ($this->retry)($this->agent, $this->failed)->assertAccepted();

    expect(DeliverOutboundMessage::attemptsInCycle($this->failed->refresh()))->toBe(0);
    Queue::assertPushed(DeliverOutboundMessage::class, fn (DeliverOutboundMessage $job): bool => $job->messageId === $this->failed->id);
});

test('a retry within the same second as the failure is versioned by its retry count', function () {
    Queue::fake();
    $chatCopy = fn (): array => ConversationMessageResource::make($this->failed->refresh())->resolve(request());

    expect($chatCopy())->toMatchArray(['status' => 'failed', 'delivery_attempts' => 5, 'delivery_retries' => 0]);

    ($this->retry)($this->agent, $this->failed)->assertAccepted();

    // Requeued without a new attempt: only the retry count tells the chat that
    // this copy is newer than the failure shown within the same second.
    expect($chatCopy())->toMatchArray(['status' => 'queued', 'delivery_attempts' => 5, 'delivery_retries' => 1]);

    $this->failed->forceFill(['status' => MessageStatus::Failed, 'error_code' => 'provider_unreachable', 'failed_at' => now()])->save();
    ($this->retry)($this->agent, $this->failed)->assertAccepted();

    expect($chatCopy())->delivery_retries->toBe(2);
});

test('an unconfirmed delivery is retried only after the agent accepts the duplicate risk', function () {
    Queue::fake();
    $this->failed->forceFill(['error_code' => 'delivery_unknown'])->save();

    ($this->retry)($this->agent, $this->failed)->assertUnprocessable()->assertJsonValidationErrors('confirm');
    expect($this->failed->refresh()->status)->toBe(MessageStatus::Failed);

    ($this->retry)($this->agent, $this->failed, ['confirm' => true])->assertAccepted();

    expect($this->failed->refresh())
        ->status->toBe(MessageStatus::Queued)
        ->and($this->failed->metadata['delivery']['history'][0]['duplicate_risk_confirmed'])->toBeTrue();
});

test('accepting the duplicate risk is kept in the audit log; an ordinary retry is not', function () {
    Queue::fake();
    $ordinary = WhatsAppFixtures::outbound($this->conversation, ['status' => 'failed', 'error_code' => 'provider_unreachable', 'failed_at' => now()]);
    $this->failed->forceFill(['error_code' => 'delivery_unknown'])->save();

    ($this->retry)($this->agent, $ordinary)->assertAccepted();
    ($this->retry)($this->agent, $this->failed, ['confirm' => true])->assertAccepted();

    expect(AuditLog::query()->sole())
        ->action->toBe(AuditAction::MessageRetriedWithDuplicateRisk)
        ->user_id->toBe($this->agent->id)
        ->auditable_id->toBe($this->conversation->id)
        ->and(AuditLog::query()->sole()->properties)->toMatchArray([
            'message_id' => $this->failed->id,
            'previous_error' => 'delivery_unknown',
            'duplicate_risk_confirmed' => true,
        ]);
});

test('a message retried by the new responsible agent after a transfer is delivered', function () {
    $transport = WhatsAppFixtures::transport();
    $newAgent = User::factory()->agent()->inDepartments($this->department)->create();
    $this->conversation->forceFill(['assigned_user_id' => $newAgent->id])->save();

    ($this->retry)($newAgent, $this->failed)->assertAccepted();

    expect($this->failed->refresh()->status)->toBe(MessageStatus::Accepted)
        ->and($transport->sent)->toBe([$this->failed->id]);
});

test('only failed messages can be retried', function (string $status) {
    Queue::fake();
    $this->failed->forceFill(['status' => $status, 'error_code' => null])->save();

    ($this->retry)($this->agent, $this->failed)->assertConflict();
    Queue::assertNothingPushed();
})->with(['queued', 'sending', 'accepted', 'delivered', 'read']);

test('a message is not retried once its connection was deactivated', function () {
    Queue::fake();
    $this->conversation->channelConnection->forceFill(['is_active' => false])->save();

    ($this->retry)($this->agent, $this->failed)->assertStatus(409);

    expect($this->failed->refresh()->status)->toBe(MessageStatus::Failed);
    Queue::assertNothingPushed();
});

test('a message is not retried while Meta rejects the connection credentials', function () {
    Queue::fake();
    $this->conversation->channelConnection->forceFill(['credentials_error' => 'Invalid OAuth access token.'])->save();
    $this->failed->forceFill(['error_code' => 'delivery_unknown'])->save();

    ($this->retry)($this->agent, $this->failed, ['confirm' => true])
        ->assertConflict()
        ->assertJsonFragment(['message' => $this->conversation->channelConnection->sendingBlockReason()]);

    expect($this->failed->refresh()->status)->toBe(MessageStatus::Failed)
        ->and(AuditLog::query()->exists())->toBeFalse();
    Queue::assertNothingPushed();
});

test('a free text message is not retried once the window closed', function () {
    Queue::fake();
    $this->conversation->forceFill(['last_inbound_at' => now()->subHours(25)])->save();

    ($this->retry)($this->agent, $this->failed)->assertConflict();

    expect($this->failed->refresh()->status)->toBe(MessageStatus::Failed);
});

test('messages of another conversation, or of conversations out of reach, look nonexistent', function () {
    Queue::fake();
    $otherConversation = Conversation::factory()->inDepartment($this->department)->assignedTo($this->agent)->create();
    $foreign = WhatsAppFixtures::outbound($otherConversation, ['status' => 'failed', 'error_code' => 'provider_unreachable']);
    $outsider = User::factory()->agent()->inDepartments(Department::factory()->create())->create();

    ($this->retry)($this->agent, $foreign)->assertNotFound();
    ($this->retry)($outsider, $this->failed)->assertNotFound();

    expect($foreign->refresh()->status)->toBe(MessageStatus::Failed)
        ->and($this->failed->refresh()->status)->toBe(MessageStatus::Failed);
    Queue::assertNothingPushed();
});

test('people who can see the conversation but not reply in it cannot retry', function () {
    Queue::fake();
    $supervisor = User::factory()->supervisor()->inDepartments($this->department)->create();

    ($this->retry)($supervisor, $this->failed)->assertForbidden();
    Queue::assertNothingPushed();
});

test('a failed template is retried only by someone who holds templates.send', function () {
    Queue::fake();
    $template = TemplateFixtures::stored($this->conversation->channelConnection()->firstOrFail());
    $failedTemplate = WhatsAppFixtures::outbound($this->conversation, [
        'type' => MessageType::Template,
        'status' => 'failed',
        'error_code' => 'provider_unreachable',
        'failed_at' => now()->subMinute(),
        'sender_user_id' => $this->agent->id,
        'metadata' => ['template' => ['id' => $template->id, 'name' => $template->name, 'language' => $template->language]],
    ]);

    ($this->retry)($this->agent, $failedTemplate)->assertForbidden();
    expect($failedTemplate->refresh()->status)->toBe(MessageStatus::Failed);
    Queue::assertNothingPushed();

    $administrator = User::factory()->administrator()->inDepartments($this->department)->create();
    $this->conversation->forceFill(['assigned_user_id' => $administrator->id])->save();

    ($this->retry)($administrator, $failedTemplate)->assertAccepted();
    expect($failedTemplate->refresh()->status)->toBe(MessageStatus::Queued);
});
