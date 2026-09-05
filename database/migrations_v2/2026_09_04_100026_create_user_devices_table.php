<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_devices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete()->cascadeOnUpdate();
            $table->string('device_id', 191)->nullable();
            $table->string('device_name', 100)->nullable();
            $table->enum('platform', ['ios', 'android', 'web'])->default('android');
            $table->string('app_version', 30)->nullable();
            $table->string('fcm_token', 500)->unique();
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_active_at')->nullable();
            $table->timestamps();

            $table->index('user_id');
            $table->index(['user_id', 'device_id'], 'user_devices_user_id_device_id_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_devices');
    }
};
