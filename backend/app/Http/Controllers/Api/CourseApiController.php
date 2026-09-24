<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Course;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CourseApiController extends Controller
{
    public function index(): JsonResponse
    {
        $courses = Course::withCount([
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
            'code' => 'required|string|max:50|unique:courses,code',
            'category' => 'required|string|max:50',
            'default_sessions' => 'required|integer|min:1',
            'price' => 'required|numeric|min:0',
            'color' => 'required|string|max:20',
            'description' => 'nullable|string',
        ]);

        $course = Course::create($validated);

        return response()->json([
            'message' => 'Thêm khóa học mới thành công!',
            'course' => $course,
        ], 201);
    }

    public function update(Request $request, Course $course): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'code' => 'required|string|max:50|unique:courses,code,'.$course->id,
            'category' => 'required|string|max:50',
            'default_sessions' => 'required|integer|min:1',
            'price' => 'required|numeric|min:0',
            'color' => 'required|string|max:20',
            'description' => 'nullable|string',
            'is_active' => 'required|boolean',
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
}
