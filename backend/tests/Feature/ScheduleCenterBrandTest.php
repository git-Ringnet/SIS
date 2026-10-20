<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Student;
use App\Models\User;
use App\Support\BrandSettings;
use App\Support\CenterStore;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ScheduleCenterBrandTest extends TestCase
{
    use RefreshDatabase;

    private Course $course;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake();
        Sanctum::actingAs(User::factory()->create());
        $this->course = Course::create(['code' => 'PIANO', 'name' => 'Piano', 'price' => 3000000, 'default_sessions' => 12]);
    }

    private function createStudent(array $overrides = []): array
    {
        return $this->postJson('/api/students', array_merge([
            'name' => 'Học viên',
            'gender' => 'Nam',
            'parent_name' => 'PH',
            'parent_phone' => '0900000000',
            'course_id' => $this->course->id,
            'total_sessions' => 12,
            'status' => 'active',
        ], $overrides))->assertCreated()->json('student');
    }

    public function test_student_with_weekly_schedule_appears_in_attendance_only_on_scheduled_days(): void
    {
        $student = $this->createStudent([
            'class_days' => [2, 4], // Thứ 3, Thứ 5
            'class_start_time' => '17:30',
            'class_end_time' => '18:30',
            'teacher' => 'Cô Mai',
            'room' => 'PIANO 01',
        ]);

        $this->assertSame('T3, T5 • 17:30 - 18:30', Student::find($student['id'])->schedule_info);

        $tuesday = Carbon::parse('next tuesday')->toDateString();
        $wednesday = Carbon::parse('next wednesday')->toDateString();

        $this->getJson("/api/attendance?date={$tuesday}")
            ->assertOk()
            ->assertJsonPath('students.0.id', $student['id'])
            ->assertJsonPath('students.0.teacher', 'Cô Mai')
            ->assertJsonPath('students.0.room', 'PIANO 01')
            ->assertJsonPath('students.0.shift_time', '17:30 - 18:30');

        $this->getJson("/api/attendance?date={$wednesday}")->assertOk()->assertJsonCount(0, 'students');

        // Bộ lọc ca: 17:30 thuộc ca tối
        $this->getJson("/api/attendance?date={$tuesday}&shift=evening")->assertJsonCount(1, 'students');
        $this->getJson("/api/attendance?date={$tuesday}&shift=morning")->assertJsonCount(0, 'students');
    }

    public function test_dropped_students_do_not_appear_in_attendance(): void
    {
        $student = $this->createStudent(['class_days' => [2], 'class_start_time' => '09:00']);
        Student::find($student['id'])->update(['status' => 'dropped']);

        $tuesday = Carbon::parse('next tuesday')->toDateString();
        $this->getJson("/api/attendance?date={$tuesday}")->assertJsonCount(0, 'students');
    }

    public function test_end_time_must_be_after_start_time(): void
    {
        $this->postJson('/api/students', [
            'name' => 'X', 'gender' => 'Nam', 'parent_name' => 'PH', 'parent_phone' => '09',
            'course_id' => $this->course->id, 'total_sessions' => 12, 'status' => 'active',
            'class_days' => [1], 'class_start_time' => '18:00', 'class_end_time' => '17:00',
        ])->assertUnprocessable()->assertJsonValidationErrors('class_end_time');
    }

    public function test_room_report_is_built_from_real_schedules_and_center_capacity(): void
    {
        $center = CenterStore::defaultName();
        $centerId = CenterStore::all()[0]['id'];
        $this->postJson("/api/centers/{$centerId}/rooms", ['name' => 'GROUP', 'capacity' => 6])->assertSuccessful();
        foreach (range(1, 2) as $i) {
            $this->createStudent(['center' => $center, 'class_days' => [1], 'class_start_time' => '18:00', 'room' => 'GROUP']);
        }

        $slot = collect($this->getJson('/api/reports')->assertOk()->json('room_slots'))
            ->firstWhere('room', 'GROUP');

        $this->assertSame('Thứ 2 - 18:00', $slot['schedule_label']);
        $this->assertSame(2, $slot['current_students']);
        $this->assertSame(6, $slot['capacity']); // sức chứa phòng GROUP trong dữ liệu trung tâm
        $this->assertSame(4, $slot['available']);
    }

    public function test_new_center_shows_up_in_other_screens_filters(): void
    {
        $this->postJson('/api/centers', ['name' => 'Cơ sở Thủ Đức'])->assertCreated();

        $this->assertContains('Cơ sở Thủ Đức', $this->getJson('/api/leads')->json('centers'));
        $this->assertContains('Cơ sở Thủ Đức', $this->getJson('/api/attendance')->json('centers'));
        $this->assertContains('Cơ sở Thủ Đức', $this->getJson('/api/staff')->json('centers'));
    }

    public function test_new_student_defaults_to_main_center(): void
    {
        $student = $this->createStudent();

        $this->assertSame(CenterStore::defaultName(), $student['center']);
    }

    public function test_teacher_shifts_follow_student_schedules(): void
    {
        $date = Carbon::parse('next monday')->toDateString();
        $slot = ['class_days' => [1], 'class_start_time' => '18:00', 'class_end_time' => '19:00', 'teacher' => 'Cô Mai', 'room' => 'GROUP'];
        $shiftsOf = fn () => collect($this->getJson("/api/teacher-checkin?date={$date}")->assertOk()->json('today_shifts'))
            ->where('teacher_name', 'Cô Mai')->values();

        $a = $this->createStudent(['name' => 'An', ...$slot]);
        $this->assertSame('Ca 1-1', $shiftsOf()->first()['type']);

        // Thêm học viên cùng khung giờ: ca chưa check-in tự thành ca nhóm, không sinh ca trùng
        $b = $this->createStudent(['name' => 'Bình', ...$slot]);
        $this->assertCount(1, $shiftsOf());
        $this->assertSame('Ca nhóm', $shiftsOf()->first()['type']);

        // Không còn học viên: ca chưa check-in bị bỏ
        Student::whereIn('id', [$a['id'], $b['id']])->update(['status' => 'dropped']);
        $this->assertCount(0, $shiftsOf());

        // Ca đã check-in thì giữ lại làm lịch sử
        Student::whereIn('id', [$a['id'], $b['id']])->update(['status' => 'active']);
        $this->postJson('/api/teacher-checkin/checkin', ['shift_id' => $shiftsOf()->first()['id']])->assertOk();
        Student::whereIn('id', [$a['id'], $b['id']])->update(['status' => 'dropped']);
        $this->assertSame('checked_in', $shiftsOf()->first()['status']);
    }

    public function test_brand_name_is_public_and_follows_settings(): void
    {
        $this->putJson('/api/settings/brand', ['brand_name' => 'Art Gallery', 'bank_holder' => 'ART GALLERY'])->assertOk();

        $this->app['auth']->forgetGuards();
        $this->getJson('/api/brand')->assertOk()->assertJsonPath('brand_name', 'Art Gallery');
        $this->assertSame('ART GALLERY', BrandSettings::brand()['bank_holder']);
    }
}
