<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('students', function (Blueprint $table) {
            // Các thứ học trong tuần theo Carbon::dayOfWeek: 0 = Chủ nhật, 1 = Thứ 2 ... 6 = Thứ 7
            $table->json('class_days')->nullable()->after('schedule_info');
            $table->string('class_start_time', 5)->nullable()->after('class_days'); // HH:MM
            $table->string('class_end_time', 5)->nullable()->after('class_start_time'); // HH:MM
            $table->string('teacher')->nullable()->after('class_end_time');
            $table->string('room')->nullable()->after('teacher');
        });
    }

    public function down(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->dropColumn(['class_days', 'class_start_time', 'class_end_time', 'teacher', 'room']);
        });
    }
};
