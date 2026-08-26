<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->foreignId('keyword_id')->constrained()->cascadeOnDelete();
            $table->date('work_date');
            $table->unsignedInteger('target_clicks')->nullable();
            $table->foreignId('scheduled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('is_auto_copied')->default(false);
            $table->timestamps();

            $table->unique(['employee_id', 'site_id', 'keyword_id', 'work_date'], 'work_assignments_unique');
            $table->index(['work_date', 'employee_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_assignments');
    }
};
