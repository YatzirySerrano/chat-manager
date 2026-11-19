<?php

namespace App\Http\Requests\Admin;

use App\Enums\WebChatAvailabilityMode;
use App\Enums\WebChatIdentityMode;
use App\Enums\WebChatPosition;
use App\Models\Connection;
use App\Models\WebChatWidget;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\File;
use Illuminate\Validation\Validator;

/**
 * Widget settings of a web chat connection. Authorized origins are exact
 * scheme://host[:port] values (https, except for local development hosts),
 * stored normalized; the accent color must keep white text readable. The
 * colors of the conversation (background and bubbles) are optional #rrggbb
 * values: empty keeps the widget's own look, and their contrast is the
 * administrator's choice (the form only warns when it is very low).
 */
class WebChatWidgetRequest extends FormRequest
{
    public const MAX_ORIGINS = 20;

    /**
     * Largest avatar accepted, in kilobytes.
     */
    public const AVATAR_MAX_KB = 128;

    /**
     * Lowest contrast ratio accepted between the accent color and white text
     * (WCAG AA for normal text).
     */
    public const MIN_CONTRAST = 4.5;

    /**
     * Hosts that may be authorized over plain http (local development).
     *
     * @var list<string>
     */
    public const LOCAL_HOSTS = ['localhost', '127.0.0.1', '[::1]'];

    public function authorize(): bool
    {
        $connection = $this->route('connection');

        return $connection instanceof Connection && ($this->user()?->can('manageWidget', $connection) ?? false);
    }

