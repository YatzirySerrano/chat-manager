<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Catalog of request categories (what the customer is asking for). A
     * category classifies a conversation and may override part of the
     * service rules (see App\Services\ServiceRules); `service_rules` is a flat
     * map of dotted rule key → value where an absent key inherits.
     *
     * Like tags, the unique name relies on the case-insensitive collation of
     * the table and deleting is logical (`is_active`).
     */
    public function up(): void
    {
        Schema::create('conversation_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120)->unique();
            $table->string('description', 255)->nullable();
            $table->string('color', 20);
            $table->boolean('is_active')->default(true)->index();
            $table->json('service_rules')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('conversation_categories');
    }
};
