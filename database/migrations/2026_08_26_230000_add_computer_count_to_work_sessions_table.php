<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_sessions', function (Blueprint $table) {
            $table->unsignedInteger('computer_count')->default(0)->after('site_count');
        });
    }

    public function down(): void
    {
        Schema::table('work_sessions', function (Blueprint $table) {
            $table->dropColumn('computer_count');
        });
    }
};
