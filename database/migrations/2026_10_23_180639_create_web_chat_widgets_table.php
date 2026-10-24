<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Widget settings of a web chat connection (1:1): the public key the
     * embed snippet carries (rotatable, not a secret), the origins allowed to
     * embed it, its appearance, how visitors identify themselves (the
     * identity secret is encrypted by the model), the pre-chat form, the
     * availability and the idle lifetime of visitor sessions. Kept apart
     * from `connections`, whose columns are about Meta.
     */
    public function up(): void
    {
        Schema::create('web_chat_widgets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('connection_id')->unique()->constrained()->restrictOnDelete();
            $table->string('public_key', 64)->unique();
            $table->json('allowed_origins');
            $table->string('title', 60);
            $table->string('subtitle', 120)->nullable();
            $table->string('welcome_text', 500)->nullable();
            $table->string('offline_text', 500)->nullable();
            $table->string('position', 10)->default('right');
            $table->char('accent_color', 7)->default('#2563eb');
            $table->boolean('launcher_enabled')->default(true);
            $table->string('launcher_label', 40)->nullable();
            $table->string('avatar_path', 255)->nullable();
            $table->string('identity_mode', 20)->default('anonymous');
            $table->text('identity_secret')->nullable();
            $table->timestamp('identity_secret_rotated_at')->nullable();
            $table->json('prechat')->nullable();
            $table->string('availability_mode', 20)->default('always');
            $table->unsignedSmallInteger('session_idle_days')->default(30);
            $table->timestamp('key_rotated_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('web_chat_widgets');
    }
};
