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
            $table->string('youtube_upload_status')->default('not_uploaded')->after('status');
            $table->string('youtube_video_id')->nullable()->after('youtube_upload_status');
            $table->string('youtube_url')->nullable()->after('youtube_video_id');
            $table->string('youtube_title')->nullable()->after('youtube_url');
            $table->text('youtube_description')->nullable()->after('youtube_title');
            $table->json('youtube_tags')->nullable()->after('youtube_description');
            $table->string('youtube_privacy_status')->nullable()->after('youtube_tags');
            $table->text('youtube_error_message')->nullable()->after('youtube_privacy_status');
            $table->timestamp('youtube_uploaded_at')->nullable()->after('youtube_error_message');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('clips', function (Blueprint $table) {
            $table->dropColumn([
                'youtube_upload_status',
                'youtube_video_id',
                'youtube_url',
                'youtube_title',
                'youtube_description',
                'youtube_tags',
                'youtube_privacy_status',
                'youtube_error_message',
                'youtube_uploaded_at',
            ]);
        });
    }
};
