<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * «Solicitud completada»: when an agent closes a conversation marking the
 * credit request as completed, the customer's next message never reopens it
 * (whatever the connection's reopening window): it starts a new request
 * that enters the waiting list («Pendientes»).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table): void {
            $table->timestamp('completed_at')->nullable()->after('closed_by_user_id');
        });
    }

    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table): void {
            $table->dropColumn('completed_at');
        });
    }
};
