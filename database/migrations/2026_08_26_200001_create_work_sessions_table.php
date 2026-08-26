<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedInteger('duration_seconds');
            $table->timestamp('started_at');
            $table->timestamp('ends_at');
            $table->timestamp('finished_at')->nullable();
            $table->string('status')->default('running');
            $table->unsignedInteger('site_count')->default(0);
            $table->unsignedInteger('clicks_awarded')->default(0);
            $table->json('sites')->nullable();
            $table->timestamps();

            $table->index(['employee_id', 'status']);
            $table->index(['employee_id', 'started_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_sessions');
    }
};