    protected function prepareForValidation(): void
    {
        $trimmed = [];

        foreach (['title', 'subtitle', 'welcome_text', 'offline_text', 'launcher_label', 'accent_color', 'online_text', 'offline_status_text', 'composer_placeholder', ...WebChatWidget::COLORS] as $field) {
            if (is_string($this->input($field))) {
                $trimmed[$field] = trim($this->input($field));
            }
        }

        $origins = $this->input('allowed_origins');

        if (is_array($origins)) {
            $trimmed['allowed_origins'] = array_values(array_map(
                fn (mixed $origin): mixed => is_string($origin) ? trim($origin) : $origin,
                $origins,
            ));
        }

        $this->merge($trimmed);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $colors = array_fill_keys(WebChatWidget::COLORS, ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/']);

        return [
            ...$colors,
            'allow_visitor_files' => ['sometimes', 'boolean'],
            'dark_mode' => ['sometimes', 'string', Rule::in(WebChatWidget::DARK_MODES)],
            'launcher_shape' => ['sometimes', 'string', Rule::in(WebChatWidget::LAUNCHER_SHAPES)],
            'launcher_icon' => ['sometimes', 'string', Rule::in(WebChatWidget::LAUNCHER_ICONS)],
            'launcher_size' => ['sometimes', 'string', Rule::in(WebChatWidget::LAUNCHER_SIZES)],
            'launcher_offset_x' => ['sometimes', 'integer', 'min:0', 'max:'.WebChatWidget::MAX_LAUNCHER_OFFSET],
            'launcher_offset_y' => ['sometimes', 'integer', 'min:0', 'max:'.WebChatWidget::MAX_LAUNCHER_OFFSET],
            'launcher_pulse' => ['sometimes', 'boolean'],
            'launcher_width' => ['nullable', 'integer', 'min:'.WebChatWidget::LAUNCHER_WIDTH['min'], 'max:'.WebChatWidget::LAUNCHER_WIDTH['max']],
            'launcher_font_size' => ['sometimes', 'string', Rule::in(WebChatWidget::LAUNCHER_SIZES)],
            'panel_width' => ['sometimes', 'integer', 'min:'.WebChatWidget::PANEL_WIDTH['min'], 'max:'.WebChatWidget::PANEL_WIDTH['max']],
            'panel_height' => ['sometimes', 'integer', 'min:'.WebChatWidget::PANEL_HEIGHT['min'], 'max:'.WebChatWidget::PANEL_HEIGHT['max']],
            'online_text' => ['nullable', 'string', 'max:40'],
            'offline_status_text' => ['nullable', 'string', 'max:40'],
            'composer_placeholder' => ['nullable', 'string', 'max:80'],
            'allowed_origins' => ['required', 'array', 'min:1', 'max:'.self::MAX_ORIGINS],
            'allowed_origins.*' => ['required', 'string', 'max:255', $this->originRule()],
            'title' => ['required', 'string', 'min:1', 'max:60'],
            'subtitle' => ['nullable', 'string', 'max:120'],
            'welcome_text' => ['nullable', 'string', 'max:500'],
            'offline_text' => ['nullable', 'string', 'max:500'],
            'position' => ['required', Rule::enum(WebChatPosition::class)],
            'accent_color' => ['required', 'string', 'regex:/^#[0-9a-fA-F]{6}$/', $this->contrastRule()],
            'launcher_enabled' => ['required', 'boolean'],
            'launcher_label' => ['nullable', 'string', 'max:40'],
            'avatar' => ['nullable', File::image()->types(['png', 'jpg', 'jpeg', 'webp'])->max(self::AVATAR_MAX_KB)],
            'remove_avatar' => ['nullable', 'boolean'],
            'launcher_image' => ['nullable', File::image()->types(['png', 'jpg', 'jpeg', 'webp'])->max(self::AVATAR_MAX_KB)],
            'remove_launcher_image' => ['nullable', 'boolean'],
            'identity_mode' => ['required', Rule::enum(WebChatIdentityMode::class)],
            'prechat' => ['nullable', 'array'],
            'prechat.fields' => ['nullable', 'array', 'max:2'],
            'prechat.fields.*' => ['string', 'distinct', Rule::in(['name', 'email'])],
            'prechat.required' => ['nullable', 'boolean'],
            'prechat.privacy_notice' => ['nullable', 'string', 'max:500'],
            'availability_mode' => ['required', Rule::enum(WebChatAvailabilityMode::class)],
            'session_idle_days' => ['required', 'integer', 'min:1', 'max:90'],
        ];
    }

    /**
     * The same origin written twice (after normalizing) is rejected, and the
     * avatar and the button's icon must really be PNG, JPEG or WebP images
     * (checked by their bytes; never SVG); the icon must be almost square.
     *
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->has('allowed_origins') || $validator->errors()->has('allowed_origins.*')) {
                    return;
                }

                $origins = $this->normalizedOrigins();

                if (count($origins) !== count(array_unique($origins))) {
                    $validator->errors()->add('allowed_origins', 'Hay dominios repetidos. Escribe cada origen una sola vez.');
                }
            },
            function (Validator $validator): void {
                foreach (['avatar', 'launcher_image'] as $field) {
                    $image = $this->file($field);

                    if ($validator->errors()->has($field) || ! $image instanceof UploadedFile) {
                        continue;
                    }

                    if (self::imageTypeOf((string) file_get_contents($image->getRealPath(), length: 16)) === null) {
                        $validator->errors()->add($field, 'La imagen debe ser PNG, JPG o WebP.');

                        continue;
                    }

                    // The button's icon is drawn in a square: an elongated image would be unreadable.
                    $size = $field === 'launcher_image' ? @getimagesize($image->getRealPath()) : null;

                    if (is_array($size) && $size[1] > 0 && ($size[0] / $size[1] < 0.5 || $size[0] / $size[1] > 2)) {
                        $validator->errors()->add($field, 'Usa una imagen casi cuadrada para el ícono.');
                    }
                }
            },
        ];
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        $colors = [];

        foreach (WebChatWidget::COLORS as $color) {
            $colors[$color.'.regex'] = 'Escribe el color en formato hexadecimal, por ejemplo #F3F4F6, o déjalo vacío para usar el predeterminado.';
        }

        return [
            ...$colors,
            'allowed_origins.required' => 'Autoriza al menos un dominio donde se insertará el chat.',
            'allowed_origins.min' => 'Autoriza al menos un dominio donde se insertará el chat.',
            'allowed_origins.max' => 'Puedes autorizar hasta '.self::MAX_ORIGINS.' dominios.',
            'accent_color.regex' => 'Escribe el color en formato hexadecimal, por ejemplo #2563EB.',
            'avatar.max' => 'La imagen no debe pesar más de '.self::AVATAR_MAX_KB.' KB.',
            'avatar.mimes' => 'La imagen debe ser PNG, JPG o WebP.',
            'avatar.image' => 'La imagen debe ser PNG, JPG o WebP.',
            'launcher_image.max' => 'El ícono no debe pesar más de '.self::AVATAR_MAX_KB.' KB.',
            'launcher_image.mimes' => 'El ícono debe ser PNG, JPG o WebP.',
            'launcher_image.image' => 'El ícono debe ser PNG, JPG o WebP.',
        ];
    }

    /**
     * Get custom attributes for validator errors.
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'allowed_origins' => 'dominios autorizados',
            'allowed_origins.*' => 'dominio',
            'title' => 'título',
            'subtitle' => 'subtítulo',
            'welcome_text' => 'mensaje de bienvenida',
            'offline_text' => 'mensaje fuera de línea',
            'position' => 'posición',
            'accent_color' => 'color de acento',
            'background_color' => 'fondo de la conversación',
            'visitor_bubble_color' => 'burbuja del visitante',
            'visitor_text_color' => 'texto del visitante',
            'agent_bubble_color' => 'burbuja del agente',
            'agent_text_color' => 'texto del agente',
            'allow_visitor_files' => 'archivos de los visitantes',
            'dark_mode' => 'modo oscuro',
            'launcher_color' => 'color del botón',
            'launcher_icon_color' => 'color del ícono',
            'launcher_shape' => 'forma del botón',
            'launcher_icon' => 'ícono del botón',
            'launcher_size' => 'tamaño del botón',
            'launcher_offset_x' => 'distancia del borde lateral',
            'launcher_offset_y' => 'distancia del borde inferior',
            'launcher_pulse' => 'animación del botón',
            'launcher_width' => 'ancho del botón',
            'launcher_font_size' => 'tamaño del texto del botón',
            'panel_width' => 'ancho de la ventana',
            'panel_height' => 'alto de la ventana',
            'online_text' => 'texto «en línea»',
            'offline_status_text' => 'texto «fuera de línea»',
            'composer_placeholder' => 'texto de la caja de mensaje',
            'launcher_enabled' => 'botón flotante',
            'launcher_label' => 'texto del botón',
            'avatar' => 'imagen',
            'launcher_image' => 'ícono del botón',
            'identity_mode' => 'identificación',
            'prechat.fields' => 'campos del formulario previo',
            'prechat.fields.*' => 'campo',
            'prechat.privacy_notice' => 'aviso de privacidad',
            'availability_mode' => 'disponibilidad',
            'session_idle_days' => 'días de inactividad',
        ];
    }

    /**
     * Validated settings ready to fill, with the origins normalized.
     *
     * @return array{settings: array<string, mixed>, avatar: UploadedFile|null, remove_avatar: bool, launcher_image: UploadedFile|null, remove_launcher_image: bool}
     */
    public function payload(): array
    {
        $fields = $this->input('prechat.fields');

        $avatar = $this->file('avatar');
        $launcherImage = $this->file('launcher_image');

        return [
            'settings' => [
                'allowed_origins' => $this->normalizedOrigins(),
                'title' => $this->string('title')->value(),
                'subtitle' => $this->nullableString('subtitle'),
                'welcome_text' => $this->nullableString('welcome_text'),
                'offline_text' => $this->nullableString('offline_text'),
                'position' => $this->enum('position', WebChatPosition::class),
                'accent_color' => strtolower($this->string('accent_color')->value()),
                'launcher_enabled' => $this->boolean('launcher_enabled'),
                'launcher_label' => $this->nullableString('launcher_label'),
                ...$this->colors(),
                // Older clients that do not send it keep the current value.
                ...$this->optionalSettings(),
                'identity_mode' => $this->enum('identity_mode', WebChatIdentityMode::class),
                'prechat' => [
                    'fields' => array_values(array_filter(
                        ['name', 'email'],
                        fn (string $field): bool => is_array($fields) && in_array($field, $fields, true),
                    )),
                    'required' => $this->boolean('prechat.required'),
                    'privacy_notice' => $this->nullableString('prechat.privacy_notice'),
                ],
                'availability_mode' => $this->enum('availability_mode', WebChatAvailabilityMode::class),
                'session_idle_days' => $this->integer('session_idle_days'),
            ],
            'avatar' => $avatar instanceof UploadedFile ? $avatar : null,
            'remove_avatar' => $this->boolean('remove_avatar'),
            'launcher_image' => $launcherImage instanceof UploadedFile ? $launcherImage : null,
            'remove_launcher_image' => $this->boolean('remove_launcher_image'),
        ];
    }

    /**
     * Settings older clients may not send: they keep their stored value.
     *
     * @return array<string, mixed>
     */
    private function optionalSettings(): array
    {
        $settings = [];

        foreach (['allow_visitor_files', 'launcher_pulse'] as $flag) {
            if ($this->has($flag)) {
                $settings[$flag] = $this->boolean($flag);
            }
        }

        foreach (['online_text', 'offline_status_text', 'composer_placeholder'] as $text) {
            if ($this->exists($text)) {
                $settings[$text] = $this->nullableString($text);
            }
        }

        if ($this->exists('launcher_width')) {
            $settings['launcher_width'] = $this->filled('launcher_width') ? $this->integer('launcher_width') : null;
        }

        foreach (['launcher_shape', 'launcher_icon', 'launcher_size', 'launcher_font_size', 'dark_mode'] as $choice) {
            if ($this->filled($choice)) {
                $settings[$choice] = $this->string($choice)->value();
            }
        }

        foreach (['launcher_offset_x', 'launcher_offset_y', 'panel_width', 'panel_height'] as $offset) {
            if ($this->filled($offset)) {
                $settings[$offset] = $this->integer($offset);
            }
        }

        return $settings;
    }

    /**
     * The optional colors, lowercase, or null when left empty. A client that
     * does not send them at all (an older form) keeps the stored ones.
     *
     * @return array<string, string|null>
     */
    private function colors(): array
    {
        $colors = [];

        foreach (WebChatWidget::COLORS as $color) {
            if (! $this->exists($color)) {
                continue;
            }

            $value = $this->nullableString($color);
            $colors[$color] = $value !== null ? strtolower($value) : null;
        }

        return $colors;
    }

    /**
     * Contrast ratio between a #rrggbb color and white (1 to 21).
     */
    public static function contrastWithWhite(string $hex): float
    {
        $channels = array_map(function (string $pair): float {
            $value = hexdec($pair) / 255;

            return $value <= 0.03928 ? $value / 12.92 : (($value + 0.055) / 1.055) ** 2.4;
        }, str_split(ltrim($hex, '#'), 2));

        $luminance = 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];

        return 1.05 / ($luminance + 0.05);
    }

