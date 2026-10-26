<?php

namespace App\Services\Meta;

use App\Models\MessageTemplate;
use Illuminate\Support\Str;

/**
 * Reads the components of a WhatsApp template as Meta returns them and
 * derives everything the application needs to send it: the variables to
 * fill ("header.1", "body.1" or "body.customer_name", "button.0.1",
 * "header.media"), their validation, a plain-text preview stored as the
 * message body, and the "components" block of the send request.
 *
 * Templates whose parts cannot be filled from this application (location
 * headers, one-time-password, copy-code, flow or catalog buttons, carousels,
 * limited-time offers) are reported as not sendable instead of guessing.
 */
final class TemplateStructure
{
    private const PLACEHOLDER = '/\{\{\s*([A-Za-z0-9_]+)\s*\}\}/';

    /** Meta's limit for a body variable. */
    public const BODY_PARAMETER_MAX = 1024;

    /** Meta's limit for the whole text header. */
    public const HEADER_MAX = 60;

    /**
     * @var array{format: string, text: string|null, parameters: list<string>, examples: list<string>}|null
     */
    private ?array $header = null;

    /**
     * @var array{text: string, parameters: list<string>, examples: list<string>}|null
     */
    private ?array $body = null;

    private ?string $footer = null;

    /**
     * @var list<array{type: string, text: string, url: string|null, phone_number: string|null, parameters: list<string>, examples: list<string>}>
     */
    private array $buttons = [];

    private ?string $unsupported = null;

    /**
     * @param  list<array<string, mixed>>  $components
     */
    public function __construct(array $components, private readonly bool $named = false, ?string $category = null)
    {
        if (strtoupper((string) $category) === 'AUTHENTICATION') {
            $this->unsupported = 'Las plantillas de autenticación (códigos de un solo uso) no se envían desde la bandeja.';
        }

        foreach ($components as $component) {
            $type = strtoupper((string) ($component['type'] ?? ''));

            match ($type) {
                'HEADER' => $this->readHeader($component),
                'BODY' => $this->body = $this->readText($component, 'body_text'),
                'FOOTER' => $this->footer = is_string($component['text'] ?? null) ? $component['text'] : null,
                'BUTTONS' => $this->readButtons($component),
                default => $this->unsupported ??= 'La plantilla tiene un componente que la bandeja no admite ('.Str::limit($type, 30).').',
            };
        }

        if ($this->body === null) {
            $this->unsupported ??= 'La plantilla no tiene cuerpo de texto.';
        }
    }

    public static function fromTemplate(MessageTemplate $template): self
    {
        return new self($template->components ?? [], strtoupper((string) $template->parameter_format) === 'NAMED', $template->category);
    }

    public function isSendable(): bool
    {
        return $this->unsupported === null;
    }

    public function unsupportedReason(): ?string
    {
        return $this->unsupported;
    }

    /**
     * Variables to fill, in display order.
     *
     * @return list<array{key: string, component: string, label: string, kind: string, example: string|null, max: int}>
     */
    public function parameters(): array
    {
        $parameters = [];

        if ($this->header !== null) {
            if (in_array($this->header['format'], ['IMAGE', 'VIDEO', 'DOCUMENT'], true)) {
                $parameters[] = [
                    'key' => 'header.media',
                    'component' => 'header',
                    'label' => match ($this->header['format']) {
                        'IMAGE' => 'Imagen del encabezado (enlace público que empiece con https://)',
                        'VIDEO' => 'Video del encabezado (enlace público que empiece con https://)',
                        default => 'Documento del encabezado (enlace público que empiece con https://)',
                    },
                    'kind' => 'media_url',
                    'example' => null,
                    'max' => 2048,
                ];
            }

            foreach ($this->header['parameters'] as $position => $name) {
                $parameters[] = ['key' => "header.{$name}", 'component' => 'header', 'label' => 'Encabezado – '.self::parameterName($name), 'kind' => 'text', 'example' => $this->header['examples'][$position] ?? null, 'max' => self::HEADER_MAX];
            }
        }

        foreach ($this->body['parameters'] ?? [] as $position => $name) {
            $parameters[] = ['key' => "body.{$name}", 'component' => 'body', 'label' => 'Texto – '.self::parameterName($name), 'kind' => 'text', 'example' => $this->body['examples'][$position] ?? null, 'max' => self::BODY_PARAMETER_MAX];
        }

        foreach ($this->buttons as $index => $button) {
            foreach ($button['parameters'] as $position => $name) {
                $parameters[] = ['key' => "button.{$index}.{$name}", 'component' => 'button', 'label' => "Botón «{$button['text']}» – ".self::parameterName($name), 'kind' => 'text', 'example' => $button['examples'][$position] ?? null, 'max' => 2000];
            }
        }

        return $parameters;
    }

