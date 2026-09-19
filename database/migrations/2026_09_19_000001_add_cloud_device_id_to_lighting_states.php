<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lighting_states', function (Blueprint $table) {
            $table->string('cloud_device_id', 128)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('lighting_states', function (Blueprint $table) {
            $table->dropColumn('cloud_device_id');
        });
    }
};
