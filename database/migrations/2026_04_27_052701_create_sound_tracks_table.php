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
        Schema::create('sound_tracks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sound_folder_id')->constrained()->cascadeOnDelete();
            $table->string('file_path');
            $table->float('duration_seconds')->nullable();
            $table->timestamps();
            $table->unique(['sound_folder_id', 'file_path']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sound_tracks');
    }
};
