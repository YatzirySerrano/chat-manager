<?php

use App\Enums\DataScope;
use App\Enums\Permission;
use App\Models\Department;
use App\Models\Tag;
use App\Models\TeamChat\TeamDeskNote;
use App\Models\User;
use App\Services\TeamChat\ControlDesk;
use Inertia\Testing\AssertableInertia as Assert;

/*
| «Mesa de control» follow-up: internal notes, tags and the reports of the
| desk, only for whoever attends it; the requester never sees them.
*/

beforeEach(function () {
    $department = Department::factory()->create();
    $this->requester = conversationUser([Permission::UseTeamChat], DataScope::Assigned, [$department]);
    $this->auditor = conversationUser([Permission::UseTeamChat, Permission::AttendControlDesk, Permission::AssignTags, Permission::ExportReports], DataScope::Assigned, [$department]);
    $this->thread = app(ControlDesk::class)->threadFor($this->requester);

    $this->actingAs($this->requester)
        ->postJson(route('team-chat.threads.messages.store', $this->thread), ['body' => 'Necesito el contrato firmado'])
        ->assertCreated();
});

test('the desk keeps internal notes and tags that the requester never sees', function () {
    $tag = Tag::factory()->create(['name' => 'Urgente']);

    $this->actingAs($this->auditor)
        ->post(route('team-chat.threads.desk.notes.store', $this->thread), ['body' => 'Llamar mañana'])
        ->assertRedirect();
    $this->actingAs($this->auditor)
        ->put(route('team-chat.threads.desk.tags.update', $this->thread), ['tag_ids' => [$tag->id]])
        ->assertRedirect();

    $this->actingAs($this->auditor)
        ->get(route('team-chat.threads.show', [$this->thread, 'desk' => 'pending']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('activeThread.desk_details.notes.0.body', 'Llamar mañana')
            ->where('activeThread.desk_details.tags.0.name', 'Urgente')
            ->where('deskInbox.threads.0.tags.0.name', 'Urgente'));

    $this->actingAs($this->requester)
        ->get(route('team-chat.threads.show', $this->thread))
        ->assertInertia(fn (Assert $page) => $page->where('activeThread.desk_details', null));

    $this->actingAs($this->requester)
        ->post(route('team-chat.threads.desk.notes.store', $this->thread), ['body' => 'x'])
        ->assertNotFound();
});

test('only the author edits or deletes a note', function () {
    $other = conversationUser([Permission::UseTeamChat, Permission::AttendControlDesk], DataScope::Assigned);
    $note = new TeamDeskNote(['body' => 'Primera']);
    $note->forceFill(['team_thread_id' => $this->thread->id, 'user_id' => $this->auditor->id])->save();

    $this->actingAs($other)
        ->delete(route('team-chat.threads.desk.notes.destroy', [$this->thread, $note]))
        ->assertForbidden();

    $this->actingAs($this->auditor)
        ->put(route('team-chat.threads.desk.notes.update', [$this->thread, $note]), ['body' => 'Corregida'])
        ->assertRedirect();

    expect($note->fresh())->body->toBe('Corregida')->edited_at->not->toBeNull();
});

test('the desk exports its requests and the dossier of one request', function () {
    $this->actingAs($this->auditor)
        ->post(route('exports.generate', ['desk-requests', 'xlsx', 'tab' => 'pending']))
        ->assertOk()
        ->assertDownload();

    $this->actingAs($this->auditor)
        ->post(route('exports.generate', ['desk-request-dossier', 'xlsx', 'thread' => $this->thread->id]))
        ->assertOk()
        ->assertDownload();

    $this->actingAs($this->requester)
        ->post(route('exports.generate', ['desk-request-dossier', 'xlsx', 'thread' => $this->thread->id]))
        ->assertForbidden();
});
