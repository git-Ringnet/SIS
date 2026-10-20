<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StudentCourseItemEditTest extends TestCase
{
    use RefreshDatabase;

    public function test_change_course_and_dates_of_a_course_item(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $guitar = Course::create(['code' => 'G', 'name' => 'Học guitar', 'price' => 1, 'default_sessions' => 12, 'is_active' => true]);
        $piano = Course::create(['code' => 'P', 'name' => 'Piano', 'price' => 1, 'default_sessions' => 12, 'is_active' => true]);
        $student = Student::create([
            'code' => 'HV-1', 'name' => 'Hoàng', 'gender' => 'Nam', 'parent_name' => 'Phi', 'parent_phone' => '09',
            'course_id' => $guitar->id, 'total_sessions' => 12, 'remaining_sessions' => 3, 'status' => 'active',
        ]);
        $itemId = $student->registered_courses_list[0]['id'];

        // Đổi khóa: giữ nguyên số buổi, khóa chính của học viên đổi theo
        $this->putJson("/api/students/{$student->id}/courses/{$itemId}", ['course_id' => $piano->id])
            ->assertOk()
            ->assertJsonPath('student.registered_courses.0.course_name', 'Piano')
            ->assertJsonPath('student.registered_courses.0.total_sessions', 12)
            ->assertJsonPath('student.course_id', $piano->id);

        // Thời gian khóa học
        $this->putJson("/api/students/{$student->id}/courses/{$itemId}", [
            'start_date' => '2026-10-18', 'sessions_per_week' => 2, 'end_date' => '2026-11-23',
        ])->assertOk()
            ->assertJsonPath('student.registered_courses.0.start_date', '2026-10-18')
            ->assertJsonPath('student.registered_courses.0.end_date', '2026-11-23')
            ->assertJsonPath('student.registered_courses.0.schedule_info', '2 buổi/tuần');

        $this->putJson("/api/students/{$student->id}/courses/{$itemId}", ['start_date' => '2026-10-18', 'end_date' => '2026-10-01'])
            ->assertUnprocessable();
    }
}
