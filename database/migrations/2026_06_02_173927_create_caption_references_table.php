<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('caption_references', function (Blueprint $table) {
            $table->id();
            $table->string('platform')->index(); // youtube_shorts, tiktok
            $table->string('category')->default('clip')->index();
            $table->string('language')->default('id')->index();
            $table->string('title_example')->nullable();
            $table->text('description_example')->nullable();
            $table->string('hook_example')->nullable();
            $table->string('hashtags_example')->nullable();
            $table->string('source_url')->nullable()->index();
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->integer('quality_score')->nullable();
            $table->string('content_hash')->nullable()->index();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('caption_references');
    }
};
