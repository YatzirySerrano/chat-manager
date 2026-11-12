<?php

use App\Enums\DataScope;
use App\Enums\Permission;
use App\Models\Role;
use App\Models\Tag;
use App\Models\User;
use App\Reports\Rendering\ChromeLocator;
use App\Reports\Rendering\PdfRenderingFailed;

test('a PDF that Chrome cannot print answers with a readable message instead of a 500', function () {
    Tag::factory()->create();
    $role = Role::factory()->scope(DataScope::Global)->withPermissions([Permission::ViewTags, Permission::ExportReports])->create();
    $actor = User::factory()->withRole($role)->create();

    // A Node binary that does not exist: the same class of failure as a
    // server without Chrome.
    config(['exports.pdf.node_binary' => base_path('missing-node-binary')]);

    $this->actingAs($actor)
        ->from(route('tags.index'))
        ->post(route('exports.generate', ['tags', 'pdf']))
        ->assertRedirect(route('tags.index'));
});

test('the cause of a failed PDF is named from what Chrome printed, not from the command line', function (string $output, string $reason) {
    $exception = new RuntimeException("The command \"node browser.cjs --no-sandbox\" failed.\n\nError Output:\n================\n{$output}");

    expect(PdfRenderingFailed::from($exception)->reason)->toBe($reason);
})->with([
    'missing browser' => ['Error: Could not find Chrome (ver. 155.0.0.0).', 'chrome_missing'],
    'sandbox' => ['FATAL:zygote_host_impl_linux.cc No usable sandbox!', 'sandbox'],
    'libraries' => ['chrome: error while loading shared libraries: libnss3.so', 'libraries'],
    'timeout' => ['TimeoutError: Navigation timed out', 'timeout'],
    'unknown' => ['something else entirely', 'unknown'],
]);

test('a configured Chrome path that does not exist is reported and not used', function () {
    config(['exports.pdf.chrome_path' => base_path('missing-chrome-binary')]);

    $locator = new ChromeLocator;

    expect($locator->configuredPathIsBroken())->toBeTrue()
        ->and($locator->path())->not->toBe(base_path('missing-chrome-binary'));
});
