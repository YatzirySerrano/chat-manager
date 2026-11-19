<?php

namespace App\Console\Commands;

use App\Services\Chatbot\ChatbotConversations;
use Illuminate\Console\Command;

/**
 * Chatbot menus the customer did not answer within the menu's timeout
 * («Si no contesta en…»): the conversation follows the ordinary routing and
 * is distributed and announced, so it never waits unseen.
 */
class ExpireChatbotSessions extends Command
{
    protected $signature = 'chatbot:expire-sessions {--limit=200 : Sessions per run}';

    protected $description = 'Hand chatbot menus nobody answered in time over to the ordinary routing';

    public function handle(ChatbotConversations $chatbot): int
    {
        $expired = $chatbot->expire(max(1, (int) $this->option('limit')));

        $this->info("Menús del chatbot vencidos: {$expired}");

        return self::SUCCESS;
    }
}
