<?php

namespace App\Services\Chatbot;

use App\Models\ChatbotKeywordRule;
use App\Models\Conversation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;

/**
 * Keyword matching of «Chatbot · Palabras clave». A keyword matches a
 * customer message as whole words, ignoring case and accents: «horario»
 * matches «¿Cuál es su HORARIO?» but not «horarios»; «horario de atención»
 * matches «el horario de atencion». Nothing else is guessed (no stems, no
 * typos), so an administrator can always tell why a rule answered.
 */
class ChatbotKeywords
{
    /** Longest customer text examined (characters). */
    public const MAX_TEXT = 4000;

    /**
     * Lowercase, accents removed, any run of non letters or digits turned
     * into one space, trimmed.
     */
    public static function normalize(string $text): string
    {
        $ascii = Str::lower(Str::ascii(mb_substr($text, 0, self::MAX_TEXT)));

        return trim((string) preg_replace('/[^a-z0-9]+/', ' ', $ascii));
    }

    /**
     * Keywords as the administrator wrote them, comma (or line) separated,
     * without blanks or repetitions (after normalizing).
     *
     * @param  string|list<string>  $keywords
     * @return list<string>
     */
    public static function parse(string|array $keywords): array
    {
        $parts = is_array($keywords) ? $keywords : preg_split('/[,\n;]+/', $keywords);
        $unique = [];

        foreach ($parts === false ? [] : $parts as $keyword) {
            $keyword = Str::squish((string) $keyword);
            $key = self::normalize($keyword);

            if ($key !== '' && ! array_key_exists($key, $unique)) {
                $unique[$key] = $keyword;
            }
        }

        return array_values($unique);
    }

    /**
     * The first keyword (as written) found in the text, or null.
     *
     * @param  list<string>  $keywords
     */
    public function match(array $keywords, string $text): ?string
    {
        $haystack = ' '.self::normalize($text).' ';

        if (trim($haystack) === '') {
            return null;
        }

        foreach ($keywords as $keyword) {
            $needle = self::normalize($keyword);

            if ($needle !== '' && str_contains($haystack, ' '.$needle.' ')) {
                return $keyword;
            }
        }

        return null;
    }

    /**
     * Active rules that apply to the conversation (its connection and
     * department), in the order the administrator set.
     *
     * @return Collection<int, ChatbotKeywordRule>
     */
    public function rulesFor(Conversation $conversation): Collection
    {
        return ChatbotKeywordRule::query()
            ->active()
            ->where(fn (Builder $scope) => $scope
                ->whereDoesntHave('connections')
                ->orWhereHas('connections', fn (Builder $connections) => $connections->whereKey($conversation->connection_id)))
            ->where(fn (Builder $scope) => $scope
                ->whereDoesntHave('departments')
                ->when($conversation->department_id !== null, fn (Builder $query) => $query
                    ->orWhereHas('departments', fn (Builder $departments) => $departments->whereKey($conversation->department_id))))
            ->orderBy('position')
            ->orderBy('id')
            ->get();
    }

    /**
     * The first rule of the conversation whose keywords appear in the text,
     * with the keyword found.
     *
     * @return array{rule: ChatbotKeywordRule, keyword: string}|null
     */
    public function find(Conversation $conversation, string $text): ?array
    {
        foreach ($this->rulesFor($conversation) as $rule) {
            $keyword = $this->match($rule->keywords, $text);

            if ($keyword !== null) {
                return ['rule' => $rule, 'keyword' => $keyword];
            }
        }

        return null;
    }
}
