<?php

namespace App\Services\Chatbot;

use App\Enums\ChatbotOptionAction;
use App\Models\ChatbotMenu;
use App\Models\ChatbotMenuOption;
use App\Models\Connection;
use Illuminate\Support\Collection;

/**
 * The chatbot menu of a connection as a customer sees it, level by level
 * (the main menu and each submenu). Only the options that work right now
 * are offered, renumbered:
 *
 * - a transfer whose department was deleted, deactivated or is not
 *   authorized for the connection (same rule as ConversationRouter) is
 *   skipped;
 * - a submenu with no option left is skipped.
 *
 * Also the option a customer's answer chooses.
 */
class ChatbotMenus
{
    /**
     * The connection's enabled menu as a tree, or null when it has no
     * menu, it is off or its main menu offers nothing.
     */
    public function availableFor(Connection $connection): ?ChatbotTree
    {
        $menu = ChatbotMenu::query()->where('connection_id', $connection->id)->where('is_enabled', true)->first();

        if ($menu === null) {
            return null;
        }

        $tree = $this->tree($menu, $connection);

        return $tree->level(null)->isEmpty() ? null : $tree;
    }

    /**
     * The welcome of an enabled menu that offers no options (none were
     * added, or none works today), or null: such a chatbot only greets, and
     * the conversation goes on to the ordinary routing at once.
     */
    public function greetingOnly(Connection $connection): ?string
    {
        $menu = ChatbotMenu::query()->where('connection_id', $connection->id)->where('is_enabled', true)->first();
        $greeting = trim((string) $menu?->greeting);

        if ($menu === null || $greeting === '' || ! $this->tree($menu, $connection)->level(null)->isEmpty()) {
            return null;
        }

        return $greeting;
    }

    public function tree(ChatbotMenu $menu, Connection $connection): ChatbotTree
    {
        $authorized = array_map('intval', $connection->departments()->pluck('departments.id')->all());

        /** @var Collection<int, ChatbotMenuOption> $all */
        $all = $menu->allOptions()->with('department')->get()->toBase();
        $byParent = $all->groupBy(fn (ChatbotMenuOption $option): string => (string) ($option->parent_id ?? 0));

        /** @var array<int, Collection<int, ChatbotMenuOption>> $levels */
        $levels = [];

        // Depth first, so a submenu knows whether any of its options survived.
        $build = function (?int $parentId) use (&$build, &$levels, $byParent, $authorized): Collection {
            $offered = collect();

            foreach ($byParent->get((string) ($parentId ?? 0), collect()) as $option) {
                /** @var ChatbotMenuOption $option */
                $works = match ($option->action) {
                    ChatbotOptionAction::Transfer => $option->department !== null
                        && $option->department->is_active
                        && ($authorized === [] || in_array($option->department_id, $authorized, true)),
                    ChatbotOptionAction::Submenu => $build($option->id)->isNotEmpty(),
                    default => true,
                };

                if ($works) {
                    $offered->push($option);
                }
            }

            $levels[$parentId ?? 0] = $offered->values();

            return $offered;
        };

        $build(null);

        return new ChatbotTree($menu, $levels, $all->keyBy('id'));
    }

    /**
     * The main menu: greeting, a blank line and «1 - Option» per line.
     * Variables are replaced when the message is queued (ChatbotText).
     */
    public function text(ChatbotTree $tree): string
    {
        $greeting = trim((string) $tree->menu->greeting);

        return ltrim(($greeting === '' ? '' : $greeting."\n\n").$this->list($tree->level(null)));
    }

    /**
     * A level as the customer receives it: the main menu, or a submenu's
     * own text followed by its options and how to go back.
     */
    public function levelText(ChatbotTree $tree, ?ChatbotMenuOption $submenu): string
    {
        if ($submenu === null) {
            return $this->text($tree);
        }

        $header = trim((string) $submenu->reply_message);

        return ltrim(($header === '' ? '' : $header."\n\n")
            .$this->list($tree->level($submenu->id))
            ."\n\n".$tree->menu->navigationText());
    }

    /**
     * The retry after an answer that is not an option: its short text and
     * the options of the level again, so the customer does not scroll back.
     */
    public function retryText(ChatbotTree $tree, ?ChatbotMenuOption $submenu): string
    {
        $text = $tree->menu->retryText()."\n\n".$this->list($tree->level($submenu?->id));

        return $submenu === null ? $text : $text."\n\n".$tree->menu->navigationText();
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
     * «0»: one level back. «#» (or «menú», «inicio»): the main menu.
     *
     * @return 'back'|'main'|null
     */
    public function navigation(string $answer): ?string
    {
        $raw = trim($answer);

        if ($raw === '#' || $raw === '＃') {
            return 'main';
        }

        if ($raw === '0') {
            return 'back';
        }

        return in_array(ChatbotKeywords::normalize($raw), ['menu', 'menu principal', 'inicio'], true) ? 'main' : null;
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
            return (int) $number[1] < 1 ? null : $options->get(((int) $number[1]) - 1);
        }

        $exact = $options->first(fn (ChatbotMenuOption $option): bool => ChatbotKeywords::normalize($option->displayLabel()) === $normalized);

        if ($exact !== null || mb_strlen($normalized) < 3) {
            return $exact;
        }

        $partial = $options->filter(fn (ChatbotMenuOption $option): bool => str_contains(' '.ChatbotKeywords::normalize($option->displayLabel()).' ', ' '.$normalized.' '));

        return $partial->count() === 1 ? $partial->first() : null;
    }
}
