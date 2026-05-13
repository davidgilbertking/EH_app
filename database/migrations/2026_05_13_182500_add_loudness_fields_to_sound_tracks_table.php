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
        Schema::table('sound_tracks', function (Blueprint $table) {
            $table->float('integrated_lufs')->nullable()->after('duration_seconds');
            $table->float('true_peak_dbtp')->nullable()->after('integrated_lufs');
            $table->float('normalization_gain_db')->nullable()->after('true_peak_dbtp');
            $table->timestamp('loudness_analyzed_at')->nullable()->after('normalization_gain_db');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sound_tracks', function (Blueprint $table) {
            $table->dropColumn([
                'integrated_lufs',
                'true_peak_dbtp',
                'normalization_gain_db',
                'loudness_analyzed_at',
            ]);
        });
    }
};

