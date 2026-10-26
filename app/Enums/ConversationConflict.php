<?php

namespace App\Enums;

/**
 * Why an operation the actor is allowed to perform cannot run on the
 * conversation as it is now (409): its state changed after the user looked
 * at it. The code travels to the browser ({code, message}), which shows the
 * message and reloads the conversation to show its real state.
 */
enum ConversationConflict: string
{
    /** The state no longer allows the operation (stale view, already pending, already reopened…). */
    case StateChanged = 'state_changed';

    /** Another agent is responsible for the conversation now. */
    case AssignedToOther = 'assigned_to_other';

    /** The conversation was closed. */
    case Closed = 'closed';

    public function message(): string
    {
        return match ($this) {
            self::StateChanged => 'Esta conversación cambió de estado. Actualiza la información para continuar.',
            self::AssignedToOther => 'Otro agente ya está atendiendo esta conversación.',
            self::Closed => 'Esta conversación está cerrada. Revisa las opciones disponibles.',
        };
    }
}
