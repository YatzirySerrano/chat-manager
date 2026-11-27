<?php

namespace App\Http\Controllers\Conversations;

use App\Actions\Conversations\SaveRequestFields;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Support\RequestFields\RequestFieldCatalog;
use App\Support\RequestFields\RequestFieldParser;
use App\Support\SaveToast;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * «Datos de la solicitud»: the request data picked out of the messages.
 *
 * - `parse` reads the «Etiqueta: valor» lines of one message of the
 *   conversation and returns them as suggestions for the capture dialog.
 *   It changes nothing, so seeing the conversation is enough.
 * - `update` saves the fields the analyst kept (merged, or the whole edited
 *   list), authorized like the request category; the engine authorizes the
 *   actor again under lock (SaveRequestFields).
 */
class ConversationRequestFieldController extends ConversationOperationController
{
    public function parse(Conversation $conversation, Message $message, RequestFieldParser $parser): JsonResponse
    {
        $this->ensureVisible($conversation);

        abort_unless($message->conversation_id === $conversation->id, 404);

        $body = $message->revoked_at === null ? $message->body : null;

        return response()->json([
            'fields' => $parser->parse($body),
            'labels' => RequestFieldCatalog::options(),
        ]);
    }

    public function update(Request $request, Conversation $conversation, SaveRequestFields $save): RedirectResponse
    {
        $this->ensureVisible($conversation);

        /** @var User $actor */
        $actor = $request->user();

        // Permission before validation: a refusal is a 403, never a 422.
        $this->ensurePermitted($actor, 'categorize', $conversation);

        $validated = $request->validate([
            'mode' => ['required', Rule::in([SaveRequestFields::MERGE, SaveRequestFields::REPLACE])],
            'fields' => ['present', 'array', 'max:'.RequestFieldCatalog::FIELDS_MAX],
            'fields.*' => ['array:label,value,message_id,key'],
            'fields.*.label' => ['required', 'string', 'max:'.RequestFieldCatalog::LABEL_MAX],
            'fields.*.value' => ['nullable', 'string', 'max:'.RequestFieldCatalog::VALUE_MAX],
            'fields.*.message_id' => ['nullable', 'integer', 'min:1'],
        ], attributes: [
            'fields' => 'datos de la solicitud',
            'fields.*.label' => 'nombre del dato',
            'fields.*.value' => 'valor',
        ]);

        $fields = array_values(array_map(fn (array $field): array => [
            'label' => (string) $field['label'],
            'value' => isset($field['value']) ? (string) $field['value'] : null,
            'message_id' => isset($field['message_id']) ? (int) $field['message_id'] : null,
        ], $validated['fields']));

        $changed = $save->handle($conversation, $fields, $validated['mode'], $actor);

        SaveToast::flash($changed, $validated['mode'] === SaveRequestFields::MERGE
            ? 'Datos de la solicitud guardados.'
            : 'Datos de la solicitud actualizados.');

        return back();
    }
}
