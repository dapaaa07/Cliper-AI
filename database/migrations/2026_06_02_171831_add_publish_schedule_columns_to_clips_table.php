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
        Schema::table('clips', function (Blueprint $table) {
            $table->string('youtube_publish_status')->nullable()->after('youtube_privacy_status'); // immediate_public, scheduled_public
            $table->timestamp('youtube_publish_at')->nullable()->after('youtube_publish_status');
            $table->string('youtube_publish_timezone')->nullable()->after('youtube_publish_at');
            $table->string('youtube_scheduled_for_local')->nullable()->after('youtube_publish_timezone');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('clips', function (Blueprint $table) {
            $table->dropColumn([
                'youtube_publish_status',
                'youtube_publish_at',
                'youtube_publish_timezone',
                'youtube_scheduled_for_local',
            ]);
        });
    }
};
