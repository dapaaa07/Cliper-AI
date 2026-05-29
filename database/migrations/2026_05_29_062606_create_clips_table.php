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
        Schema::create('clips', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('video_id')->constrained('videos')->onDelete('cascade');
            $table->string('start_time');
            $table->string('end_time');
            $table->string('aspect_ratio')->default('vertical');
            $table->string('status')->default('pending');
            $table->string('video_path')->nullable();
            $table->string('subtitle_path')->nullable();
            $table->integer('progress')->default(0);
            $table->text('error_message')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('clips');
    }
};
