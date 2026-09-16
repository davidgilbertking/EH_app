<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lighting_states', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
            $table->unsignedBigInteger('owner_user_id')->nullable();
            $table->string('owner_session_hash', 64)->nullable();
            $table->string('epoch_hash', 64)->nullable();
            $table->uuid('control_generation')->nullable();
            $table->unsignedBigInteger('control_expires_ms')->nullable();
            $table->boolean('enabled')->default(false);
            $table->unsignedBigInteger('client_seq')->default(0);
            $table->unsignedBigInteger('revision')->default(0);
            $table->unsignedBigInteger('applied_revision')->nullable();
            $table->unsignedBigInteger('status_version')->default(0);
            $table->uuid('intent_id')->nullable();
            $table->json('desired_target')->nullable();
            $table->string('stage')->default('idle');
            $table->boolean('color_barrier')->default(false);
            $table->json('observed')->nullable();
            $table->json('transition')->nullable();
            $table->unsignedBigInteger('read_revision')->nullable();
            $table->unsignedBigInteger('dark_since_ms')->nullable();
            $table->unsignedBigInteger('worker_seen_ms')->nullable();
            $table->string('error')->nullable();
        });
        DB::table('lighting_states')->insert(['id' => 1]);

        Schema::create('lighting_mock_devices', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
            $table->json('state');
        });
        DB::table('lighting_mock_devices')->insert(['id' => 1, 'state' => json_encode([
            'mode' => 'white', 'brightnessPct' => 100.0, 'temperaturePct' => 33.0, 'rgbSuppressed' => true,
        ], JSON_THROW_ON_ERROR)]);

        Schema::create('lighting_intents', function (Blueprint $table) {
            $table->id();
            $table->uuid('intent_id')->unique();
            $table->uuid('control_generation');
            $table->unsignedBigInteger('client_seq');
            $table->unsignedBigInteger('revision');
            $table->string('fingerprint', 64);
        });
        Schema::create('lighting_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('timestamp_ms');
            $table->uuid('intent_id')->nullable();
            $table->unsignedBigInteger('revision');
            $table->string('stage');
            $table->string('operation');
            $table->json('target')->nullable();
            $table->boolean('simulated');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lighting_events');
        Schema::dropIfExists('lighting_intents');
        Schema::dropIfExists('lighting_mock_devices');
        Schema::dropIfExists('lighting_states');
    }
};
