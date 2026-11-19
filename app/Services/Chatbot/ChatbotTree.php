<?php

namespace App\Services\Chatbot;

use App\Models\ChatbotMenu;
use App\Models\ChatbotMenuOption;
use Illuminate\Support\Collection;

/**
 * A chatbot menu loaded once, level by level, with only the options that
 * can be offered now (ChatbotMenus::tree).
 */
final class ChatbotTree
{
    /**
     * @param  array<int, Collection<int, ChatbotMenuOption>>  $levels  Offered options by parent id (0: the main menu).
     * @param  Collection<int, ChatbotMenuOption>  $all  Every option of the menu by id, offered or not.
     */
    public function __construct(
        public readonly ChatbotMenu $menu,
        private readonly array $levels,
        private readonly Collection $all,
    ) {}

    /**
     * Options offered in a level (null: the main menu), in order.
     *
     * @return Collection<int, ChatbotMenuOption>
     */
    public function level(?int $submenuId): Collection
    {
        return $this->levels[$submenuId ?? 0] ?? collect();
    }

    /**
     * The submenu with this id when it is still offered somewhere, else
     * null (removed or emptied since: the customer goes to the main menu).
     */
    public function submenu(?int $id): ?ChatbotMenuOption
    {
        if ($id === null || ! isset($this->levels[$id]) || $this->levels[$id]->isEmpty()) {
            return null;
        }

        return $this->all->get($id);
    }

    /**
     * The level above a submenu (null: the main menu).
     */
    public function parentOf(ChatbotMenuOption $submenu): ?ChatbotMenuOption
    {
        return $this->submenu($submenu->parent_id);
    }
}
