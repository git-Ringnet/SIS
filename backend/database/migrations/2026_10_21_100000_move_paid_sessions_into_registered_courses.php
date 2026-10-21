<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Thu học phí trước đây chỉ cộng buổi vào tổng của học viên (students.total_sessions / remaining_sessions)
     * mà không cộng vào khóa đã đăng ký, nên lần điểm danh sau (tổng tính lại từ các khóa) làm mất số buổi đã đóng tiền.
     * Học viên có tổng lớn hơn tổng các khóa: cộng phần chênh vào khóa đang học (khóa trùng course_id, không có thì khóa cuối).
     */
    public function up(): void
    {
        DB::table('students')->whereNotNull('registered_courses')->orderBy('id')->each(function ($student) {
            $list = json_decode($student->registered_courses, true);
            if (! is_array($list) || $list === []) {
                return;
            }

            $missing = (int) $student->total_sessions - array_sum(array_map(fn ($i) => (int) ($i['total_sessions'] ?? 0), $list));
            if ($missing <= 0) {
                return;
            }

            $keys = array_keys($list);
            $key = collect($keys)->last(fn ($k) => (int) ($list[$k]['course_id'] ?? 0) === (int) $student->course_id) ?? end($keys);
            $attended = (int) ($list[$key]['attended_sessions'] ?? 0);
            $list[$key]['total_sessions'] = (int) $list[$key]['total_sessions'] + $missing;
            $list[$key]['remaining_sessions'] = max(0, $list[$key]['total_sessions'] - $attended);
            $list[$key]['status'] = $list[$key]['remaining_sessions'] > 0 ? 'active' : 'completed';

            DB::table('students')->where('id', $student->id)->update([
                'registered_courses' => json_encode($list, JSON_UNESCAPED_UNICODE),
                'total_sessions' => array_sum(array_column($list, 'total_sessions')),
                'remaining_sessions' => array_sum(array_column($list, 'remaining_sessions')),
            ]);
        });
    }

    public function down(): void
    {
        // Không hoàn tác: số buổi đã cộng vào khóa là số buổi học viên đã đóng tiền
    }
};
