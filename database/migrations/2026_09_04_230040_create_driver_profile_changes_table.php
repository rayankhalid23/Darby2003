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
        Schema::create('driver_profile_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('driver_id')->constrained('drivers')->onDelete('cascade')->onUpdate('cascade');
            $table->json('old_values')->nullable();
            $table->json('new_values');
            $table->enum('status', ['Pending', 'Approved', 'Rejected'])->default('Pending');
            $table->string('rejection_reason', 500)->nullable();
            $table->foreignId('action_by')->nullable()->constrained('users')->onDelete('set null');
            $table->timestamps();
            $table->timestamp('action_at')->nullable();

            $table->index('driver_id');
            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('driver_profile_changes');
    }
};
