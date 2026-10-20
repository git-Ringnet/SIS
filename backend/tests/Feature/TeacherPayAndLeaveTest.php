<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Course;
use App\Models\Student;
use App\Models\User;
use App\Support\CenterStore;
use App\Support\CheckinToken;
use App\Support\PagePermissions;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TeacherPayAndLeaveTest extends TestCase
{
    use RefreshDatabase;

    private Course $guitar;

    private Course $piano;

    private const DATE = '2026-10-20'; // Thứ 3

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Carbon::setTestNow(self::DATE.' 17:00:00');
        Sanctum::actingAs(User::factory()->create()); // chủ trung tâm

        $this->guitar = Course::create(['code' => 'G', 'name' => 'Guitar', 'price' => 1, 'default_sessions' => 12]);
        $this->piano = Course::create(['code' => 'P', 'name' => 'Piano', 'price' => 1, 'default_sessions' => 12]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function student(string $name, string $teacher, Course $course, string $room, string $time = '17:30'): Student
    {
        return Student::create([
            'code' => 'HV-'.$name, 'name' => $name, 'gender' => 'Nam', 'parent_name' => 'PH', 'parent_phone' => '09',
            'course_id' => $course->id, 'total_sessions' => 12, 'remaining_sessions' => 12, 'status' => 'active',
            'class_days' => [2], 'class_start_time' => $time, 'class_end_time' => '18:30', 'teacher' => $teacher, 'room' => $room,
        ]);
    }

    private function shifts(): array
    {
        return collect($this->getJson('/api/teacher-checkin?date='.self::DATE)->assertOk()->json('today_shifts'))->keyBy('teacher_name')->all();
    }

    private function monthly(): array
    {
        return $this->getJson('/api/teacher-checkin?date='.self::DATE.'&month=2026-10')->json('monthly_summary');
    }

    private function saveRates(array $teachers): void
    {
        $this->postJson('/api/teacher-checkin/rates', ['teachers' => $teachers])->assertOk();
    }

    public function test_per_shift_rate_with_course_override_and_one_on_one(): void
    {
        $a = $this->student('A', 'Cô Mai', $this->guitar, 'G1');
        $this->student('B', 'Cô Mai', $this->guitar, 'G1');      // ca nhóm Guitar
        $this->student('C', 'Thầy Bình', $this->piano, 'P1');    // ca 1:1

        $this->getJson('/api/teacher-checkin/rates')->assertOk()
            ->assertJsonPath('teachers.0.name', 'Cô Mai')
            ->assertJsonPath('teachers.0.courses.0.name', 'Guitar');

        $this->saveRates([
            ['name' => 'Cô Mai', 'mode' => 'per_shift', 'group' => 200000, 'one_on_one' => 150000,
                'courses' => [['course_id' => $this->guitar->id, 'group' => 250000]]],
            ['name' => 'Thầy Bình', 'mode' => 'per_student', 'group' => 50000, 'one_on_one' => 120000],
        ]);

        $s = $this->shifts();
        $this->postJson('/api/teacher-checkin/checkin', ['shift_id' => $s['Cô Mai']['id']])->assertOk();
        $this->postJson('/api/teacher-checkin/checkin', ['shift_id' => $s['Thầy Bình']['id']])->assertOk();

        // Đổi đơn giá sau khi check-in không ảnh hưởng ca đã tính
        $this->saveRates([['name' => 'Cô Mai', 'mode' => 'per_shift', 'group' => 1, 'one_on_one' => 1, 'courses' => []]]);

        $m = $this->monthly();
        $amounts = collect($m['shifts'])->pluck('amount', 'earner');
        $this->assertEquals(250000, $amounts['Cô Mai']);   // giá riêng khoá Guitar, theo ca
        $this->assertEquals(120000, $amounts['Thầy Bình']); // 1:1 luôn theo đơn giá 1:1
        $this->assertEquals(370000, $m['total_salary']);
    }

    public function test_rates_list_courses_from_schedule_slots_and_checkin_uses_that_course_rate(): void
    {
        // Khoá chính của học viên là Guitar, nhưng ca Thứ 3 (Cô Mai dạy) thuộc khoá Piano
        Student::create([
            'code' => 'HV-D', 'name' => 'D', 'gender' => 'Nam', 'parent_name' => 'PH', 'parent_phone' => '09',
            'course_id' => $this->guitar->id, 'total_sessions' => 24, 'remaining_sessions' => 24, 'status' => 'active',
            'class_days' => [2], 'teacher' => 'Cô Mai',
            'schedules' => [['id' => 'd2', 'day' => 2, 'start' => '17:30', 'end' => '18:30', 'teacher' => 'Cô Mai', 'room' => 'P1']],
            'registered_courses' => [
                ['id' => 1, 'course_id' => $this->guitar->id, 'total_sessions' => 12, 'remaining_sessions' => 12],
                ['id' => 2, 'course_id' => $this->piano->id, 'total_sessions' => 12, 'remaining_sessions' => 12, 'schedule_ids' => ['d2']],
            ],
        ]);

        $teachers = collect($this->getJson('/api/teacher-checkin/rates')->assertOk()->json('teachers'))->keyBy('name');
        $this->assertSame(['Piano'], array_column($teachers['Cô Mai']['courses'], 'name'));

        $this->saveRates([['name' => 'Cô Mai', 'mode' => 'per_shift', 'group' => 0, 'one_on_one' => 100000,
            'courses' => [['course_id' => $this->piano->id, 'one_on_one' => 180000]]]]);

        $this->postJson('/api/teacher-checkin/checkin', ['shift_id' => $this->shifts()['Cô Mai']['id']])->assertOk();

        $this->assertEquals(180000, $this->monthly()['total_salary']);
    }

    public function test_manager_can_reset_edit_and_delete_shifts(): void
    {
        $this->student('A', 'Cô Mai', $this->guitar, 'G1');
        $this->student('B', 'Thầy Bình', $this->piano, 'P1');
        $this->saveRates([['name' => 'Cô Mai', 'mode' => 'per_shift', 'group' => 0, 'one_on_one' => 100000, 'courses' => []]]);

        $id = $this->shifts()['Cô Mai']['id'];
        $this->postJson('/api/teacher-checkin/checkin', ['shift_id' => $id, 'checkin_time' => '17:30'])->assertOk();

        // Sửa: giờ check-in trễ → tự thành đi trễ; đơn giá đã chốt đổi theo
        $this->putJson("/api/teacher-checkin/{$id}", ['checkin_time' => '17:50', 'unit_rate' => 120000])->assertOk();
        $shift = $this->shifts()['Cô Mai'];
        $this->assertSame('late', $shift['status']);
        $this->assertSame(20, $shift['minutes_late']);
        $this->assertEquals(120000, $this->monthly()['total_salary']);

        // Khôi phục: về chưa check-in, check-in lại được
        $this->postJson("/api/teacher-checkin/{$id}/reset")->assertOk();
        $shift = $this->shifts()['Cô Mai'];
        $this->assertSame('not_checked_in', $shift['status']);
        $this->assertArrayNotHasKey('unit_rate', $shift);
        $this->assertEquals(0, $this->monthly()['total_salary']);
        $this->postJson("/api/teacher-checkin/{$id}/reset")->assertStatus(422);
        $this->putJson("/api/teacher-checkin/{$id}", ['checkin_time' => '17:30'])->assertStatus(422);

        // Xoá: ca biến mất và không được tự sinh lại từ lịch học
        $this->deleteJson("/api/teacher-checkin/{$id}")->assertOk();
        $this->assertArrayNotHasKey('Cô Mai', $this->shifts());
        $this->assertArrayHasKey('Thầy Bình', $this->shifts());
        $this->deleteJson("/api/teacher-checkin/{$id}")->assertNotFound();
    }

    public function test_per_student_rate_counts_present_students(): void
    {
        $a = $this->student('A', 'Cô Mai', $this->guitar, 'G1');
        $b = $this->student('B', 'Cô Mai', $this->guitar, 'G1');
        $this->student('C', 'Cô Mai', $this->guitar, 'G1');
        $this->saveRates([['name' => 'Cô Mai', 'mode' => 'per_student', 'group' => 60000, 'one_on_one' => 0]]);

        $this->postJson('/api/teacher-checkin/checkin', ['shift_id' => $this->shifts()['Cô Mai']['id']])->assertOk();
        foreach ([$a, $b] as $st) {
            Attendance::create(['student_id' => $st->id, 'course_id' => $st->course_id, 'session_number' => 1,
                'attended_at' => self::DATE.' 17:30', 'status' => 'present']);
        }

        $this->assertEquals(120000, $this->monthly()['total_salary']); // 2 bé có mặt × 60.000
    }

    private function markPresent(array $students): void
    {
        foreach ($students as $st) {
            Attendance::create(['student_id' => $st->id, 'course_id' => $st->course_id, 'session_number' => 1,
                'attended_at' => self::DATE.' 17:30', 'status' => 'present']);
        }
    }

    public function test_free_mode_pays_per_student_above_threshold_and_per_shift_otherwise(): void
    {
        // Ca 17:30 có 3 bé, ca 19:00 có 2 bé, cùng Cô Mai
        $a = $this->student('A', 'Cô Mai', $this->guitar, 'G1');
        $b = $this->student('B', 'Cô Mai', $this->guitar, 'G1');
        $c = $this->student('C', 'Cô Mai', $this->guitar, 'G1');
        $d = $this->student('D', 'Cô Mai', $this->guitar, 'G2', '19:00');
        $e = $this->student('E', 'Cô Mai', $this->guitar, 'G2', '19:00');
        $this->saveRates([['name' => 'Cô Mai', 'mode' => 'free', 'one_on_one' => 0,
            'free' => ['threshold' => 2, 'per_student' => 50000, 'per_shift' => 120000]]]);

        foreach (collect($this->getJson('/api/teacher-checkin?date='.self::DATE)->json('today_shifts'))->pluck('id') as $id) {
            $this->postJson('/api/teacher-checkin/checkin', ['shift_id' => $id])->assertOk();
        }
        $this->markPresent([$a, $b, $c, $d, $e]);

        $amounts = collect($this->monthly()['shifts'])->pluck('amount', 'start_time');
        $this->assertEquals(150000, $amounts['17:30']); // 3 bé > ngưỡng 2 → 3 × 50.000
        $this->assertEquals(120000, $amounts['19:00']); // 2 bé = ngưỡng (≤) → giá / ca dạy
    }

    public function test_free_mode_falls_back_to_center_defaults(): void
    {
        $center = CenterStore::all()[0];
        $this->putJson("/api/centers/{$center['id']}", ['name' => $center['name'],
            'free_threshold' => 1, 'free_per_student' => 40000, 'free_per_shift' => 90000])->assertOk();

        $a = $this->student('A', 'Cô Mai', $this->guitar, 'G1');
        $b = $this->student('B', 'Cô Mai', $this->guitar, 'G1');
        Student::query()->update(['center' => $center['name']]);
        // Giáo viên chỉ đặt giá / lượt, ngưỡng và giá / ca để trống → lấy của cơ sở
        $this->saveRates([['name' => 'Cô Mai', 'mode' => 'free', 'free' => ['per_student' => 60000]]]);

        $this->postJson('/api/teacher-checkin/checkin', ['shift_id' => $this->shifts()['Cô Mai']['id']])->assertOk();
        $this->markPresent([$a, $b]);

        $this->assertEquals(120000, $this->monthly()['total_salary']); // 2 bé > ngưỡng 1 (của cơ sở) → 2 × 60.000
    }

    public function test_center_can_let_free_mode_teachers_check_in_without_qr(): void
    {
        $center = CenterStore::all()[0];
        $this->student('A', 'Cô Mai', $this->guitar, 'G1');
        $this->student('B', 'Cô Mai', $this->guitar, 'G1');
        $this->saveRates([['name' => 'Cô Mai', 'mode' => 'free', 'free' => ['threshold' => 5, 'per_student' => 1, 'per_shift' => 1]]]);
        $this->getJson('/api/teacher-checkin?date='.self::DATE);
        $id = collect(json_decode(Storage::get('teacher_checkins.json'), true))->first()['id'];

        $mai = User::factory()->create(['name' => 'Cô Mai']);
        Storage::put('staff_data.json', json_encode(['members' => [[
            'id' => 9, 'name' => 'Cô Mai', 'email' => $mai->email, 'role' => 'Giáo viên',
            'blocked_pages' => PagePermissions::defaultBlockedPages('Giáo viên'),
        ]]]));
        Sanctum::actingAs($mai);

        // Mặc định cơ sở bắt buộc QR
        $this->postJson('/api/teacher-checkin/checkin', ['shift_id' => $id])->assertForbidden();

        // Bỏ tích "Bắt buộc quét QR trước khi nhận ca dạy tự do"
        $all = json_decode(Storage::get('centers_data.json') ?: json_encode(CenterStore::all()), true);
        $all[0]['free_require_qr'] = false;
        CenterStore::save($all);

        $this->postJson('/api/teacher-checkin/checkin', ['shift_id' => $id])->assertOk();
    }

    public function test_leave_marks_shifts_excused_and_blocks_checkin_until_removed(): void
    {
        $this->student('A', 'Cô Mai', $this->guitar, 'G1');
        $leave = $this->postJson('/api/teacher-checkin/leaves', ['teacher' => 'cô mai', 'from' => '2026-10-19', 'to' => '2026-10-21', 'reason' => 'Ốm'])
            ->assertCreated()->json('leave');

        $shift = $this->shifts()['Cô Mai'];
        $this->assertSame('excused_absence', $shift['status']);
        $this->postJson('/api/teacher-checkin/checkin', ['shift_id' => $shift['id']])->assertUnprocessable();

        $this->deleteJson("/api/teacher-checkin/leaves/{$leave['id']}")->assertOk();
        $this->assertSame('not_checked_in', $this->shifts()['Cô Mai']['status']);
    }

    public function test_substitute_sees_checks_in_and_earns_the_shift(): void
    {
        $this->student('A', 'Cô Mai', $this->guitar, 'G1');
        $this->student('B', 'Cô Mai', $this->guitar, 'G1');
        $this->saveRates([
            ['name' => 'Cô Mai', 'mode' => 'per_shift', 'group' => 200000, 'one_on_one' => 0],
            ['name' => 'Thầy Bình', 'mode' => 'per_shift', 'group' => 180000, 'one_on_one' => 0],
        ]);
        $this->postJson('/api/teacher-checkin/leaves', ['teacher' => 'Cô Mai', 'from' => self::DATE, 'to' => self::DATE])->assertCreated();
        $this->postJson('/api/teacher-checkin/substitutions', ['original' => 'Cô Mai', 'substitute' => 'Cô Mai', 'from' => self::DATE, 'to' => self::DATE])
            ->assertUnprocessable();
        $this->postJson('/api/teacher-checkin/substitutions', ['original' => 'Cô Mai', 'substitute' => 'Thầy Bình', 'from' => self::DATE, 'to' => self::DATE])
            ->assertCreated();

        // Đăng nhập bằng giáo viên dạy thay
        $binh = User::factory()->create(['name' => 'Thầy Bình']);
        Storage::put('staff_data.json', json_encode(['members' => [[
            'id' => 9, 'name' => 'Thầy Bình', 'email' => $binh->email, 'role' => 'Giáo viên',
            'blocked_pages' => PagePermissions::defaultBlockedPages('Giáo viên'),
        ]]]));
        Sanctum::actingAs($binh);

        $shift = collect($this->getJson('/api/teacher-checkin?date='.self::DATE)->json('today_shifts'))->first();
        $this->assertSame('Thầy Bình', $shift['substitute_teacher']);
        $center = CenterStore::defaultName();
        $this->postJson('/api/teacher-checkin/checkin', ['shift_id' => $shift['id'],
            'qr_center' => $center, 'qr_token' => CheckinToken::current($center)['token']])->assertOk();
        $this->getJson('/api/teacher-checkin/leaves')->assertForbidden();

        $m = $this->monthly();
        $this->assertSame('Thầy Bình', $m['shifts'][0]['earner']);
        $this->assertEquals(180000, $m['total_salary']); // theo đơn giá của người dạy thay
    }
}
