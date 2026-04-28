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
        Schema::table('investigators', function (Blueprint $table) {
            $table->char('gender', 1)->nullable()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('investigators', function (Blueprint $table) {
            $table->dropColumn('gender');
        });
    }
};
