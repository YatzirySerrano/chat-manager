<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

/**
 * Builds a releases tree for rollback.sh: complete releases have REVISION,
 * vendor/autoload.php and public/build/manifest.json.
 *
 * @param  array<string, bool>  $releases  name => complete
 */
function releasesTree(array $releases, string $current): string
{
    $root = storage_path('framework/testing/deploy-'.bin2hex(random_bytes(4)));

    foreach ($releases as $name => $complete) {
        File::ensureDirectoryExists("{$root}/releases/{$name}");

        if ($complete) {
            File::ensureDirectoryExists("{$root}/releases/{$name}/vendor");
            File::ensureDirectoryExists("{$root}/releases/{$name}/public/build");
            File::put("{$root}/releases/{$name}/REVISION", $name);
            File::put("{$root}/releases/{$name}/vendor/autoload.php", '<?php');
            File::put("{$root}/releases/{$name}/public/build/manifest.json", '{}');
        }
    }

    symlink("{$root}/releases/{$current}", "{$root}/current");

    return $root;
}

test('commands run as root never execute a script from the application tree', function () {
    $cron = File::get(base_path('deploy/cron/chat-manager'));
    $rootCommands = collect(explode("\n", $cron))
        ->filter(fn (string $line): bool => preg_match('/^[^#\s]\S*\s+\S+\s+\S+\s+\S+\s+\S+\s+root\s/', $line) === 1);
    $sudoCalls = collect(['deploy.sh', 'rollback.sh'])
        ->flatMap(fn (string $script): array => preg_match_all('/\bsudo\s+(\S+)/', File::get(base_path("deploy/scripts/{$script}")), $matches) ? $matches[1] : []);
    $sudoers = File::get(base_path('deploy/sudoers/chat-manager'));

    expect($rootCommands)->not->toBeEmpty()
        ->each(fn ($line) => $line->not->toContain('/var/www'))
        ->and($sudoCalls->all())->each->not->toContain('RELEASE_DIR')
        ->and($sudoCalls->all())->each->not->toContain('/var/www')
        ->and(preg_replace('/^#.*$/m', '', $sudoers))->not->toContain('/var/www')
        ->and($sudoers)->toContain('/usr/local/sbin/chat-manager-backup-database ""');
});

test('a deployment adds the new catalog permissions right after migrating', function () {
    $deploy = File::get(base_path('deploy/scripts/deploy.sh'));

    expect($deploy)->toContain('"$PHP" artisan app:sync-permissions --no-interaction')
        ->and(strpos($deploy, 'artisan app:sync-permissions'))->toBeGreaterThan((int) strpos($deploy, 'artisan migrate --force'))
        ->and(strpos($deploy, 'artisan app:sync-permissions'))->toBeLessThan((int) strpos($deploy, 'Switching current'));
});

test('the web chat loader is served with a short cache and nosniff', function () {
    $nginx = File::get(base_path('deploy/nginx/chat-manager.conf'));

    expect(preg_match('/location = \/widget\/v1\/chat-manager\.js \{(.*?)\}/s', $nginx, $block))->toBe(1)
        ->and($block[1])->toContain('try_files $uri =404;')
        ->toContain('Cache-Control "public, max-age=300, stale-while-revalidate=86400" always')
        ->toContain('X-Content-Type-Options nosniff always');
});

test('every deployment script has valid bash syntax', function (string $script) {
    $result = Process::run(['bash', '-n', base_path("deploy/scripts/{$script}")]);

    expect($result->successful())->toBeTrue($result->errorOutput());
})->with(['deploy.sh', 'rollback.sh', 'prune-releases.sh', 'backup-database.sh', 'backup-storage.sh', 'backup-offsite.sh', 'restore-database.sh', 'restore-storage.sh']);

