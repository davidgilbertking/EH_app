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
        Schema::table('ancient_ones', function (Blueprint $table) {
            // High-resolution upscale used as the Home-screen background art.
            // The existing image_path column keeps pointing at the small
            // thumbnail used by the ancient-picker grid.
            $table->string('bg_image_path')->nullable()->after('image_path');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ancient_ones', function (Blueprint $table) {
            $table->dropColumn('bg_image_path');
        });
    }
};
