<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Course;
use App\Models\Student;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ParentPortalTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_view_public_portal_info_without_auth(): void
    {
        $course = Course::create([
            'code' => 'KH-PIANO',
            'name' => 'Piano Căn Bản',
            'price' => 2000000,
            'default_sessions' => 12,
            'is_active' => true,
        ]);

        $student = Student::create([
            'code' => 'HV-001',
            'name' => 'Nguyễn Văn An',
            'parent_name' => 'Nguyễn Văn Ba',
            'parent_phone' => '0908123456',
            'course_id' => $course->id,
            'total_sessions' => 12,
            'remaining_sessions' => 9,
            'status' => 'active',
        ]);

        $response = $this->getJson("/api/portal/students/{$student->id}/info");

        $response->assertStatus(200);
        $response->assertJson([
            'student_id' => $student->id,
            'student_name' => 'Nguyễn Văn An',
            'student_code' => 'HV-001',
            'course_name' => 'Piano Căn Bản',
            'phone_hint' => '•••56',
        ]);
    }

    public function test_rejects_verification_with_wrong_4_digits(): void
    {
        $course = Course::create([
            'code' => 'KH-PIANO',
            'name' => 'Piano Căn Bản',
            'price' => 2000000,
            'default_sessions' => 12,
            'is_active' => true,
        ]);

        $student = Student::create([
            'code' => 'HV-001',
            'name' => 'Nguyễn Văn An',
            'parent_name' => 'Nguyễn Văn Ba',
            'parent_phone' => '0908123456',
            'course_id' => $course->id,
            'total_sessions' => 12,
            'remaining_sessions' => 9,
            'status' => 'active',
        ]);

        $response = $this->postJson("/api/portal/students/{$student->id}/verify", [
            'phone_last4' => '9999',
        ]);

        $response->assertStatus(422);
        $response->assertJsonStructure(['message']);
    }

    public function test_succeeds_verification_with_correct_4_digits_and_returns_sessions_breakdown(): void
    {
        $course = Course::create([
            'code' => 'KH-PIANO',
            'name' => 'Piano Căn Bản',
            'price' => 2000000,
            'default_sessions' => 12,
            'is_active' => true,
        ]);

        $student = Student::create([
            'code' => 'HV-001',
            'name' => 'Nguyễn Văn An',
            'parent_name' => 'Nguyễn Văn Ba',
            'parent_phone' => '0908123456',
            'course_id' => $course->id,
            'total_sessions' => 12,
            'remaining_sessions' => 9,
            'status' => 'active',
        ]);

        // Tạo 3 buổi điểm danh
        Attendance::create([
            'student_id' => $student->id,
            'course_id' => $course->id,
            'session_number' => 1,
            'attended_at' => Carbon::now()->subDays(6),
            'status' => 'present',
            'note' => 'Học tốt, thuộc nốt nhạc',
            'marked_by' => 'Cô Lan',
        ]);

        Attendance::create([
            'student_id' => $student->id,
            'course_id' => $course->id,
            'session_number' => 2,
            'attended_at' => Carbon::now()->subDays(3),
            'status' => 'present',
            'note' => 'Đánh đúng nhịp 2/4',
            'marked_by' => 'Cô Lan',
        ]);

        Attendance::create([
            'student_id' => $student->id,
            'course_id' => $course->id,
            'session_number' => 3,
            'attended_at' => Carbon::now()->subDay(),
            'status' => 'absent_with_leave',
            'note' => 'Bé bị ốm nhẹ có phép',
            'marked_by' => 'Admin',
        ]);

        $response = $this->postJson("/api/portal/students/{$student->id}/verify", [
            'phone_last4' => '3456',
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'verified' => true,
            'stats' => [
                'total_sessions' => 12,
                'remaining_sessions' => 9,
                'total_absences' => 1,
                'absent_with_leave' => 1,
            ],
        ]);

        $sessions = $response->json('sessions');
        $this->assertCount(12, $sessions);
        $this->assertEquals('present', $sessions[0]['status']);
        $this->assertEquals('absent_with_leave', $sessions[2]['status']);
        $this->assertEquals('upcoming', $sessions[3]['status']);
    }

    public function test_parent_can_submit_leave_request(): void
    {
        $course = Course::create([
            'code' => 'KH-PIANO',
            'name' => 'Piano Căn Bản',
            'price' => 2000000,
            'default_sessions' => 12,
            'is_active' => true,
        ]);

        $student = Student::create([
            'code' => 'HV-001',
            'name' => 'Nguyễn Văn An',
            'parent_name' => 'Nguyễn Văn Ba',
            'parent_phone' => '0908123456',
            'course_id' => $course->id,
            'total_sessions' => 12,
            'remaining_sessions' => 9,
            'status' => 'active',
        ]);

        $response = $this->postJson("/api/portal/students/{$student->id}/leave-request", [
            'phone_last4' => '3456',
            'date' => Carbon::tomorrow()->format('Y-m-d'),
            'reason' => 'Gia đình có việc bận về quê',
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('attendances', [
            'student_id' => $student->id,
            'status' => 'absent_with_leave',
        ]);
    }
}
