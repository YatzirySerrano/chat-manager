<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Automatic messages of the connection for the team's actions on a
 * conversation: «Agente asignado» (a person takes it), «Transferido a
 * departamento» and «Transferido a otro agente». Additive: every message
 * starts off, except «Agente asignado» on web chat connections, where the
 * visitor sees who is answering anyway.
 */
return new class extends Migration
{
    private const AGENT_ASSIGNED_TEXT = 'Hola, soy {{agente}}. ¿En qué te puedo ayudar?';

    public function up(): void
    {
        Schema::table('connections', function (Blueprint $table) {
            $table->text('agent_assigned_message')->nullable()->after('farewell_enabled');
            $table->boolean('agent_assigned_enabled')->default(false)->after('agent_assigned_message');
            $table->text('department_transfer_message')->nullable()->after('agent_assigned_enabled');
            $table->boolean('department_transfer_enabled')->default(false)->after('department_transfer_message');
            $table->text('agent_transfer_message')->nullable()->after('department_transfer_enabled');
            $table->boolean('agent_transfer_enabled')->default(false)->after('agent_transfer_message');
        });

        DB::table('connections')->where('channel', 'web_chat')->update([
            'agent_assigned_message' => self::AGENT_ASSIGNED_TEXT,
            'agent_assigned_enabled' => true,
        ]);
    }

    public function down(): void
    {
        Schema::table('connections', function (Blueprint $table) {
            $table->dropColumn([
                'agent_assigned_message', 'agent_assigned_enabled',
                'department_transfer_message', 'department_transfer_enabled',
                'agent_transfer_message', 'agent_transfer_enabled',
            ]);
        });
    }
};
