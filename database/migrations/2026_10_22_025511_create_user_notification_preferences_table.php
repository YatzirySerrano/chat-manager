<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-user preferences of each notification kind. A missing row means the
 * defaults of the kind apply (see NotificationKind).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_notification_preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 60);
            $table->boolean('in_app');
            $table->boolean('browser');
            $table->boolean('sound');
            $table->timestamps();

            $table->unique(['user_id', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_notification_preferences');
    }
};
