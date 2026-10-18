<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Each row represents one WhatsApp Business phone number registered in Meta.
     * Access tokens and app secrets are intentionally NOT stored here; they will
     * be provided through encrypted configuration in the integration increment.
     */
    public function up(): void
    {
        Schema::create('whatsapp_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->string('display_phone_number', 32);
            $table->string('phone_number_id', 64)->unique();
            $table->string('business_account_id', 64)->index();
            $table->boolean('is_active')->default(false);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('whatsapp_accounts');
    }
};
