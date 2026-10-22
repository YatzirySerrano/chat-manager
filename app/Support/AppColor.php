<?php

namespace App\Support;

use App\Enums\ColorSwatch;
use Illuminate\Contracts\Database\Eloquent\Castable;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use JsonSerializable;
use Stringable;

/**
 * Color of a department or tag: either a key of the platform palette
 * (ColorSwatch) or a custom color stored as a lowercase "#rrggbb" hex.
 *
 * It exposes `value` like the ColorSwatch enum it replaces, so the stored
 * string is read the same way everywhere.
 */
final readonly class AppColor implements Castable, JsonSerializable, Stringable
{
    /** Custom colors: exactly six hexadecimal digits, case-insensitive on input. */
    public const string HEX_PATTERN = '/^#[0-9a-fA-F]{6}$/';

    private function __construct(public string $value) {}

    /**
     * Normalize a palette key or hex color, or return null when it is not one.
     */
    public static function tryFrom(mixed $color): ?self
    {
        if ($color instanceof self) {
            return $color;
        }

        if ($color instanceof ColorSwatch) {
            return new self($color->value);
        }

        if (! is_string($color)) {
            return null;
        }

        if (ColorSwatch::tryFrom($color) !== null) {
            return new self($color);
        }

        if (preg_match(self::HEX_PATTERN, $color) === 1) {
            return new self(strtolower($color));
        }

        return null;
    }

    /**
     * @throws InvalidArgumentException when the color is not a palette key or "#rrggbb" hex.
     */
    public static function from(mixed $color): self
    {
        return self::tryFrom($color)
            ?? throw new InvalidArgumentException('The color must be a palette key or a "#rrggbb" hex color.');
    }

    /**
     * The palette swatch, or null for a custom color.
     */
    public function swatch(): ?ColorSwatch
    {
        return ColorSwatch::tryFrom($this->value);
    }

    /**
     * Readable name: the swatch name, or "Personalizado #RRGGBB".
     */
    public function label(): string
    {
        return $this->swatch()?->label() ?? 'Personalizado '.strtoupper($this->value);
    }

    public function isCustom(): bool
    {
        return $this->swatch() === null;
    }

    public function jsonSerialize(): string
    {
        return $this->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }

    /**
     * @param  array<int, mixed>  $arguments
     * @return CastsAttributes<AppColor, AppColor|ColorSwatch|string>
     */
    public static function castUsing(array $arguments): CastsAttributes
    {
        return new class implements CastsAttributes
        {
            /**
             * @param  array<string, mixed>  $attributes
             */
            public function get(Model $model, string $key, mixed $value, array $attributes): ?AppColor
            {
                return $value === null ? null : AppColor::from($value);
            }

            /**
             * @param  array<string, mixed>  $attributes
             */
            public function set(Model $model, string $key, mixed $value, array $attributes): ?string
            {
                return $value === null ? null : AppColor::from($value)->value;
            }
        };
    }
}