    /**
     * Image type recognized from the first bytes of a file, or null.
     */
    public static function imageTypeOf(string $bytes): ?string
    {
        return match (true) {
            str_starts_with($bytes, "\x89PNG\r\n\x1a\n") => 'image/png',
            str_starts_with($bytes, "\xFF\xD8\xFF") => 'image/jpeg',
            str_starts_with($bytes, 'RIFF') && substr($bytes, 8, 4) === 'WEBP' => 'image/webp',
            default => null,
        };
    }

    /**
     * @return list<string>
     */
    private function normalizedOrigins(): array
    {
        $origins = $this->input('allowed_origins');

        if (! is_array($origins)) {
            return [];
        }

        return array_values(array_filter(array_map(
            fn (mixed $origin): ?string => is_string($origin) ? WebChatWidget::normalizeOrigin($origin) : null,
            $origins,
        )));
    }

    private function originRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (! is_string($value)) {
                return;
            }

            if (str_contains($value, '*')) {
                $fail('No se admiten comodines: escribe cada dominio completo, por ejemplo https://tienda.example.com.');

                return;
            }

            $normalized = WebChatWidget::normalizeOrigin($value);

            if ($normalized === null) {
                $fail('Escribe solo el origen (protocolo y dominio, y el puerto si no es el habitual), sin ruta ni parámetros: por ejemplo https://tienda.example.com.');

                return;
            }

            $host = (string) parse_url($normalized, PHP_URL_HOST);

            if (str_starts_with($normalized, 'http://') && ! in_array($host, self::LOCAL_HOSTS, true)) {
                $fail('El dominio debe usar https. Solo localhost y 127.0.0.1 se admiten con http, para desarrollo.');
            }
        };
    }

    private function contrastRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (! is_string($value) || preg_match('/^#[0-9a-fA-F]{6}$/', $value) !== 1) {
                return;
            }

            if (self::contrastWithWhite($value) < self::MIN_CONTRAST) {
                $fail(sprintf(
                    'El color es demasiado claro para leer texto blanco encima (contraste %.1f:1; se necesita al menos %.1f:1). Elige un tono más oscuro.',
                    self::contrastWithWhite($value),
                    self::MIN_CONTRAST,
                ));
            }
        };
    }

    private function nullableString(string $field): ?string
    {
        $value = $this->input($field);

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return trim($value);
    }
}
