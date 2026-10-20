<?php

namespace App\Actions\Tags;

use App\Models\Tag;
use Illuminate\Support\Str;

class GenerateTagSlug
{
    /**
     * Longest base slug: the column holds 80 characters and a "-N" suffix
     * may be appended to keep it unique. Slugging can lengthen a name
     * ("@" becomes "at"), so the name limit alone does not guarantee it.
     */
    private const MAX_BASE_LENGTH = 70;

    /**
     * Build a unique slug for the tag name.
     */
    public function handle(string $name, ?int $ignoreTagId = null): string
    {
        $base = rtrim(Str::substr(Str::slug($name), 0, self::MAX_BASE_LENGTH), '-') ?: 'etiqueta';
        $slug = $base;
        $suffix = 2;

        while (Tag::query()
            ->where('slug', $slug)
            ->when($ignoreTagId !== null, fn ($query) => $query->whereKeyNot($ignoreTagId))
            ->exists()) {
            $slug = "{$base}-{$suffix}";
            $suffix++;
        }

        return $slug;
    }
}
