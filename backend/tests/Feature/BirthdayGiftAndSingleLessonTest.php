<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\CourseClass;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BirthdayGiftAndSingleLessonTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    public function test_birthday_list_and_toggle_gift(): void
    {
        $course = Course::create(['code' => 'P', 'name' => 'Piano cơ bản', 'price' => 1000000, 'default_sessions' => 12]);

        // Học viên có sinh nhật ngày 01-10
        $student = Student::create([
            'code' => 'HV-901',
            'name' => 'Hoàng',
            'dob' => '2015-10-01',
            'gender' => 'Nam',
            'parent_name' => 'Phi',
            'parent_phone' => '0901234567',
            'course_id' => $course->id,
            'total_sessions' => 12,
            'remaining_sessions' => 10,
            'status' => 'active',
            'center' => 'Cơ sở 1',
        ]);

        // 1. Kiểm tra danh sách sinh nhật tháng 10 năm 2026
        $response = $this->actingAs($this->user)->getJson('/api/birthdays?month=10&year=2026');
        $response->assertOk()
            ->assertJsonPath('students.0.id', $student->id)
            ->assertJsonPath('students.0.name', 'Hoàng')
            ->assertJsonPath('students.0.birthday_date', '01-10')
            ->assertJsonPath('students.0.is_gifted', false);

        // 2. Tặng quà sinh nhật cho học viên
        $toggleRes = $this->actingAs($this->user)->postJson("/api/birthdays/{$student->id}/toggle-gift", [
            'year' => 2026,
        ]);
        $toggleRes->assertOk()
            ->assertJsonPath('is_gifted', true);

        $student->refresh();
        $this->assertContains(2026, $student->birthday_gift_years);

        // 3. Kiểm tra lại danh sách sinh nhật -> is_gifted phải là true
        $responseAfter = $this->actingAs($this->user)->getJson('/api/birthdays?month=10&year=2026');
        $responseAfter->assertOk()
            ->assertJsonPath('students.0.is_gifted', true);

        // 4. Bấm lại để hủy đánh dấu tặng quà
        $untoggleRes = $this->actingAs($this->user)->postJson("/api/birthdays/{$student->id}/toggle-gift", [
            'year' => 2026,
        ]);
        $untoggleRes->assertOk()
            ->assertJsonPath('is_gifted', false);

        $student->refresh();
        $this->assertNotContains(2026, $student->birthday_gift_years ?? []);
    }

    public function test_available_group_slots_and_schedule_single_lesson(): void
    {
        $course = Course::create(['code' => 'G', 'name' => 'Guitar đệm hát', 'price' => 1000000, 'default_sessions' => 12]);
        $student = Student::create([
            'code' => 'HV-902',
            'name' => 'Tuấn',
            'dob' => '2016-05-15',
            'gender' => 'Nam',
            'parent_name' => 'Mẹ Tuấn',
            'parent_phone' => '0908888999',
            'course_id' => $course->id,
            'total_sessions' => 12,
            'remaining_sessions' => 12,
            'status' => 'active',
            'center' => 'Cơ sở 1',
        ]);

        // Tạo lớp học nhóm có ca vào Thứ 3 (day = 2) của giáo viên "Thái Hiếu"
        // 2026-10-20 là Thứ 3 (Tuesday, dayOfWeek = 2)
        $courseClass = CourseClass::create([
            'course_id' => $course->id,
            'name' => 'Lớp Guitar Nhóm A',
            'room' => 'Phòng 101',
            'center' => 'Cơ sở 1',
            'capacity' => 4,
            'schedules' => [
                [
                    'day' => 2,
                    'start' => '18:00',
                    'end' => '19:00',
                    'teacher' => 'Thái Hiếu',
                ],
            ],
        ]);

        // 1. Kiểm tra lấy ca dạy nhóm có sẵn của giáo viên vào ngày 2026-10-20
        $res = $this->actingAs($this->user)->getJson('/api/attendance/available-group-slots?date=2026-10-20&teacher=Thái Hiếu&center=Cơ sở 1');
        $res->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('slots.0.class_id', $courseClass->id)
            ->assertJsonPath('slots.0.teacher', 'Thái Hiếu');

        // 2. Đặt buổi học lẻ ghép vào ca nhóm
        $slotId = $res->json('slots.0.id');
        $lessonRes = $this->actingAs($this->user)->postJson('/api/attendance/single-lesson', [
            'student_id' => $student->id,
            'course_id' => $course->id,
            'center' => 'Cơ sở 1',
            'date' => '2026-10-20',
            'time' => '18:00 - 19:00',
            'teacher' => 'Thái Hiếu',
            'merged_slot_id' => $slotId,
            'merged_class_id' => $courseClass->id,
            'note' => 'Học ghép với lớp guitar',
        ]);

        $lessonRes->assertStatus(201);
    }
}
