<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Users\UserAvatars;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * «Ajustes → Perfil → Foto»: each person sets or removes their own profile
 * picture, the one teammates see next to their name everywhere.
 */
class ProfileAvatarController extends Controller
{
    public function update(Request $request, UserAvatars $avatars): RedirectResponse
    {
        $request->validate([
            'avatar' => ['required', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:'.UserAvatars::MAX_UPLOAD_KB],
        ], [
            'avatar.required' => 'Elige una foto.',
            'avatar.image' => 'El archivo debe ser una imagen.',
            'avatar.mimes' => 'Usa una foto JPG, PNG o WebP.',
            'avatar.max' => 'La foto no puede pasar de '.intdiv(UserAvatars::MAX_UPLOAD_KB, 1024).' MB.',
        ]);

        /** @var User $user */
        $user = $request->user();
        $avatars->store($user, $request->file('avatar'));

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Foto de perfil actualizada.']);

        return to_route('profile.edit');
    }

    public function destroy(Request $request, UserAvatars $avatars): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $avatars->remove($user);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Foto de perfil quitada.']);

        return to_route('profile.edit');
    }
}
