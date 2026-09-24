<?php

namespace App\Http\Controllers;

use App\Models\Course;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CourseController extends Controller
{
    public function index(): Response
    {
        $courses = Course::withCount([
            'students',
            'activeStudents',
        ])->get();

        return Inertia::render('Courses/Index', [
            'courses' => $courses,
        ]);
    }

    public function store(Request $request): RedirectResponse
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

        Course::create($validated);

        return redirect()->back()->with('success', 'Thêm khóa học mới thành công!');
    }

    public function update(Request $request, Course $course): RedirectResponse
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

        return redirect()->back()->with('success', 'Cập nhật thông tin khóa học thành công!');
    }

    public function destroy(Course $course): RedirectResponse
    {
        if ($course->students()->count() > 0) {
            return redirect()->back()->with('error', 'Không thể xóa khóa học đã có học viên đăng ký!');
        }

        $course->delete();

        return redirect()->back()->with('success', 'Đã xóa khóa học thành công!');
    }
}
