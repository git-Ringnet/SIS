<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Lịch học nhiều ca: mỗi học viên có danh sách ca (thứ + giờ + giáo viên + phòng).
     * Điểm danh ghi theo từng ca và trừ buổi vào đúng khoá học.
     */
    public function up(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->json('schedules')->nullable()->after('class_days');
        });

        Schema::table('attendances', function (Blueprint $table) {
            $table->string('slot_id', 40)->nullable()->after('course_id');
            $table->unsignedInteger('course_item_id')->nullable()->after('slot_id');
        });

        // Chuyển lịch cũ (các thứ + một khung giờ) thành danh sách ca, mỗi thứ một ca
        DB::table('students')->whereNotNull('class_days')->whereNotNull('class_start_time')->orderBy('id')
            ->each(function ($row) {
                $days = json_decode((string) $row->class_days, true) ?: [];
                $slots = array_map(fn ($d) => [
                    'id' => 'd'.(int) $d,
                    'day' => (int) $d,
                    'start' => $row->class_start_time,
                    'end' => $row->class_end_time,
                    'teacher' => $row->teacher ?: null,
                    'room' => $row->room ?: null,
                ], $days);
                usort($slots, fn ($a, $b) => ($a['day'] === 0 ? 7 : $a['day']) <=> ($b['day'] === 0 ? 7 : $b['day']));

                DB::table('students')->where('id', $row->id)->update(['schedules' => json_encode($slots, JSON_UNESCAPED_UNICODE)]);
            });
    }

    public function down(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            $table->dropColumn(['slot_id', 'course_item_id']);
        });

        Schema::table('students', function (Blueprint $table) {
            $table->dropColumn('schedules');
        });
    }
};
