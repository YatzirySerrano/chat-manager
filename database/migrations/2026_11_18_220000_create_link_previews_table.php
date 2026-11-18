<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What a web address shared in a message looks like (its Open Graph
     * title, description, site and image), fetched once by the server and
     * kept until expires_at, so a chat never asks the site again for every
     * viewer. A failed fetch is kept too (status "failed"), for less time,
     * so a broken address is not retried on every render. The image and the
     * icon are copies on the private media disk: the browser never loads
     * them from the other site.
     */
    public function up(): void
    {
        Schema::create('link_previews', function (Blueprint $table) {
            $table->id();
            $table->char('url_hash', 64)->unique();
            $table->text('url');
            $table->text('final_url')->nullable();
            $table->string('status', 16);
            $table->string('error', 64)->nullable();
            $table->string('title', 300)->nullable();
            $table->string('description', 600)->nullable();
            $table->string('site_name', 150)->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->string('image_disk', 32)->nullable();
            $table->string('image_path', 1024)->nullable();
            $table->string('image_mime', 64)->nullable();
            $table->unsignedSmallInteger('image_width')->nullable();
            $table->unsignedSmallInteger('image_height')->nullable();
            $table->string('icon_disk', 32)->nullable();
            $table->string('icon_path', 1024)->nullable();
            $table->string('icon_mime', 64)->nullable();
            $table->timestamp('fetched_at')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('link_previews');
    }
};
