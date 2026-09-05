<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('otp_codes', function (Blueprint $table) {
            $table->id();
            $table->string('email', 100);
            $table->string('code_hash', 255);
            $table->string('purpose', 50)->comment('REGISTER, LOGIN, RESET_PASSWORD, VERIFY_EMAIL, CHANGE_EMAIL');
            $table->timestamp('expires_at');
            $table->boolean('is_used')->default(false);
            $table->tinyInteger('attempts')->default(0);
            $table->timestamps();

            $table->index('email');
            $table->index('expires_at');
            $table->index(['email', 'purpose', 'is_used']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('otp_codes');
    }
};
