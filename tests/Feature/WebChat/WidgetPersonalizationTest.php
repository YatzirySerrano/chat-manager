<?php

use App\Actions\WebChat\UpdateWidgetSettings;
use App\Models\User;
use App\Models\WebChatWidget;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Support\WebChatFixtures;

/*
| Personalization of the web chat widget: conversation colors, the floating
| button (colors, shape, icon, size, width, text size, offsets, pulse), the
| window size and the status / composer texts. Validated on save, empty
| values keep the original look, and the frame embeds them for the widget
| and the loader.
*/

beforeEach(function () {
    Http::preventStrayRequests();
    $this->withoutVite();
    $this->admin = User::factory()->superAdministrator()->create();
    $this->connection = WebChatFixtures::connection();
});

/**
 * @return array<string, mixed>
 */
function personalizationPayload(array $overrides = []): array
{
    return [
        'allowed_origins' => ['https://tienda.test'],
        'title' => 'Chatea con nosotros',
        'position' => 'right',
        'accent_color' => '#2563eb',
        'launcher_enabled' => true,
        'identity_mode' => 'anonymous',
        'availability_mode' => 'always',
        'session_idle_days' => 30,
        ...$overrides,
    ];
}

test('a new widget keeps the original look', function () {
    $widget = WebChatWidget::query()->sole()->refresh();

    expect($widget->colors())->each->toBeNull()
        ->and($widget->launcherStyle())->toMatchArray([
            'launcher_shape' => 'circle',
            'launcher_icon' => 'chat',
            'launcher_size' => 'medium',
            'launcher_offset' => ['x' => 0, 'y' => 0],
            'launcher_pulse' => false,
            'launcher_width' => null,
            'launcher_font_size' => 'medium',
            'panel' => ['width' => 380, 'height' => 600],
        ])
        ->and($widget->allow_visitor_files)->toBeTrue();
});

test('the personalization is validated and saved', function () {
    $this->actingAs($this->admin)
        ->put(route('connections.widget.update', $this->connection), personalizationPayload([
            'background_color' => '#FAFAFA',
            'visitor_bubble_color' => '#123456',
            'agent_text_color' => '',
            'launcher_shape' => 'pill',
            'launcher_icon' => 'headset',
            'launcher_size' => 'large',
            'launcher_width' => 180,
            'launcher_font_size' => 'small',
            'launcher_offset_x' => 24,
            'launcher_offset_y' => 120,
            'launcher_pulse' => true,
            'launcher_label' => '¿Te ayudamos?',
            'panel_width' => 440,
            'panel_height' => 700,
            'online_text' => 'Aquí estamos',
            'composer_placeholder' => 'Cuéntanos…',
            'allow_visitor_files' => false,
        ]))
        ->assertSessionHasNoErrors();

    expect(WebChatWidget::query()->sole())
        ->background_color->toBe('#fafafa')
        ->visitor_bubble_color->toBe('#123456')
        ->agent_text_color->toBeNull()
        ->launcher_shape->toBe('pill')
        ->launcher_width->toBe(180)
        ->panel_width->toBe(440)
        ->online_text->toBe('Aquí estamos')
        ->allow_visitor_files->toBeFalse();
});

test('out of range or unknown values are refused', function () {
    $this->actingAs($this->admin)
        ->put(route('connections.widget.update', $this->connection), personalizationPayload([
            'background_color' => 'red',
            'launcher_shape' => 'star',
            'launcher_offset_x' => 121,
            'launcher_width' => 20,
            'panel_width' => 600,
            'panel_height' => 100,
        ]))
        ->assertSessionHasErrors(['background_color', 'launcher_shape', 'launcher_offset_x', 'launcher_width', 'panel_width', 'panel_height']);
});

test('the frame embeds the colors, the button, the window and the file limits', function () {
    $this->connection->webChatWidget->forceFill([
        'background_color' => '#fafafa',
        'launcher_shape' => 'pill',
        'launcher_label' => 'Ayuda',
        'panel_height' => 700,
    ])->save();

    $response = $this->get(route('widget.frame', $this->connection->webChatWidget->public_key), ['Referer' => 'https://tienda.test/'])->assertOk();
    preg_match('#<script type="application/json" id="cm-config"[^>]*>(.*?)</script>#s', (string) $response->getContent(), $match);
    $config = json_decode($match[1] ?? 'null', true, flags: JSON_THROW_ON_ERROR);

    expect($config['appearance'])
        ->toMatchArray(['background_color' => '#fafafa', 'launcher_shape' => 'pill', 'panel' => ['width' => 380, 'height' => 700]])
        ->and($config['visitor_files']['enabled'])->toBeTrue()
        ->and($config['endpoints']['files'])->toBe('/api/v1/widget/session/files');
});

test('the button takes an image of its own, never an SVG, and it can be removed', function () {
    Storage::fake((string) config('filesystems.media_disk', 'local'));

    $svg = UploadedFile::fake()->createWithContent('icono.png', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');

    $this->actingAs($this->admin)
        ->put(route('connections.widget.update', $this->connection), personalizationPayload(['launcher_image' => $svg]))
        ->assertSessionHasErrors(['launcher_image']);

    $this->actingAs($this->admin)
        ->put(route('connections.widget.update', $this->connection), personalizationPayload(['launcher_image' => UploadedFile::fake()->image('icono.png', 64, 64)]))
        ->assertSessionHasNoErrors();

    $widget = WebChatWidget::query()->sole();
    expect($widget->launcher_icon_path)->not->toBeNull()
        ->and(UpdateWidgetSettings::imageDataUri($widget->launcher_icon_path))->toStartWith('data:image/png;base64,');

    $this->actingAs($this->admin)
        ->put(route('connections.widget.update', $this->connection), personalizationPayload(['remove_launcher_image' => true]))
        ->assertSessionHasNoErrors();

    expect(WebChatWidget::query()->sole()->launcher_icon_path)->toBeNull();
});
