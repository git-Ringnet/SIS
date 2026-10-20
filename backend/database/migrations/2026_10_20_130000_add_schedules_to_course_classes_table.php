<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Khung giờ của lớp học: [{day: 0-6 (0 = CN), start: "HH:MM", end: "HH:MM"|null, teacher: string|null}].
     */
    public function up(): void
    {
        Schema::table('course_classes', function (Blueprint $table) {
            $table->json('schedules')->nullable()->after('capacity');
        });
    }

    public function down(): void
    {
        Schema::table('course_classes', function (Blueprint $table) {
            $table->dropColumn('schedules');
        });
    }
};