    /**
     * Validation errors of the given values, keyed by variable.
     *
     * @param  array<string, mixed>  $values
     * @return array<string, string>
     */
    public function errors(array $values): array
    {
        $errors = [];

        foreach ($this->parameters() as $parameter) {
            $value = $values[$parameter['key']] ?? null;

            if (! is_string($value) || trim($value) === '') {
                $errors[$parameter['key']] = "Completa la variable «{$parameter['label']}».";

                continue;
            }

            if ($parameter['kind'] === 'media_url') {
                if (filter_var($value, FILTER_VALIDATE_URL) === false || ! str_starts_with(strtolower($value), 'https://')) {
                    $errors[$parameter['key']] = 'El archivo del encabezado debe ser una URL https válida.';
                }

                continue;
            }

            if (mb_strlen($value) > $parameter['max']) {
                $errors[$parameter['key']] = "La variable «{$parameter['label']}» admite como máximo {$parameter['max']} caracteres.";
            } elseif (preg_match('/[\r\n\t]| {5,}/', $value) === 1) {
                $errors[$parameter['key']] = "La variable «{$parameter['label']}» no puede tener saltos de línea, tabuladores ni más de cuatro espacios seguidos (regla de Meta).";
            }
        }

        if ($errors === [] && $this->header !== null && $this->header['format'] === 'TEXT' && mb_strlen($this->fill((string) $this->header['text'], 'header', $values)) > self::HEADER_MAX) {
            $errors['header'] = 'El encabezado con sus variables supera los 60 caracteres que permite Meta.';
        }

        $unknown = array_diff(array_keys($values), array_column($this->parameters(), 'key'));

        foreach ($unknown as $key) {
            $errors[(string) $key] = 'Esta variable no existe en la plantilla.';
        }

        return $errors;
    }

    /**
     * Plain-text rendering of the message the customer will receive.
     *
     * @param  array<string, mixed>  $values
     */
    public function preview(array $values = []): string
    {
        $parts = [];

        if ($this->header !== null) {
            $parts[] = $this->header['format'] === 'TEXT'
                ? '*'.$this->fill((string) $this->header['text'], 'header', $values).'*'
                : '['.match ($this->header['format']) {
                    'IMAGE' => 'Imagen',
                    'VIDEO' => 'Video',
                    'DOCUMENT' => 'Documento',
                    default => 'Encabezado',
                }.']';
        }

        if ($this->body !== null) {
            $parts[] = $this->fill($this->body['text'], 'body', $values);
        }

        if ($this->footer !== null && $this->footer !== '') {
            $parts[] = '_'.$this->footer.'_';
        }

        if ($this->buttons !== []) {
            $parts[] = implode("\n", array_map(fn (array $button): string => '['.$button['text'].']', $this->buttons));
        }

        return implode("\n\n", $parts);
    }

    /**
     * "components" of the Cloud API send request.
     *
     * @param  array<string, mixed>  $values
     * @return list<array<string, mixed>>
     */
    public function sendComponents(array $values): array
    {
        $components = [];

        if ($this->header !== null) {
            $format = $this->header['format'];

            if (in_array($format, ['IMAGE', 'VIDEO', 'DOCUMENT'], true)) {
                $type = strtolower($format);
                $components[] = ['type' => 'header', 'parameters' => [['type' => $type, $type => ['link' => (string) ($values['header.media'] ?? '')]]]];
            } elseif ($this->header['parameters'] !== []) {
                $components[] = ['type' => 'header', 'parameters' => $this->textParameters('header', $this->header['parameters'], $values)];
            }
        }

        if ($this->body !== null && $this->body['parameters'] !== []) {
            $components[] = ['type' => 'body', 'parameters' => $this->textParameters('body', $this->body['parameters'], $values)];
        }

        foreach ($this->buttons as $index => $button) {
            if ($button['parameters'] === []) {
                continue;
            }

            $components[] = [
                'type' => 'button',
                'sub_type' => strtolower($button['type']),
                'index' => (string) $index,
                'parameters' => array_map(fn (string $name): array => ['type' => 'text', 'text' => (string) ($values["button.{$index}.{$name}"] ?? '')], $button['parameters']),
            ];
        }

        return $components;
    }

    /**
     * Structure for the interface (preview of each part).
     *
     * @return array{header: array{format: string, text: string|null}|null, body: string|null, footer: string|null, buttons: list<array{type: string, text: string, url: string|null, phone_number: string|null}>}
     */
    public function describe(): array
    {
        return [
            'header' => $this->header === null ? null : ['format' => $this->header['format'], 'text' => $this->header['text']],
            'body' => $this->body['text'] ?? null,
            'footer' => $this->footer,
            'buttons' => array_map(fn (array $button): array => [
                'type' => $button['type'],
                'text' => $button['text'],
                'url' => $button['url'],
                'phone_number' => $button['phone_number'],
            ], $this->buttons),
        ];
    }

