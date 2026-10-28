<?php

use App\Models\Student;
use App\Support\StudentSchedule;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Log;

return new class extends Migration
{
    /**
     * Bản ghi điểm danh trỏ vào ca không đúng thứ của ngày điểm danh, do:
     *  - sửa lịch học đổi thứ của ca mà vẫn giữ mã ca → gắn lại vào ca của đúng thứ ngày đó;
     *  - bấm điểm danh khi trang Điểm danh còn hiện danh sách của ngày khác (vd ngày 20/10 Thứ 3 bị ghi ca Thứ 5),
     *    ngày đó ca đúng đã được điểm danh riêng → bản ghi thừa, xoá và trả lại buổi đã trừ cho học viên.
     * Danh sách bản ghi đã xoá ghi vào storage/logs/laravel.log để đối chiếu.
     */
    public function up(): void
    {
        Student::query()->each(function (Student $student) {
            $result = StudentSchedule::relinkAttendanceSlots($student);
            if ($result['removed'] !== []) {
                Log::warning("Xoá điểm danh trùng của học viên {$student->code} {$student->name}: #".implode(', #', $result['removed']));
            }
        });
    }

    public function down(): void
    {
        // Không khôi phục liên kết sai
    }
};
