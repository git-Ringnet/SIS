<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Course;
use App\Models\Student;
use App\Models\User;
use App\Support\PagePermissions;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ParentPortalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local'); // không đọc / ghi file dữ liệu thật (buổi học lẻ, cài đặt...)
    }

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
            'remaining_sessions' => 10, // 2 buổi có mặt bị trừ, buổi vắng có phép không trừ
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
                'remaining_sessions' => 10,
                'total_absences' => 1,
                'absent_with_leave' => 1,
            ],
        ]);

        $sessions = $response->json('sessions');
        $this->assertCount(13, $sessions);
        $this->assertEquals('present', $sessions[0]['status']);
        $this->assertEquals('absent_with_leave', $sessions[2]['status']);
        $this->assertEquals('upcoming', $sessions[3]['status']);
        // Chỉ buổi chính thức được đánh số; buổi nghỉ có phép không có số, không trừ buổi → vẫn đủ 12 buổi (13 dòng)
        $this->assertSame([1, 2, null, 3], array_column(array_slice($sessions, 0, 4), 'session_number'));
        $this->assertSame(12, end($sessions)['session_number']);
    }

    public function test_staff_can_open_portal_view_without_phone_digits(): void
    {
        $course = Course::create(['code' => 'KH-PIANO', 'name' => 'Piano Căn Bản', 'price' => 2000000, 'default_sessions' => 12, 'is_active' => true]);
        $student = Student::create([
            'code' => 'HV-001', 'name' => 'Nguyễn Văn An', 'parent_name' => 'Nguyễn Văn Ba', 'parent_phone' => '0908123456', 'course_id' => $course->id,
            'total_sessions' => 12, 'remaining_sessions' => 9, 'status' => 'active',
        ]);

        // Không đăng nhập thì không vào được đường dành cho trung tâm
        $this->getJson("/api/students/{$student->id}/portal")->assertUnauthorized();

        $owner = User::factory()->create();
        $teacher = User::factory()->create();
        Storage::put('staff_data.json', json_encode(['invites' => [], 'members' => [
            ['id' => 1, 'email' => $owner->email, 'name' => 'Chủ', 'role' => 'Chủ trung tâm', 'blocked_pages' => [], 'is_owner' => true],
            ['id' => 2, 'email' => $teacher->email, 'name' => 'GV', 'role' => 'Giáo viên',
                'blocked_pages' => PagePermissions::defaultBlockedPages('Giáo viên'), 'is_owner' => false],
        ]]));

        $parentView = $this->postJson("/api/portal/students/{$student->id}/verify", ['phone_last4' => '3456'])->assertOk()->json();

        // Giáo viên (mặc định chỉ mở Điểm danh / Check-in) vẫn xem được, dữ liệu giống hệt phụ huynh thấy
        Sanctum::actingAs($teacher);
        $staffView = $this->getJson("/api/students/{$student->id}/portal")->assertOk()->json();

        $this->assertSame($parentView, $staffView);
        $this->assertSame('Nguyễn Văn An', $staffView['student']['name']);
    }
}
