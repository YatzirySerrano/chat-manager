<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * In-app notifications (Laravel database notifications) plus the columns the
 * notification center needs: the kind and level for filtering, and an
 * aggregation key so bursts of the same event fold into a single unread
 * notification. `unread_aggregate_key` is a stored generated column: only
 * one unread notification per (recipient, aggregation key) can exist, which
 * the database enforces even when workers race.
 *
 * The payload only stores identifiers and safe metadata; the visible text is
 * built when the recipient reads it, after checking access again.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->string('kind', 60);
            $table->string('level', 20);
            $table->string('aggregate_key', 191)->nullable();
            $table->string('unread_aggregate_key', 191)->nullable()->storedAs('IF(`read_at` IS NULL, `aggregate_key`, NULL)');
            $table->unsignedInteger('occurrences')->default(1);
            $table->json('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->unique(['notifiable_type', 'notifiable_id', 'unread_aggregate_key'], 'notifications_unread_aggregate_unique');
            $table->index(['notifiable_type', 'notifiable_id', 'read_at', 'updated_at'], 'notifications_inbox_index');
            $table->index('updated_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
