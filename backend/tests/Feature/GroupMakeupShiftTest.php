<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Student;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Buổi bù / học lẻ của khóa nhóm đặt đúng giờ lớp nhóm của giáo viên (vd hết khóa, giáo vụ đặt bù đúng giờ học nhóm cũ):
 * gộp vào ca nhóm đó (một ca, một đầu lương), không tách thành ca riêng. Khóa cá nhân 1:1 vẫn mỗi học viên một ca.
 */
class GroupMakeupShiftTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Carbon::setTestNow('2026-11-03 08:00:00'); // Thứ 3
        Sanctum::actingAs(User::factory()->create());
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function student(string $code, Course $course, int $remaining = 8): Student
    {
        return Student::create([
            'code' => $code, 'name' => "Bé {$code}", 'gender' => 'Nam', 'parent_name' => 'PH', 'parent_phone' => '09',
            'course_id' => $course->id, 'total_sessions' => 8, 'remaining_sessions' => $remaining, 'status' => 'active',
            'center' => 'Khoa Anh Music', 'join_date' => '2026-09-01', 'class_days' => [2],
            'schedules' => [['id' => 'tue', 'day' => 2, 'start' => '20:00', 'end' => '21:00', 'teacher' => 'Thầy Khoa', 'room' => null]],
        ]);
    }

    private function shiftsAt(string $time): array
    {
        return collect($this->getJson('/api/teacher-checkin?date=2026-11-03')->assertOk()->json('today_shifts'))
            ->where('start_time', $time)->values()->all();
    }

    public function test_group_makeup_at_group_time_joins_the_group_shift(): void
    {
        $guitar = Course::create(['code' => 'G', 'name' => 'Guitar nhóm', 'price' => 1, 'default_sessions' => 8, 'class_type' => 'group']);
        $a = $this->student('HV-1', $guitar);
        $b = $this->student('HV-2', $guitar);
        $c = $this->student('HV-3', $guitar, 0); // hết khóa: giáo vụ đặt bù đúng giờ học nhóm cũ, khác phòng
        $this->postJson('/api/attendance/single-lesson', ['student_id' => $c->id, 'date' => '2026-11-03', 'time' => '20:00',
            'teacher' => 'Thầy Khoa', 'room' => 'PHÒNG MAIN', 'course_id' => $guitar->id, 'is_makeup' => true])->assertCreated();

        $shifts = $this->shiftsAt('20:00');
        $this->assertCount(1, $shifts);
        $this->assertSame('Ca nhóm', $shifts[0]['type']);
        $this->assertEqualsCanonicalizing([$a->id, $b->id, $c->id], $shifts[0]['student_ids']);
    }

    public function test_individual_makeup_at_same_time_stays_its_own_shift(): void
    {
        $solo = Course::create(['code' => 'P', 'name' => 'Piano 1:1', 'price' => 1, 'default_sessions' => 8, 'class_type' => 'individual']);
        $group = Course::create(['code' => 'G', 'name' => 'Guitar nhóm', 'price' => 1, 'default_sessions' => 8, 'class_type' => 'group']);
        $this->student('HV-1', $group);
        $this->student('HV-2', $group);
        $d = Student::create(['code' => 'HV-9', 'name' => 'Bé 1:1', 'gender' => 'Nam', 'parent_name' => 'PH', 'parent_phone' => '09',
            'course_id' => $solo->id, 'total_sessions' => 8, 'remaining_sessions' => 8, 'status' => 'active', 'center' => 'Khoa Anh Music']);
        $this->postJson('/api/attendance/single-lesson', ['student_id' => $d->id, 'date' => '2026-11-03', 'time' => '20:00',
            'teacher' => 'Thầy Khoa', 'course_id' => $solo->id, 'is_makeup' => true])->assertCreated();

        $shifts = collect($this->shiftsAt('20:00'));
        $this->assertCount(2, $shifts);
        $this->assertSame([$d->id], $shifts->firstWhere('type', 'Ca 1-1')['student_ids']);
    }
}
