<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\CourseClass;
use App\Models\Student;
use App\Support\CenterStore;
use App\Support\TeacherName;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CourseApiController extends Controller
{
    public function index(): JsonResponse
    {
        $courses = Course::with('classes')->withCount([
            'students',
            'activeStudents',
        ])->get();

        return response()->json([
            'courses' => $courses,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'default_sessions' => 'required|integer|min:1',
            // Số buổi được nghỉ có phép của khóa (trống = mặc định 3 buổi)
            'allowed_absences' => 'nullable|integer|min:0|max:100',
            'price' => 'required|numeric|min:0',
            'description' => 'nullable|string',
        ]);

        $course = Course::create([...$validated, 'code' => Course::nextCode(), 'is_active' => true]);

        return response()->json([
            'message' => 'Thêm khóa học mới thành công!',
            'course' => $course,
        ], 201);
    }

    public function update(Request $request, Course $course): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'default_sessions' => 'required|integer|min:1',
            // Số buổi được nghỉ có phép của khóa (trống = mặc định 3 buổi)
            'allowed_absences' => 'nullable|integer|min:0|max:100',
            'price' => 'required|numeric|min:0',
            'description' => 'nullable|string',
        ]);

        $course->update($validated);

        return response()->json([
            'message' => 'Cập nhật thông tin khóa học thành công!',
            'course' => $course,
        ]);
    }

    public function destroy(Course $course): JsonResponse
    {
        if ($course->students()->count() > 0) {
            return response()->json([
                'error' => 'Không thể xóa khóa học đã có học viên đăng ký!',
            ], 422);
        }

        $course->delete();

        return response()->json([
            'message' => 'Đã xóa khóa học thành công!',
        ]);
    }

    // ------------------------------------------------------------------ Lớp học trong khóa

    /**
     * Phòng học chọn được cho lớp: "Phòng · Cơ sở", kèm sức chứa để gợi ý sĩ số.
     */
    public function classRooms(): JsonResponse
    {
        $rooms = [];
        foreach (CenterStore::all() as $c) {
            foreach ($c['rooms'] ?? [] as $r) {
                $rooms[] = ['center' => $c['name'], 'room' => $r['name'], 'capacity' => (int) ($r['capacity'] ?? 0)];
            }
        }

        return response()->json(['rooms' => $rooms, 'teachers' => TeacherName::all()]);
    }

    private function classRules(?CourseClass $class = null): array
    {
        return [
            'name' => 'required|string|max:100|unique:course_classes,name'.($class ? ','.$class->id : ''),
            'center' => 'nullable|string|max:255',
            'room' => 'nullable|string|max:100',
            'capacity' => 'nullable|integer|min:1|max:1000',
            // Khung giờ của lớp: thứ (0 = CN) + giờ bắt đầu / kết thúc + giáo viên
            'schedules' => 'nullable|array|max:14',
            'schedules.*.day' => 'required|integer|between:0,6',
            'schedules.*.start' => 'required|date_format:H:i',
            'schedules.*.end' => 'nullable|date_format:H:i',
            'schedules.*.teacher' => 'nullable|string|max:255',
        ];
    }

    private const CLASS_MESSAGES = [
        'name.required' => 'Vui lòng nhập tên lớp học',
        'name.unique' => 'Tên lớp này đã có, hãy đặt tên khác (ví dụ thêm tên khóa vào sau).',
        'schedules.*.day.required' => 'Vui lòng chọn thứ cho khung giờ',
        'schedules.*.start.required' => 'Vui lòng chọn giờ cho khung giờ',
    ];

    /**
     * Sĩ số mặc định = sức chứa của phòng nếu không nhập.
     */
    private function withDefaultCapacity(array $data): array
    {
        if (empty($data['capacity']) && ! empty($data['room'])) {
            foreach (CenterStore::all() as $c) {
                if (($data['center'] ?? null) && $c['name'] !== $data['center']) {
                    continue;
                }
                foreach ($c['rooms'] ?? [] as $r) {
                    if ($r['name'] === $data['room']) {
                        $data['capacity'] = (int) ($r['capacity'] ?? 0) ?: null;
                        break 2;
                    }
                }
            }
        }

        return $data;
    }

    public function storeClass(Request $request, Course $course): JsonResponse
    {
        $validated = $request->validate($this->classRules(), self::CLASS_MESSAGES);
        $class = $course->classes()->create($this->withDefaultCapacity($validated));

        return response()->json(['message' => "Đã tạo lớp {$class->name}.", 'class' => $class->fresh()], 201);
    }

    public function updateClass(Request $request, CourseClass $courseClass): JsonResponse
    {
        $validated = $request->validate($this->classRules($courseClass), self::CLASS_MESSAGES);
        foreach ($validated['schedules'] ?? [] as $i => $slot) {
            if (! empty($slot['end']) && $slot['end'] <= $slot['start']) {
                return response()->json(['message' => 'Giờ kết thúc phải sau giờ bắt đầu.', 'errors' => ["schedules.{$i}.end" => ['Giờ kết thúc phải sau giờ bắt đầu.']]], 422);
            }
        }
        $oldName = $courseClass->name;
        $courseClass->update($this->withDefaultCapacity($validated));

        // Đổi tên lớp thì cập nhật luôn học viên đang gán vào lớp
        if ($oldName !== $courseClass->name) {
            Student::where('assigned_class', $oldName)->update(['assigned_class' => $courseClass->name]);
        }

        // Đổi khung giờ / phòng: học viên trong lớp nhận lịch mới
        $message = 'Đã cập nhật lớp học.';
        if ($courseClass->hasSlots()) {
            $synced = $courseClass->syncStudents();
            $message = $synced === null
                ? 'Đã lưu lớp. Các khung giờ khác giờ hoặc khác giáo viên nên chưa tự áp vào lịch học viên, hãy xếp lịch học viên tay.'
                : "Đã lưu lớp và cập nhật lịch cho {$synced} học viên trong lớp.";
        }

        return response()->json(['message' => $message, 'class' => $courseClass->fresh()]);
    }

    public function destroyClass(CourseClass $courseClass): JsonResponse
    {
        // Học viên trong lớp chuyển về "chưa gán lớp"
        Student::where('assigned_class', $courseClass->name)->update(['assigned_class' => null]);
        $courseClass->delete();

        return response()->json(['message' => 'Đã xoá lớp học.']);
    }
}
