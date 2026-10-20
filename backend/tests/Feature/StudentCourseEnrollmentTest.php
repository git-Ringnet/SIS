<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StudentCourseEnrollmentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Sanctum::actingAs(User::factory()->create());
    }

    public function test_can_add_course_for_student(): void
    {
        $course1 = Course::create(['code' => 'KH-001', 'name' => 'Guitar Cơ Bản', 'price' => 3000000, 'is_active' => true]);
        $course2 = Course::create(['code' => 'KH-002', 'name' => 'Piano Nâng Cao', 'price' => 4500000, 'is_active' => true]);

        $student = Student::create([
            'code' => 'HV-001',
            'name' => 'Nguyễn Văn A',
            'gender' => 'Nam',
            'parent_name' => 'Nguyễn Văn B',
            'parent_phone' => '0901234567',
            'course_id' => $course1->id,
            'total_sessions' => 12,
            'remaining_sessions' => 12,
            'status' => 'active',
        ]);

        $response = $this->postJson("/api/students/{$student->id}/courses", [
            'course_id' => $course2->id,
            'total_sessions' => 16,
            'start_date' => '2026-10-20',
            'end_date' => '2026-12-20',
            'sessions_per_week' => '2',
        ]);

        $response->assertStatus(200);
        $student->refresh();

        $this->assertEquals(28, $student->total_sessions);
        $this->assertEquals(28, $student->remaining_sessions);
        $this->assertCount(2, $student->registered_courses_list);
        $this->assertEquals('Piano Nâng Cao', $student->registered_courses_list[1]['course_name']);
    }

    public function test_can_update_course_item(): void
    {
        $course = Course::create(['code' => 'KH-003', 'name' => 'Trống Jazz', 'price' => 3600000, 'is_active' => true]);
        $student = Student::create([
            'code' => 'HV-002',
            'name' => 'Trần Thị C',
            'gender' => 'Nữ',
            'parent_name' => 'Trần Văn D',
            'parent_phone' => '0909999888',
            'course_id' => $course->id,
            'total_sessions' => 12,
            'remaining_sessions' => 12,
            'status' => 'active',
        ]);

        $response = $this->putJson("/api/students/{$student->id}/courses/1", [
            'attended_sessions' => 4,
            'total_sessions' => 12,
        ]);

        $response->assertStatus(200);
        $student->refresh();
        $this->assertEquals(8, $student->remaining_sessions);
        $this->assertEquals(4, $student->registered_courses_list[0]['attended_sessions']);
    }

    public function test_can_delete_course_item_when_multiple_exist(): void
    {
        $course1 = Course::create(['code' => 'KH-004', 'name' => 'Guitar', 'price' => 3000000, 'is_active' => true]);
        $course2 = Course::create(['code' => 'KH-005', 'name' => 'Vocal', 'price' => 3200000, 'is_active' => true]);

        $student = Student::create([
            'code' => 'HV-003',
            'name' => 'Lê Minh E',
            'gender' => 'Nam',
            'parent_name' => 'Lê Văn F',
            'parent_phone' => '0912345678',
            'course_id' => $course1->id,
            'total_sessions' => 12,
            'remaining_sessions' => 12,
            'status' => 'active',
        ]);

        // Add 2nd course
        $this->postJson("/api/students/{$student->id}/courses", [
            'course_id' => $course2->id,
            'total_sessions' => 10,
        ])->assertStatus(200);

        $student->refresh();
        $this->assertCount(2, $student->registered_courses_list);

        // Delete 2nd course
        $response = $this->deleteJson("/api/students/{$student->id}/courses/2");
        $response->assertStatus(200);

        $student->refresh();
        $this->assertCount(1, $student->registered_courses_list);
        $this->assertEquals(12, $student->total_sessions);
    }
}
