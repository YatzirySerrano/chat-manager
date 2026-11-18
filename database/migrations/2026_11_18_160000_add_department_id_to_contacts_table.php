<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Department where the contact's new conversations arrive (chosen by
     * hand on the contact). The router only honors it while the department
     * is active and the connection may route to it; otherwise the usual
     * chain applies. Deleting the department simply clears the choice.
     */
    public function up(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->foreignId('department_id')->nullable()->after('email')->constrained('departments')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('department_id');
        });
    }
};
