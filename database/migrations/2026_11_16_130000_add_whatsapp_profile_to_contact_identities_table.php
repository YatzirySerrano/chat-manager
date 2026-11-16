<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What WhatsApp shares about a person, read through a WhatsApp Web (QR)
 * session (RefreshWhatsAppWebContactProfile): the profile picture, kept as
 * a copy on the private media disk (never a link to WhatsApp's CDN), the
 * "about" text with its date and, for business accounts, their public
 * business profile. It belongs to the WhatsApp account, so it lives on the
 * identity. WhatsApp Cloud API never shares it: those identities keep NULL.
 * Additive: existing rows keep NULL until their first refresh.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contact_identities', function (Blueprint $table) {
            $table->string('avatar_disk', 40)->nullable()->after('last_seen_at');
            $table->string('avatar_path', 500)->nullable()->after('avatar_disk');
            // SHA-256 of the stored picture: an unchanged picture is not stored again.
            $table->char('avatar_hash', 64)->nullable()->after('avatar_path');
            $table->text('profile_about')->nullable()->after('avatar_hash');
            $table->timestamp('profile_about_set_at')->nullable()->after('profile_about');
            $table->json('business_profile')->nullable()->after('profile_about_set_at');
            // Last time WhatsApp was asked (null: never).
            $table->timestamp('profile_fetched_at')->nullable()->after('business_profile');
        });
    }

    public function down(): void
    {
        Schema::table('contact_identities', function (Blueprint $table) {
            $table->dropColumn(['avatar_disk', 'avatar_path', 'avatar_hash', 'profile_about', 'profile_about_set_at', 'business_profile', 'profile_fetched_at']);
        });
    }
};
