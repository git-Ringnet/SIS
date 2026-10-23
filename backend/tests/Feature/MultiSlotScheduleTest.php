<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Invoice;
use App\Models\Student;
use App\Models\User;
use App\Support\BrandSettings;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MultiSlotScheduleTest extends TestCase
{
    use RefreshDatabase;

    private const MONDAY = '2026-10-19';

    private Course $guitar;

    private Course $piano;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Carbon::setTestNow(self::MONDAY.' 08:00:00');
        Sanctum::actingAs(User::factory()->create());
        $this->guitar = Course::create(['code' => 'G', 'name' => 'Guitar', 'price' => 1, 'default_sessions' => 12]);
        $this->piano = Course::create(['code' => 'P', 'name' => 'Piano', 'price' => 1, 'default_sessions' => 10]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * Học viên: T2 có 2 ca (09:00 Guitar với Cô Mai, 15:00 Piano với Thầy Bình), CN 15:30.
     */
    private function createStudent(): Student
    {
        $id = $this->postJson('/api/students', [
            'name' => 'Bé An', 'gender' => 'Nam', 'parent_name' => 'PH', 'parent_phone' => '0900000000',
            'course_id' => $this->guitar->id, 'total_sessions' => 12, 'status' => 'active',
            'schedules' => [
                ['day' => 0, 'start' => '15:30', 'end' => '16:30', 'teacher' => 'Cô Mai', 'room' => 'G1'],
                ['day' => 1, 'start' => '15:00', 'end' => '16:00', 'teacher' => 'Thầy Bình', 'room' => 'P1'],
                ['day' => 1, 'start' => '09:00', 'end' => '10:00', 'teacher' => 'Cô Mai', 'room' => 'G1'],
            ],
        ])->assertCreated()->json('student.id');

        $student = Student::find($id);
        $slots = collect($student->schedules);
        // 2 khoá: Guitar dùng ca T2 09:00 + CN, Piano dùng ca T2 15:00
        $student->update(['registered_courses' => [
            ['id' => 1, 'course_id' => $this->guitar->id, 'course_name' => 'Guitar', 'total_sessions' => 12, 'attended_sessions' => 0, 'remaining_sessions' => 12,
                'schedule_ids' => $slots->where('start', '!=', '15:00')->pluck('id')->values()->all()],
            ['id' => 2, 'course_id' => $this->piano->id, 'course_name' => 'Piano', 'total_sessions' => 10, 'attended_sessions' => 0, 'remaining_sessions' => 10,
                'schedule_ids' => $slots->where('start', '15:00')->pluck('id')->values()->all()],
        ], 'total_sessions' => 22, 'remaining_sessions' => 22]);

        return $student->fresh();
    }

    public function test_schedules_are_saved_sorted_with_legacy_summary(): void
    {
        $student = $this->createStudent();

        $this->assertSame(['09:00', '15:00', '15:30'], collect($student->schedules)->pluck('start')->all()); // T2 09:00, T2 15:00, CN
        $this->assertSame([1, 0], $student->class_days);
        $this->assertSame('T2 09:00 · T2 15:00 · CN 15:30', $student->schedule_info);
        $this->assertSame('Cô Mai, Thầy Bình', $student->teacher);
        $this->assertCount(3, $student->schedule_slots);
    }

    public function test_attendance_shows_one_row_per_slot_and_deducts_the_right_course(): void
    {
        $student = $this->createStudent();

        $rows = collect($this->getJson('/api/attendance?date='.self::MONDAY)->assertOk()->json('students'));
        $this->assertCount(2, $rows);
        $this->assertSame(['09:00 - 10:00', '15:00 - 16:00'], $rows->pluck('shift_time')->all());
        $this->assertSame(['Guitar', 'Piano'], $rows->pluck('course.name')->all());
        $this->assertSame(['Cô Mai', 'Thầy Bình'], $rows->pluck('teacher')->all());

        [$morning, $afternoon] = $rows->pluck('slot_id')->all();

        $this->postJson("/api/attendance/{$student->id}/mark", ['date' => self::MONDAY, 'status' => 'present', 'slot_id' => $morning])->assertOk();
        $rows = collect($this->getJson('/api/attendance?date='.self::MONDAY)->json('students'));
        $this->assertSame(['present', 'not_checked'], $rows->pluck('status')->all());

        $this->postJson("/api/attendance/{$student->id}/mark", ['date' => self::MONDAY, 'status' => 'present', 'slot_id' => $afternoon])->assertOk();

        $courses = collect($student->fresh()->registered_courses)->keyBy('id');
        $this->assertSame(1, $courses[1]['attended_sessions']); // Guitar trừ 1
        $this->assertSame(1, $courses[2]['attended_sessions']); // Piano trừ 1
        $this->assertSame(20, $student->fresh()->remaining_sessions);

        // Hoàn tác ca chiều → trả buổi về Piano
        $this->postJson("/api/attendance/{$student->id}/mark", ['date' => self::MONDAY, 'status' => 'not_checked', 'slot_id' => $afternoon])->assertOk();
        $courses = collect($student->fresh()->registered_courses)->keyBy('id');
        $this->assertSame(0, $courses[2]['attended_sessions']);
        $this->assertSame(21, $student->fresh()->remaining_sessions);
    }

    public function test_quick_attendance_marks_next_slot_then_stops(): void
    {
        $student = $this->createStudent();

        $this->postJson("/api/students/{$student->id}/attendance")->assertOk();
        $this->postJson("/api/students/{$student->id}/attendance")->assertOk();
        $this->postJson("/api/students/{$student->id}/attendance")->assertUnprocessable(); // đã đủ 2 ca hôm nay

        $this->assertSame(['present', 'present'], collect($this->getJson('/api/attendance?date='.self::MONDAY)->json('students'))->pluck('status')->all());
    }

    public function test_teacher_shifts_follow_each_slot(): void
    {
        $this->createStudent();

        $shifts = collect($this->getJson('/api/teacher-checkin?date='.self::MONDAY)->assertOk()->json('today_shifts'));
        $this->assertSame(['Cô Mai' => '09:00', 'Thầy Bình' => '15:00'], $shifts->pluck('start_time', 'teacher_name')->all());
    }

    public function test_course_can_pick_slots_and_overlapping_own_slots_warn(): void
    {
        $student = $this->createStudent();
        $sunday = collect($student->schedules)->firstWhere('day', 0)['id'];

        $this->putJson("/api/students/{$student->id}/courses/2", ['schedule_ids' => [$sunday, 'khong-co']])
            ->assertOk()->assertJsonPath('student.registered_courses.1.schedule_ids', [$sunday]);

        $warnings = $this->putJson("/api/students/{$student->id}", [
            ...$student->only(['name', 'gender', 'parent_name', 'parent_phone', 'course_id', 'total_sessions', 'remaining_sessions', 'status']),
            'retention_status' => 'normal',
            'schedules' => [['day' => 1, 'start' => '09:00', 'end' => '10:00'], ['day' => 1, 'start' => '09:30', 'end' => '10:30']],
        ])->assertOk()->json('warnings');
        $this->assertStringContainsString('2 ca chồng giờ nhau', implode(' ', $warnings));

        $this->putJson("/api/students/{$student->id}", [
            ...$student->only(['name', 'gender', 'parent_name', 'parent_phone', 'course_id', 'total_sessions', 'remaining_sessions', 'status']),
            'retention_status' => 'normal',
            'schedules' => [['day' => 1, 'start' => '10:00', 'end' => '09:00']],
        ])->assertUnprocessable();
    }

    public function test_parent_portal_shows_planned_date_of_each_upcoming_session(): void
    {
        $student = $this->createStudent(); // T2 09:00, T2 15:00, CN 15:30, 22 buổi
        $morning = collect($student->schedules)->firstWhere('start', '09:00')['id'];
        // Hôm nay (T2 19/10) đã điểm danh ca sáng → buổi sắp tới đầu tiên là ca chiều hôm nay
        $this->postJson("/api/attendance/{$student->id}/mark", ['date' => self::MONDAY, 'status' => 'present', 'slot_id' => $morning])->assertOk();

        $sessions = collect($this->postJson("/api/portal/students/{$student->id}/verify", ['phone_last4' => '0000'])->assertOk()->json('sessions'));
        $upcoming = $sessions->where('status', 'upcoming')->values();

        $this->assertSame('present', $sessions[0]['status']);
        $this->assertSame(
            ['19/10/2026 15:00 - 16:00', '25/10/2026 15:30 - 16:30', '26/10/2026 09:00 - 10:00', '26/10/2026 15:00 - 16:00'],
            $upcoming->take(4)->map(fn ($s) => $s['date'].' '.$s['time'])->all(),
        );
        $this->assertSame(['Thứ 2', 'Chủ nhật'], $upcoming->take(2)->pluck('weekday')->all());
        $this->assertSame('Thầy Bình', $upcoming[0]['teacher']);
        $this->assertTrue($upcoming->every(fn ($s) => ! empty($s['date']))); // đủ ngày cho mọi buổi chưa học

        // Tạm dừng đến 01/11 → buổi sắp tới tính từ ngày đó (CN 01/11)
        $student->update(['status' => 'paused', 'paused_until' => '2026-11-01']);
        $first = collect($this->postJson("/api/portal/students/{$student->id}/verify", ['phone_last4' => '0000'])->json('sessions'))->firstWhere('status', 'upcoming');
        $this->assertSame('01/11/2026', $first['date']);

        // Đã nghỉ học → không còn ngày dự kiến
        $student->update(['status' => 'inactive']);
        $first = collect($this->postJson("/api/portal/students/{$student->id}/verify", ['phone_last4' => '0000'])->json('sessions'))->firstWhere('status', 'upcoming');
        $this->assertNull($first['date']);
    }

    public function test_course_end_date_is_estimated_from_schedule_when_missing(): void
    {
        $student = $this->createStudent(); // Guitar: T2 09:00 + CN, 12 buổi; Piano: T2 15:00, 10 buổi; hôm nay T2 19/10
        $student->update(['end_date' => null]);
        $student->refresh();

        // Guitar: 19/10, 25/10, 26/10, 01/11 ... buổi thứ 12 là CN 29/11
        $this->assertSame(['start' => null, 'start_estimated' => false, 'end' => '29/11/2026', 'end_estimated' => true], [...$student->courseDates($this->guitar->id), 'start' => null]);
        // Piano chỉ học T2 15:00: 10 buổi → T2 21/12
        $this->assertSame('21/12/2026', $student->courseDates($this->piano->id)['end']);

        // Đã nhập ngày kết thúc cho khóa thì dùng ngày đó, không tự tính
        $courses = $student->registered_courses;
        $courses[1]['end_date'] = '2027-01-15';
        $student->update(['registered_courses' => $courses]);
        $this->assertSame(['15/01/2027', false], array_values(array_slice($student->fresh()->courseDates($this->piano->id), 2)));

        // PDF hiện chữ "(dự kiến)" khi ngày kết thúc là ngày tự tính
        $id = $this->postJson('/api/invoices', ['student_id' => $student->id, 'course_id' => $this->guitar->id, 'amount' => 1, 'sessions_added' => 0, 'payment_method' => 'cash', 'paid_at' => self::MONDAY])
            ->assertCreated()->json('invoice.id');
        $html = view('pdf.payment-receipt', ['invoice' => Invoice::find($id), 'brand' => BrandSettings::brand()])->render();
        $this->assertMatchesRegularExpression('/Ngày kết thúc: <strong>29\/11\/2026<\/strong> <span[^>]*>\(dự kiến\)/u', $html);
    }
}
