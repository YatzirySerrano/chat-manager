<?php

namespace App\Services\Chatbot;

use App\Models\ChatbotMenu;
use App\Models\ChatbotMenuOption;
use App\Models\Connection;
use Illuminate\Support\Collection;

/**
 * The welcome menu of a connection as a customer sees it: the greeting
 * followed by the numbered options that can receive conversations right now
 * (an option whose department was deleted or deactivated, or that the
 * connection may not route to, is skipped and the rest are renumbered), and
 * the option a customer's answer chooses.
 */
class ChatbotMenus
{
    /**
     * The connection's enabled menu with the options it offers now, or null
     * when it has no menu, it is off or no option can receive conversations.
     *
     * @return array{menu: ChatbotMenu, options: Collection<int, ChatbotMenuOption>}|null
     */
    public function availableFor(Connection $connection): ?array
    {
        $menu = ChatbotMenu::query()->where('connection_id', $connection->id)->where('is_enabled', true)->first();

        if ($menu === null) {
            return null;
        }

        $options = $this->options($menu, $connection);

        return $options->isEmpty() ? null : ['menu' => $menu, 'options' => $options];
    }

    /**
     * Options whose department is active and authorized for the connection
     * (same rule as ConversationRouter), in their order.
     *
     * @return Collection<int, ChatbotMenuOption>
     */
    public function options(ChatbotMenu $menu, Connection $connection): Collection
    {
        $authorized = array_map('intval', $connection->departments()->pluck('departments.id')->all());

        return $menu->options()
            ->with('department')
            ->get()
            ->filter(fn (ChatbotMenuOption $option): bool => $option->department !== null
                && $option->department->is_active
                && ($authorized === [] || in_array($option->department_id, $authorized, true)))
            ->values()
            ->toBase();
    }

    /**
     * Greeting, a blank line and «1 - Option» per line. Variables are
     * replaced when the message is queued (ChatbotText).
     *
     * @param  Collection<int, ChatbotMenuOption>  $options
     */
    public function text(ChatbotMenu $menu, Collection $options): string
    {
        $greeting = trim((string) $menu->greeting);

        return ltrim(($greeting === '' ? '' : $greeting."\n\n").$this->list($options));
    }

    /**
     * The retry after an answer that is not an option: its short text and
     * the options again, so the customer does not have to scroll back.
     *
     * @param  Collection<int, ChatbotMenuOption>  $options
     */
    public function retryText(ChatbotMenu $menu, Collection $options): string
    {
        return $menu->retryText()."\n\n".$this->list($options);
    }

    /**
     * @param  Collection<int, ChatbotMenuOption>  $options
     */
    public function list(Collection $options): string
    {
        return $options->values()
            ->map(fn (ChatbotMenuOption $option, int $index): string => ($index + 1).' - '.$option->displayLabel())
            ->implode("\n");
    }

    /**
     * The option the answer chooses: its number («1», «1.», «opción 1»,
     * «#1»), or its text written in full, or a word of it that only one
     * option has (ignoring case, accents and emojis). Null otherwise.
     *
     * @param  Collection<int, ChatbotMenuOption>  $options
     */
    public function choose(Collection $options, string $answer): ?ChatbotMenuOption
    {
        $options = $options->values();
        $normalized = ChatbotKeywords::normalize($answer);

        if ($normalized === '') {
            return null;
        }

        if (preg_match('/^(?:(?:la )?opcion |numero |num |no )?(\d{1,2})$/', $normalized, $number) === 1) {
            return $options->get(((int) $number[1]) - 1);
        }

        $exact = $options->first(fn (ChatbotMenuOption $option): bool => ChatbotKeywords::normalize($option->displayLabel()) === $normalized);

        if ($exact !== null || mb_strlen($normalized) < 3) {
            return $exact;
        }

        $partial = $options->filter(fn (ChatbotMenuOption $option): bool => str_contains(' '.ChatbotKeywords::normalize($option->displayLabel()).' ', ' '.$normalized.' '));

        return $partial->count() === 1 ? $partial->first() : null;
    }
}
