<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Departments whose members can use a non-global quick reply.
     */
    public function up(): void
    {
        Schema::create('department_quick_reply', function (Blueprint $table) {
            $table->id();
            $table->foreignId('department_id')->constrained()->cascadeOnDelete();
            $table->foreignId('quick_reply_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['quick_reply_id', 'department_id']);
            $table->index(['department_id', 'quick_reply_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('department_quick_reply');
    }
};
