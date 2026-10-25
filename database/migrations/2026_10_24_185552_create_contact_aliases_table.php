<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Private name a user gives a contact for themselves. It is scoped to the
     * contact (it follows the person across conversations and identity
     * links) and never changes the contact's official data.
     */
    public function up(): void
    {
        Schema::create('contact_aliases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contact_id')->constrained()->cascadeOnDelete();
            $table->string('alias', 80);
            $table->timestamps();

            $table->unique(['user_id', 'contact_id']);
            $table->index('contact_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('contact_aliases');
    }
};
