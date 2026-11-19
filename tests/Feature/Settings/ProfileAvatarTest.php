<?php

use App\Models\User;
use App\Services\Users\UserAvatars;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
    config(['filesystems.media_disk' => 'local']);
});

function avatarPng(int $width = 600, int $height = 400): UploadedFile
{
    $image = imagecreatetruecolor($width, $height);
    imagefill($image, 0, 0, (int) imagecolorallocate($image, 40, 120, 200));
    $path = tempnam(sys_get_temp_dir(), 'avatar-test-').'.png';
    imagepng($image, $path);

    return new UploadedFile($path, 'foto.png', 'image/png', null, true);
}

test('a person uploads their profile picture, stored square as JPEG', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('profile.avatar.update'), ['avatar' => avatarPng()])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('profile.edit'));

    $user->refresh();

    expect($user->hasAvatar())->toBeTrue()
        ->and($user->avatar_path)->toStartWith(UserAvatars::FOLDER.'/')
        ->and($user->avatarUrl())->toContain('/users/'.$user->id.'/avatar');

    Storage::disk('local')->assertExists($user->avatar_path);
    $size = getimagesizefromstring(Storage::disk('local')->get($user->avatar_path));
    expect([$size[0], $size[1], $size['mime']])->toBe([UserAvatars::SIZE, UserAvatars::SIZE, 'image/jpeg']);
});

test('teammates can see the picture and it is shared with every page', function () {
    $owner = User::factory()->create();
    $this->actingAs($owner)->post(route('profile.avatar.update'), ['avatar' => avatarPng()]);
    $owner->refresh();

    $viewer = User::factory()->create();

    $this->actingAs($viewer)->get(route('users.avatar', ['user' => $owner->id, 'v' => $owner->avatar_version]))
        ->assertOk()
        ->assertHeader('Content-Type', 'image/jpeg');

    expect(app(UserAvatars::class)->map($viewer))->toHaveKey($owner->id);
});

test('a picture can be removed and replacing one deletes the previous file', function () {
    $user = User::factory()->create();
    $this->actingAs($user)->post(route('profile.avatar.update'), ['avatar' => avatarPng()]);
    $first = $user->refresh()->avatar_path;

    $this->actingAs($user)->post(route('profile.avatar.update'), ['avatar' => avatarPng(300, 500)]);
    $second = $user->refresh()->avatar_path;

    expect($second)->not->toBe($first);
    Storage::disk('local')->assertMissing($first);

    $this->actingAs($user)->delete(route('profile.avatar.destroy'))->assertRedirect(route('profile.edit'));

    expect($user->refresh()->hasAvatar())->toBeFalse();
    Storage::disk('local')->assertMissing($second);
});

test('files that are not images are refused', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('profile.avatar.update'), ['avatar' => UploadedFile::fake()->create('virus.pdf', 10, 'application/pdf')])
        ->assertSessionHasErrors('avatar');

    expect($user->refresh()->hasAvatar())->toBeFalse();
});

test('guests cannot load pictures', function () {
    $user = User::factory()->create();

    $this->get(route('users.avatar', ['user' => $user->id]))->assertRedirect();
});