    /**
     * @param  array<string, mixed>  $component
     */
    private function readHeader(array $component): void
    {
        $format = strtoupper((string) ($component['format'] ?? 'TEXT'));

        if (! in_array($format, ['TEXT', 'IMAGE', 'VIDEO', 'DOCUMENT'], true)) {
            $this->unsupported ??= 'Los encabezados de tipo '.Str::limit($format, 20).' no se envían desde la bandeja.';
        }

        $text = $format === 'TEXT' ? $this->readText($component, 'header_text') : null;

        $this->header = [
            'format' => $format,
            'text' => $text['text'] ?? null,
            'parameters' => $text['parameters'] ?? [],
            'examples' => $text['examples'] ?? [],
        ];
    }

    /**
     * @param  array<string, mixed>  $component
     * @return array{text: string, parameters: list<string>, examples: list<string>}
     */
    private function readText(array $component, string $exampleKey): array
    {
        $text = is_string($component['text'] ?? null) ? $component['text'] : '';
        $parameters = $this->placeholders($text);
        $examples = [];
        $example = is_array($component['example'] ?? null) ? $component['example'] : [];

        if ($this->named && is_array($example[$exampleKey.'_named_params'] ?? null)) {
            $byName = [];

            foreach ($example[$exampleKey.'_named_params'] as $named) {
                if (is_array($named) && is_string($named['param_name'] ?? null)) {
                    $byName[$named['param_name']] = is_scalar($named['example'] ?? null) ? (string) $named['example'] : '';
                }
            }

            $examples = array_map(fn (string $name): string => $byName[$name] ?? '', $parameters);
        } elseif (isset($example[$exampleKey])) {
            $values = $example[$exampleKey];
            $values = is_array($values) && is_array($values[0] ?? null) ? $values[0] : $values;
            $examples = is_array($values) ? array_values(array_map(fn (mixed $value): string => is_scalar($value) ? (string) $value : '', $values)) : [];
        }

        return ['text' => $text, 'parameters' => $parameters, 'examples' => $examples];
    }

    /**
     * @param  array<string, mixed>  $component
     */
    private function readButtons(array $component): void
    {
        foreach (is_array($component['buttons'] ?? null) ? $component['buttons'] : [] as $button) {
            if (! is_array($button)) {
                continue;
            }

            $type = strtoupper((string) ($button['type'] ?? ''));

            if (! in_array($type, ['QUICK_REPLY', 'URL', 'PHONE_NUMBER'], true)) {
                $this->unsupported ??= 'Los botones de tipo '.Str::limit($type, 20).' no se envían desde la bandeja.';
            }

            $url = is_string($button['url'] ?? null) ? $button['url'] : null;
            $parameters = $type === 'URL' && $url !== null ? $this->placeholders($url) : [];
            $examples = is_array($button['example'] ?? null) ? array_values(array_filter($button['example'], 'is_string')) : [];

            $this->buttons[] = [
                'type' => $type,
                'text' => is_string($button['text'] ?? null) ? $button['text'] : $type,
                'url' => $url,
                'phone_number' => is_string($button['phone_number'] ?? null) ? $button['phone_number'] : null,
                'parameters' => $parameters,
                'examples' => $examples,
            ];
        }
    }

    /**
     * @return list<string>
     */
    private function placeholders(string $text): array
    {
        preg_match_all(self::PLACEHOLDER, $text, $matches);
        $names = array_values(array_unique($matches[1]));

        if (! $this->named) {
            usort($names, fn (string $a, string $b): int => (int) $a <=> (int) $b);
        }

        return $names;
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function fill(string $text, string $component, array $values): string
    {
        return (string) preg_replace_callback(self::PLACEHOLDER, function (array $match) use ($component, $values): string {
            $value = $values["{$component}.{$match[1]}"] ?? null;

            return is_string($value) && $value !== '' ? $value : $match[0];
        }, $text);
    }

    /**
     * @param  list<string>  $names
     * @param  array<string, mixed>  $values
     * @return list<array<string, string>>
     */
    private function textParameters(string $component, array $names, array $values): array
    {
        return array_map(fn (string $name): array => [
            'type' => 'text',
            ...($this->named ? ['parameter_name' => $name] : []),
            'text' => (string) ($values["{$component}.{$name}"] ?? ''),
        ], $names);
    }

    /**
     * How a placeholder is named in the form: "dato 1" for positional ones,
     * "dato «nombre»" for named ones (never the raw {{…}} syntax).
     */
    private static function parameterName(string|int $name): string
    {
        return ctype_digit((string) $name) ? "dato {$name}" : "dato «{$name}»";
    }
}
