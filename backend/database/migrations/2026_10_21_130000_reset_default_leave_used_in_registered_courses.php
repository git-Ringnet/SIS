<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * students.absences_used trước đây mặc định 1, và khi sửa khóa đã đăng ký giá trị này bị chép vào
     * registered_courses[*].absences_used → học viên chưa nghỉ buổi nào vẫn hiện "Đã vắng có phép 1/1".
     * Học viên chưa có buổi nghỉ có phép nào: số buổi đã nghỉ có phép của học viên và mọi khóa về 0.
     */
    public function up(): void
    {
        DB::table('students')
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('attendances')
                ->whereColumn('attendances.student_id', 'students.id')
                ->where('attendances.status', 'absent_with_leave'))
            ->orderBy('id')
            ->each(function ($student) {
                $list = json_decode($student->registered_courses ?? 'null', true);
                $changed = (int) $student->absences_used !== 0;

                if (is_array($list)) {
                    foreach ($list as $key => $item) {
                        if ((int) ($item['absences_used'] ?? 0) !== 0) {
                            $list[$key]['absences_used'] = 0;
                            $changed = true;
                        }
                    }
                }

                if ($changed) {
                    DB::table('students')->where('id', $student->id)->update([
                        'absences_used' => 0,
                        'registered_courses' => is_array($list) ? json_encode($list, JSON_UNESCAPED_UNICODE) : $student->registered_courses,
                    ]);
                }
            });
    }

    public function down(): void
    {
        // Không hoàn tác: số 1 cũ là giá trị mặc định sai, không phải số buổi đã nghỉ thật
    }
};
