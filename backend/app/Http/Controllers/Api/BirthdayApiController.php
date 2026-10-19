<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MessageTemplate;
use App\Models\Student;
use App\Support\CenterStore;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BirthdayApiController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $month = (int) $request->input('month', Carbon::now()->month);
        $year = (int) $request->input('year', now()->year);
        $center = $request->input('center', 'all');

        $query = Student::with('course')->whereNotNull('dob');

        if ($center !== 'all') {
            $query->where('center', $center);
        }

        // Lọc tháng & sắp xếp theo ngày bằng PHP để chạy được trên cả SQLite lẫn MySQL
        $students = $query->get()
            ->filter(fn ($s) => $month <= 0 || Carbon::parse($s->dob)->month === $month)
            ->sortBy(fn ($s) => Carbon::parse($s->dob)->day)
            ->values();

        $studentsFormatted = $students->map(function ($s) use ($year) {
            $dob = Carbon::parse($s->dob);
            $age = $year - $dob->year;

            return [
                'id' => $s->id,
                'code' => $s->code,
                'name' => $s->name,
                'dob' => $s->dob ? $dob->format('Y-m-d') : null,
                'dob_formatted' => $dob->format('d/m/Y'),
                'birthday_date' => $dob->format('d/m'),
                'day' => $dob->day,
                'month' => $dob->month,
                'age' => $age > 0 ? $age : 1,
                'parent_name' => $s->parent_name,
                'parent_phone' => $s->parent_phone,
                'parent_zalo' => $s->parent_zalo,
                'course' => $s->course ? [
                    'id' => $s->course->id,
                    'name' => $s->course->name,
                ] : null,
                'center' => $s->center ?: CenterStore::defaultName(),
                'status' => $s->status,
            ];
        });

        $templates = MessageTemplate::where('category', 'birthday_greeting')->get();
        if ($templates->isEmpty()) {
            $templates = collect([
                [
                    'id' => 1,
                    'title' => 'Chúc mừng sinh nhật học viên & Tặng voucher ưu đãi',
                    'template_content' => 'Trung tâm xin gửi lời chúc mừng sinh nhật tuổi mới đến bé {ten_hoc_vien} 🎂🎉! Chúc con luôn vui vẻ, chăm ngoan và thăng hoa cùng niềm đam mê âm nhạc. Nhân dịp sinh nhật con, trung tâm xin gửi tặng bé và gia đình voucher ưu đãi 10% cho khóa học tiếp theo hoặc 1 phần quà học cụ đặc biệt. Chúc con có một ngày sinh nhật thật ấm áp bên gia đình ạ! ❤️',
                ],
                [
                    'id' => 2,
                    'title' => 'Lời chúc sinh nhật ngắn gọn qua Zalo',
                    'template_content' => 'Happy Birthday bé {ten_hoc_vien} 🎈! Thầy cô bộ môn {khoa_hoc} và trung tâm chúc con thêm một tuổi mới thật nhiều niềm vui, học giỏi và luôn yêu thích từng giờ học nhé con ơi!',
                ],
            ]);
        }

        $centers = CenterStore::names();

        return response()->json([
            'month' => $month,
            'year' => $year,
            'center' => $center,
            'students' => $studentsFormatted,
            'total' => $studentsFormatted->count(),
            'templates' => $templates,
            'centers' => $centers,
        ]);
    }
}
