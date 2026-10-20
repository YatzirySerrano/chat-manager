<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * A department may greet customers with its own message and follow its own
     * business hours; while `uses_custom_hours` is false the platform hours
     * apply and `business_hours` is ignored.
     */
    public function up(): void
    {
        Schema::table('departments', function (Blueprint $table) {
            $table->text('welcome_message')->nullable()->after('color');
            $table->boolean('uses_custom_hours')->default(false)->after('welcome_message');
            $table->json('business_hours')->nullable()->after('uses_custom_hours');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('departments', function (Blueprint $table) {
            $table->dropColumn(['welcome_message', 'uses_custom_hours', 'business_hours']);
        });
    }
};
