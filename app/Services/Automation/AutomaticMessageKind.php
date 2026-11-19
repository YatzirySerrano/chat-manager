<?php

namespace App\Services\Automation;

/**
 * Automatic messages the platform may send to a customer. Welcome and away
 * share the "greeting" slot of a conversation cycle: at most one of them is
 * ever sent per cycle.
 *
 * The chatbot (App\Services\Chatbot) adds its own kinds: the welcome menu
 * of a new conversation, the retry after an answer that is not an option,
 * the reply of the chosen option and the reply to a keyword. They have slots
 * of their own, so a menu never takes the place of the away message.
 *
 * The team's actions add three more, configured on the connection: a person
 * takes the conversation (AgentAssigned), it goes to another department's
 * queue (DepartmentTransfer) or another agent takes over (AgentTransfer).
 * They are sent once per conversation event (SendActionMessages).
 *
 * The assistant of «Inteligencia artificial» answers with AiReply, once per
 * customer message it answers (App\Services\Ai\AiAssistant).
 */
enum AutomaticMessageKind: string
{
    case Welcome = 'welcome';
    case Away = 'away';
    case Farewell = 'farewell';
    case ChatbotMenu = 'chatbot_menu';
    case ChatbotRetry = 'chatbot_retry';
    case ChatbotReply = 'chatbot_reply';
    case KeywordReply = 'keyword_reply';
    case AgentAssigned = 'agent_assigned';
    case DepartmentTransfer = 'department_transfer';
    case AgentTransfer = 'agent_transfer';
    case AiReply = 'ai_reply';

    /**
     * The kinds of a conversation's life cycle configured on the connection
     * (and the department): the ones the connection diagnostics count.
     *
     * @return list<self>
     */
    public static function lifecycle(): array
    {
        return [self::Welcome, self::Away, self::Farewell];
    }

    /**
     * The kinds sent after the team's actions, configured on the connection
     * as "{value}_enabled" and "{value}_message".
     *
     * @return list<self>
     */
    public static function actions(): array
    {
        return [self::AgentAssigned, self::DepartmentTransfer, self::AgentTransfer];
    }

    /**
     * Idempotency slot: one message per (conversation, slot, cycle). The
     * retries, keyword replies and action messages add their own
     * discriminator to the reference (attempt, rule and triggering message,
     * conversation event).
     */
    public function slot(): string
    {
        return match ($this) {
            self::Welcome, self::Away => 'greeting',
            self::Farewell => 'farewell',
            self::ChatbotMenu => 'chatbot-menu',
            self::ChatbotRetry => 'chatbot-retry',
            self::ChatbotReply => 'chatbot-reply',
            self::KeywordReply => 'keyword',
            self::AgentAssigned => 'agent-assigned',
            self::DepartmentTransfer => 'department-transfer',
            self::AgentTransfer => 'agent-transfer',
            self::AiReply => 'ai-reply',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Welcome => 'Bienvenida',
            self::Away => 'Fuera de horario',
            self::Farewell => 'Despedida',
            self::ChatbotMenu => 'Menú del chatbot',
            self::ChatbotRetry => 'Reintento del chatbot',
            self::ChatbotReply => 'Respuesta del chatbot',
            self::KeywordReply => 'Respuesta por palabra clave',
            self::AgentAssigned => 'Agente asignado',
            self::DepartmentTransfer => 'Transferido a departamento',
            self::AgentTransfer => 'Transferido a otro agente',
            self::AiReply => 'Respuesta de IA',
        };
    }
}