test('rollback skips a newer release that never became complete', function () {
    $root = releasesTree(['20261001000000' => true, '20261002000000' => false, '20261003000000' => true], '20261003000000');

    $result = Process::env(['APP_DIR' => $root, 'ROLLBACK_DRY_RUN' => '1'])->run([base_path('deploy/scripts/rollback.sh')]);
    File::deleteDirectory($root);

    expect($result->successful())->toBeTrue($result->errorOutput())
        ->and($result->output())->toContain('Rolling back 20261003000000 -> 20261001000000');
});

test('rollback chooses an older release even when a newer one was modified later', function () {
    $root = releasesTree(['20261001000000' => true, '20261002000000' => true, '20261003000000' => true], '20261002000000');
    touch("{$root}/releases/20261001000000", time() - 3600);
    touch("{$root}/releases/20261003000000", time());

    $result = Process::env(['APP_DIR' => $root, 'ROLLBACK_DRY_RUN' => '1'])->run([base_path('deploy/scripts/rollback.sh')]);
    File::deleteDirectory($root);

    expect($result->output())->toContain('Rolling back 20261002000000 -> 20261001000000');
});

test('rollback refuses an incomplete release given explicitly', function () {
    $root = releasesTree(['20261001000000' => false, '20261003000000' => true], '20261003000000');

    $result = Process::env(['APP_DIR' => $root, 'ROLLBACK_DRY_RUN' => '1'])->run([base_path('deploy/scripts/rollback.sh'), '20261001000000']);
    File::deleteDirectory($root);

    expect($result->failed())->toBeTrue()
        ->and($result->errorOutput())->toContain('incomplete');
});

test('rollback refuses a release name that is not a timestamp', function () {
    $root = releasesTree(['20261003000000' => true], '20261003000000');

    $result = Process::env(['APP_DIR' => $root, 'ROLLBACK_DRY_RUN' => '1'])->run([base_path('deploy/scripts/rollback.sh'), '../../etc']);
    File::deleteDirectory($root);

    expect($result->failed())->toBeTrue()
        ->and($result->errorOutput())->toContain('Invalid release name');
});

test('rollback never chooses a release marked as failed', function () {
    $root = releasesTree(['20261001000000' => true, '20261002000000' => true, '20261003000000' => true], '20261003000000');
    File::put("{$root}/releases/20261002000000/FAILED", 'rolled back');

    $result = Process::env(['APP_DIR' => $root, 'ROLLBACK_DRY_RUN' => '1'])->run([base_path('deploy/scripts/rollback.sh')]);
    $explicit = Process::env(['APP_DIR' => $root, 'ROLLBACK_DRY_RUN' => '1'])->run([base_path('deploy/scripts/rollback.sh'), '20261002000000']);
    File::deleteDirectory($root);

    expect($result->output())->toContain('Rolling back 20261003000000 -> 20261001000000')
        ->and($explicit->failed())->toBeTrue()
        ->and($explicit->errorOutput())->toContain('marked FAILED');
});

test('pruning succeeds when the active release is the only one', function () {
    $root = releasesTree(['20261001000000' => true], '20261001000000');

    $result = Process::env(['APP_DIR' => $root, 'KEEP_RELEASES' => '5'])->run([base_path('deploy/scripts/prune-releases.sh')]);
    $kept = File::isDirectory("{$root}/releases/20261001000000");
    File::deleteDirectory($root);

    expect($result->successful())->toBeTrue($result->errorOutput())
        ->and($kept)->toBeTrue();
});

test('pruning keeps the newest releases and never the active one', function () {
    $root = releasesTree([
        '20261001000000' => true, '20261002000000' => true, '20261003000000' => true, '20261004000000' => true,
    ], '20261001000000');

    $result = Process::env(['APP_DIR' => $root, 'KEEP_RELEASES' => '2'])->run([base_path('deploy/scripts/prune-releases.sh')]);
    $remaining = collect(File::directories("{$root}/releases"))->map(fn (string $path): string => basename($path))->sort()->values()->all();
    File::deleteDirectory($root);

    expect($result->successful())->toBeTrue($result->errorOutput())
        ->and($remaining)->toBe(['20261001000000', '20261004000000']);
});
