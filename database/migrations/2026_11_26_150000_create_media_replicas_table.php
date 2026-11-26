<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Local first media (App\Services\Storage\LocalMedia): one row per file of a
 * mounted disk (the NAS) that has a copy on this server, either because it
 * was written here first ("upload", pending until the NAS holds a verified
 * copy) or because a file only on the NAS was brought back ("cache").
 * The attachment rows keep pointing at the NAS disk and path: nothing of
 * theirs changes. Additive only.
 *
 * media_health_events keeps the state changes of the NAS (incident history
 * of Administración > Almacenamiento).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media_replicas', function (Blueprint $table) {
            $table->id();
            $table->string('disk', 64);
            $table->string('path', 1024);
            $table->char('path_hash', 64)->unique();
            $table->string('origin', 16)->default('upload');
            $table->string('state', 16)->default('pending');
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->char('sha256', 64)->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('next_attempt_at')->nullable();
            $table->timestamp('claimed_until')->nullable();
            $table->string('last_error', 64)->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->unsignedInteger('sync_ms')->nullable();
            $table->boolean('local_present')->default(true);
            $table->timestamp('local_purged_at')->nullable();
            $table->timestamp('last_accessed_at')->nullable();
            $table->timestamps();

            $table->index(['state', 'next_attempt_at']);
            $table->index(['local_present', 'state', 'last_accessed_at']);
            $table->index('synced_at');
        });

        Schema::create('media_health_events', function (Blueprint $table) {
            $table->id();
            $table->string('disk', 64);
            $table->string('state', 24);
            $table->string('previous_state', 24)->nullable();
            $table->string('problem', 64)->nullable();
            $table->unsignedInteger('latency_ms')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['disk', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media_health_events');
        Schema::dropIfExists('media_replicas');
    }
};
