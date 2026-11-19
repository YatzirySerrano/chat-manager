<?php

namespace App\Services\TeamChat;

use App\Actions\TeamChat\PostTeamMessage;
use App\Models\TeamChat\TeamThread;
use App\Models\User;
use App\Services\Chatbot\ChatbotText;
use App\Services\PlatformSettings;
use App\Support\ProtectedAccounts;

/**
 * «Completar y despedir» of the «Mesa de control»: the goodbye configured
 * in Administración → Configuración (control_desk.farewell_*) is posted in
 * the request by the auditor who completes it, right before completing it.
 * Variables: {{nombre}} (the requester), {{agente}} (who completes it) and
 * {{saludo}} (Buenos días / tardes / noches).
 */
class DeskFarewell
{
    public function __construct(
        private PlatformSettings $settings,
        private PostTeamMessage $post,
        private ChatbotText $text,
    ) {}

    /**
     * The configured goodbye, or null when it is off or empty.
     */
    public function message(): ?string
    {
        if (! (bool) $this->settings->get('control_desk.farewell_enabled', false)) {
            return null;
        }

        $message = trim((string) $this->settings->get('control_desk.farewell_message', ''));

        return $message === '' ? null : $message;
    }

    /**
     * Posts the goodbye in the open request the actor attends. Returns
     * whether it was sent (nothing is sent when it is off or the request is
     * not hers: completing then fails on its own with the usual reason).
     */
    public function send(TeamThread $thread, User $actor): bool
    {
        $message = $this->message();
        $thread = $thread->fresh() ?? $thread;

        if ($message === null || ! $thread->isDesk() || ! $thread->isDeskAssignedTo($actor)) {
            return false;
        }

        $this->post->handle($thread, $actor, $this->render($message, $thread, $actor), null);

        return true;
    }

    private function render(string $message, TeamThread $thread, User $actor): string
    {
        $names = app(ProtectedAccounts::class);
        $requester = $thread->deskRequester()->first(['id', 'name', 'is_active']);
        $values = [
            'nombre' => $requester === null ? '' : trim((string) $names->nameFor($requester, null)),
            'agente' => trim((string) $names->nameFor($actor, null)),
            'saludo' => $this->text->greeting(),
        ];

        $rendered = (string) preg_replace_callback('/\{\{\s*([\p{L}]+)\s*\}\}/u', function (array $match) use ($values): string {
            $key = mb_strtolower($match[1]);
            $key = $key === 'username' || $key === 'name' ? 'nombre' : $key;

            return $values[$key] ?? $match[0];
        }, $message);

        // «Listo {{nombre}},» without a name reads «Listo,».
        return trim((string) preg_replace('/ +([,.!?])/u', '$1', $rendered));
    }
}
