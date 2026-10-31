<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Student;
use App\Support\CenterStore;
use App\Support\MessageTemplateDefaults;
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
            $giftYears = array_map('intval', (array) ($s->birthday_gift_years ?? []));
            $isGifted = in_array((int) $year, $giftYears, true);

            return [
                'id' => $s->id,
                'code' => $s->code,
                'name' => $s->name,
                'dob' => $s->dob ? $dob->format('Y-m-d') : null,
                'dob_formatted' => $dob->format('d/m/Y'),
                'birthday_date' => $dob->format('d-m'),
                'day' => $dob->day,
                'month' => $dob->month,
                'age' => $age > 0 ? $age : 1,
                'is_gifted' => $isGifted,
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

        $templates = MessageTemplateDefaults::forGroup('birthday');

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

    public function toggleGift(Request $request, Student $student): JsonResponse
    {
        $year = (int) $request->input('year', now()->year);
        $giftYears = array_values(array_unique(array_map('intval', (array) ($student->birthday_gift_years ?? []))));

        if (in_array($year, $giftYears, true)) {
            $giftYears = array_values(array_filter($giftYears, fn ($y) => $y !== $year));
            $isGifted = false;
        } else {
            $giftYears[] = $year;
            $isGifted = true;
        }

        $student->birthday_gift_years = $giftYears;
        $student->save();

        return response()->json([
            'success' => true,
            'student_id' => $student->id,
            'year' => $year,
            'is_gifted' => $isGifted,
            'birthday_gift_years' => $giftYears,
            'message' => $isGifted ? 'Đã tặng quà cho học viên!' : 'Đã hủy đánh dấu tặng quà',
        ]);
    }
}
