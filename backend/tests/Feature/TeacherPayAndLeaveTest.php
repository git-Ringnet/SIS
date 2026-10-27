<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Course;
use App\Models\Student;
use App\Models\User;
use App\Support\CenterStore;
use App\Support\CheckinToken;
use App\Support\PagePermissions;
use App\Support\TeacherRates;
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

    /**
     * Lương ca riêng từng người (như đặt ở màn Nhân sự).
     */
    private function setPay(array $teachers): void
    {
        foreach ($teachers as $t) {
            TeacherRates::set($t['name'], (float) ($t['group'] ?? 0), (float) ($t['one_on_one'] ?? 0));
        }
    }

    public function test_each_teacher_has_own_group_and_one_on_one_pay_locked_at_checkin(): void
    {
        $this->student('A', 'Cô Mai', $this->guitar, 'G1');
        $this->student('B', 'Cô Mai', $this->guitar, 'G1');      // ca nhóm Guitar (3 bạn, > nửa sĩ số 4 → đủ lương)
        $this->student('D', 'Cô Mai', $this->guitar, 'G1');
        $this->student('C', 'Thầy Bình', $this->piano, 'P1');    // ca 1:1
        $this->student('E', 'Cô Lan', $this->piano, 'P2');       // chưa đặt lương

        $this->setPay([
            ['name' => 'Cô Mai', 'group' => 250000, 'one_on_one' => 150000],
            ['name' => 'Thầy Bình', 'group' => 50000, 'one_on_one' => 120000],
        ]);

        $s = $this->shifts();
        foreach (['Cô Mai', 'Thầy Bình', 'Cô Lan'] as $t) {
            $this->postJson('/api/teacher-checkin/checkin', ['shift_id' => $s[$t]['id']])->assertOk();
        }

        // Đổi lương sau khi check-in không ảnh hưởng ca đã tính
        $this->setPay([['name' => 'Cô Mai', 'group' => 1, 'one_on_one' => 1]]);

        $m = $this->monthly();
        $amounts = collect($m['shifts'])->pluck('amount', 'earner');
        $this->assertEquals(250000, $amounts['Cô Mai']);    // lương ca nhóm của Cô Mai
        $this->assertEquals(120000, $amounts['Thầy Bình']); // lương ca 1:1 của Thầy Bình
        $this->assertEquals(0, $amounts['Cô Lan']);         // không có mức chung cho mọi giáo viên
        $this->assertEquals(370000, $m['total_salary']);
        $this->getJson('/api/teacher-checkin/rates')->assertNotFound(); // "Đơn giá ca dạy" cũ đã bỏ
    }

    public function test_shifts_checked_in_with_old_pay_modes_keep_their_amount(): void
    {
        $a = $this->student('A', 'Cô Mai', $this->guitar, 'G1');
        $b = $this->student('B', 'Cô Mai', $this->guitar, 'G1');
        $this->student('C', 'Cô Mai', $this->guitar, 'G1');
        $this->getJson('/api/teacher-checkin?date='.self::DATE);
        // Ca đã check-in theo kiểu "theo lượt HV" trước khi đổi cách tính
        $shifts = json_decode(Storage::get('teacher_checkins.json'), true);
        $shifts[0] = [...$shifts[0], 'status' => 'checked_in', 'checkin_time' => '17:30', 'pay_mode' => 'per_student', 'unit_rate' => 60000];
        Storage::put('teacher_checkins.json', json_encode($shifts));
        foreach ([$a, $b] as $st) {
            Attendance::create(['student_id' => $st->id, 'course_id' => $st->course_id, 'attended_at' => self::DATE.' 17:30', 'status' => 'present']);
        }

        $this->assertEquals(120000, $this->monthly()['total_salary']); // 2 bé có mặt × 60.000 như trước
    }

    /**
     * Đăng nhập bằng một nhân sự ở màn Nhân sự với vai trò cho trước.
     */
    private function loginAs(string $name, string $role): void
    {
        $user = User::factory()->create(['name' => $name]);
        Storage::put('staff_data.json', json_encode(['members' => [[
            'id' => 9, 'name' => $name, 'email' => $user->email, 'role' => $role,
            'blocked_pages' => PagePermissions::defaultBlockedPages($role),
        ]]]));
        Sanctum::actingAs($user);
    }

    public function test_only_owner_and_manager_see_everyone_pay_others_see_their_own(): void
    {
        $this->student('A', 'Cô Mai', $this->guitar, 'G1');
        $this->student('C', 'Thầy Bình', $this->piano, 'P1', '19:00');
        $this->setPay([
            ['name' => 'Cô Mai', 'mode' => 'per_shift', 'group' => 0, 'one_on_one' => 150000],
            ['name' => 'Thầy Bình', 'mode' => 'per_shift', 'group' => 0, 'one_on_one' => 120000],
        ]);
        $s = $this->shifts();
        $this->postJson('/api/teacher-checkin/checkin', ['shift_id' => $s['Cô Mai']['id']])->assertOk();
        $this->postJson('/api/teacher-checkin/checkin', ['shift_id' => $s['Thầy Bình']['id']])->assertOk();
        $this->assertEquals(270000, $this->monthly()['total_salary']);

        // Chủ trung tâm xuất Excel riêng một giáo viên
        $csv = $this->get('/api/teacher-checkin/export?month=2026-10&teacher='.urlencode('Thầy Bình'))->assertOk()->streamedContent();
        $this->assertStringContainsString('Thầy Bình', $csv);
        $this->assertStringNotContainsString('Cô Mai', $csv);

        // Giáo viên: chỉ thù lao của mình
        $this->loginAs('Cô Mai', 'Giáo viên');
        $m = $this->monthly();
        $this->assertSame(['Cô Mai'], collect($m['shifts'])->pluck('earner')->all());
        $this->assertEquals(150000, $m['total_salary']);
        // Giáo viên truyền tên người khác vẫn chỉ xuất được của mình
        $csv = $this->get('/api/teacher-checkin/export?month=2026-10&teacher='.urlencode('Thầy Bình'))->assertOk()->streamedContent();
        $this->assertStringNotContainsString('Thầy Bình', $csv);

        // Giáo vụ vào được trang Check-in nhưng không thấy thù lao của giáo viên
        $this->loginAs('Chị Lan', 'Giáo vụ');
        $m = $this->monthly();
        $this->assertSame([], $m['shifts']);
        $this->assertEquals(0, $m['total_salary']);
        $this->assertArrayNotHasKey('unit_rate', $this->shifts()['Cô Mai']);
        $this->putJson("/api/teacher-checkin/{$s['Cô Mai']['id']}", ['checkin_time' => '17:30', 'unit_rate' => 1])->assertForbidden();

        // Quản lý xem được tất cả
        $this->loginAs('Anh Quản Lý', 'Quản lý');
        $this->assertEquals(270000, $this->monthly()['total_salary']);
    }

    public function test_only_owner_can_delete_a_paid_shift(): void
    {
        $owner = auth()->user();
        $this->student('C', 'Thầy Bình', $this->piano, 'P1');
        $this->setPay([['name' => 'Thầy Bình', 'mode' => 'per_shift', 'group' => 0, 'one_on_one' => 120000]]);
        $id = $this->shifts()['Thầy Bình']['id'];
        $this->postJson('/api/teacher-checkin/checkin', ['shift_id' => $id])->assertOk();

        $this->loginAs('Anh Quản Lý', 'Quản lý');
        $this->deleteJson("/api/teacher-checkin/{$id}")->assertForbidden();

        Sanctum::actingAs($owner);
        $this->deleteJson("/api/teacher-checkin/{$id}")->assertOk();
        $m = $this->monthly();
        $this->assertSame([], $m['shifts']);
        $this->assertEquals(0, $m['total_salary']);
        // Xoá nháp: hiện ở danh sách đã xoá kèm thù lao trước khi xoá
        $this->assertSame([[$id, 120000, true]], collect($m['deleted_shifts'])->map(fn ($s) => [$s['id'], (int) $s['amount'], $s['restorable']])->all());
    }

    public function test_deleted_shift_is_excluded_everywhere_until_restored(): void
    {
        $this->student('C', 'Thầy Bình', $this->piano, 'P1');
        $this->setPay([['name' => 'Thầy Bình', 'mode' => 'per_shift', 'group' => 0, 'one_on_one' => 120000]]);
        $id = $this->shifts()['Thầy Bình']['id'];
        $this->postJson('/api/teacher-checkin/checkin', ['shift_id' => $id, 'checkin_time' => '17:40'])->assertOk();
        $this->deleteJson("/api/teacher-checkin/{$id}")->assertOk();

        // Không tính ở đâu: ca trong ngày (không tự sinh lại), bảng tháng, Excel, check-in / báo vắng / sửa / xoá lại
        $this->assertArrayNotHasKey('Thầy Bình', $this->shifts());
        $this->assertEquals(0, $this->monthly()['total_salary']);
        $this->assertStringNotContainsString('Thầy Bình', $this->get('/api/teacher-checkin/export?month=2026-10')->streamedContent());
        $this->postJson('/api/teacher-checkin/checkin', ['shift_id' => $id])->assertNotFound();
        $this->postJson('/api/teacher-checkin/absence', ['shift_id' => $id, 'status' => 'skipped'])->assertNotFound();
        $this->putJson("/api/teacher-checkin/{$id}", ['checkin_time' => '17:30'])->assertNotFound();
        $this->deleteJson("/api/teacher-checkin/{$id}")->assertNotFound();

        // Quản lý không khôi phục được; chủ trung tâm khôi phục → về đúng như trước (giờ check-in, đơn giá đã chốt)
        $owner = auth()->user();
        $manager = User::factory()->create();
        Storage::put('staff_data.json', json_encode(['members' => [['id' => 9, 'name' => 'QL', 'email' => $manager->email, 'role' => 'Quản lý / Lễ tân']]]));
        Sanctum::actingAs($manager);
        $this->postJson("/api/teacher-checkin/{$id}/restore")->assertForbidden();

        Sanctum::actingAs($owner);
        $this->postJson("/api/teacher-checkin/{$id}/restore")->assertOk();
        $m = $this->monthly();
        $this->assertEquals(120000, $m['total_salary']);
        $this->assertSame('17:40', $m['shifts'][0]['checkin_time']);
        $this->assertSame([], $m['deleted_shifts']);
        $this->postJson("/api/teacher-checkin/{$id}/restore")->assertNotFound();
    }

    public function test_manager_can_reset_edit_and_delete_shifts(): void
    {
        $this->student('A', 'Cô Mai', $this->guitar, 'G1');
        $this->student('B', 'Thầy Bình', $this->piano, 'P1');
        $this->setPay([['name' => 'Cô Mai', 'mode' => 'per_shift', 'group' => 0, 'one_on_one' => 100000, 'courses' => []]]);

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
        $this->student('D', 'Cô Mai', $this->guitar, 'G1');
        $this->setPay([
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

    private function mark(Student $st, string $status, string $time = '17:30'): void
    {
        Attendance::create(['student_id' => $st->id, 'course_id' => $st->course_id, 'attended_at' => self::DATE.' '.$time, 'status' => $status]);
    }

    public function test_group_shift_pays_half_when_half_or_more_of_group_size_is_absent(): void
    {
        // Lớp nhóm 4 bạn (sĩ số mặc định 4), ca 1:1 riêng của Thầy Bình
        $group = collect(['A', 'B', 'C', 'D'])->map(fn ($n) => $this->student($n, 'Cô Mai', $this->guitar, 'G1'));
        $solo = $this->student('E', 'Thầy Bình', $this->piano, 'P1', '19:00');
        $this->setPay([
            ['name' => 'Cô Mai', 'mode' => 'per_shift', 'group' => 200000, 'one_on_one' => 0],
            ['name' => 'Thầy Bình', 'mode' => 'per_shift', 'group' => 0, 'one_on_one' => 150000],
        ]);
        $s = $this->shifts();
        $this->postJson('/api/teacher-checkin/checkin', ['shift_id' => $s['Cô Mai']['id']])->assertOk();
        $this->postJson('/api/teacher-checkin/checkin', ['shift_id' => $s['Thầy Bình']['id']])->assertOk();
        $pay = fn () => collect($this->monthly()['shifts'])->keyBy('earner');

        // Chưa điểm danh / vắng 1 bạn: còn 3 bạn > nửa sĩ số → đủ lương
        $this->mark($group[0], 'absent_without_leave');
        $this->assertEquals(200000, $pay()['Cô Mai']['amount']);

        // Vắng 2/4 (có phép hay không đều tính) → 50%
        $this->mark($group[1], 'absent_with_leave');
        $this->assertEquals(100000, $pay()['Cô Mai']['amount']);
        $this->assertSame('2/4 HV đi học · 50%', $pay()['Cô Mai']['pay_note']);

        // 1:1: vắng không phép → 50%, vắng có phép → không tính lương (không có dạy)
        $this->mark($solo, 'absent_without_leave', '19:00');
        $this->assertEquals(75000, $pay()['Thầy Bình']['amount']);
        Attendance::where('student_id', $solo->id)->update(['status' => 'absent_with_leave']);
        $this->assertEquals(0, $pay()['Thầy Bình']['amount']);
    }

    public function test_group_size_is_configurable_per_center(): void
    {
        // Sĩ số lớp nhóm 6: ca có 6 bạn, nghỉ 3 → 50%; nghỉ 2 → đủ lương
        $center = CenterStore::all()[0];
        $this->putJson("/api/centers/{$center['id']}", ['name' => $center['name'], 'group_size' => 6])->assertOk();
        $group = collect(['A', 'B', 'C', 'D', 'E', 'F'])->map(fn ($n) => $this->student($n, 'Cô Mai', $this->guitar, 'G1'));
        $this->setPay([['name' => 'Cô Mai', 'mode' => 'per_shift', 'group' => 300000, 'one_on_one' => 0]]);
        $this->postJson('/api/teacher-checkin/checkin', ['shift_id' => $this->shifts()['Cô Mai']['id']])->assertOk();

        $this->mark($group[0], 'absent_without_leave');
        $this->mark($group[1], 'absent_with_leave');
        $this->assertEquals(300000, $this->monthly()['total_salary']);
        $this->mark($group[2], 'absent_without_leave');
        $this->assertEquals(150000, $this->monthly()['total_salary']);
    }

    public function test_pay_is_set_when_creating_and_editing_teacher_or_academic_staff(): void
    {
        $this->postJson('/api/staff/invites', ['name' => 'Cô Lan', 'email' => 'lan@x.vn', 'role' => 'Giáo vụ', 'center' => 'Tất cả trung tâm',
            'pay_group' => 220000, 'pay_one_on_one' => 160000])->assertCreated();

        $member = collect($this->getJson('/api/staff')->json('members'))->firstWhere('email', 'lan@x.vn');
        $this->assertEquals(['group' => 220000, 'one_on_one' => 160000], array_intersect_key($member['pay'], ['group' => 1, 'one_on_one' => 1]));

        // Giáo vụ có đứng lớp: có trong danh sách giáo viên, ca 1:1 tính theo lương đã đặt
        $this->assertContains('Cô Lan', $this->getJson('/api/teacher-checkin?date='.self::DATE)->json('teachers'));
        $this->putJson("/api/staff/members/{$member['id']}/pay", ['pay_group' => 250000, 'pay_one_on_one' => 180000])->assertOk();
        $this->student('A', 'Cô Lan', $this->piano, 'P1');
        $this->postJson('/api/teacher-checkin/checkin', ['shift_id' => $this->shifts()['Cô Lan']['id']])->assertOk();
        $this->assertEquals(180000, $this->monthly()['total_salary']);

        $this->assertSame(['Giáo viên', 'Giáo vụ', 'Quản lý'], $this->getJson('/api/staff')->json('roles'));
        $this->postJson('/api/staff/invites', ['email' => 'x@x.vn', 'role' => 'Quản lý / Lễ tân', 'center' => 'Tất cả trung tâm'])
            ->assertJsonValidationErrors('role');

        // Giáo viên không xem / sửa được lương
        $this->loginAs('Cô Mai', 'Giáo viên');
        $this->assertArrayNotHasKey('pay', $this->getJson('/api/staff')->json('members.0'));
        $this->putJson('/api/staff/members/9/pay', ['pay_group' => 1, 'pay_one_on_one' => 1])->assertForbidden();
    }

    public function test_expense_tab_includes_monthly_teacher_pay_from_checkins(): void
    {
        $this->student('A', 'Cô Mai', $this->guitar, 'G1');
        $this->student('B', 'Cô Mai', $this->guitar, 'G1');
        $this->student('D', 'Cô Mai', $this->guitar, 'G1');
        $this->student('C', 'Thầy Bình', $this->piano, 'P1', '19:00');
        $this->setPay([
            ['name' => 'Cô Mai', 'group' => 200000, 'one_on_one' => 0],
            ['name' => 'Thầy Bình', 'group' => 0, 'one_on_one' => 150000],
        ]);
        foreach ($this->shifts() as $shift) {
            $this->postJson('/api/teacher-checkin/checkin', ['shift_id' => $shift['id']])->assertOk();
        }
        $center = CenterStore::defaultName();
        $this->postJson('/api/revenue/expenses', ['date' => self::DATE, 'category' => 'Thuê mặt bằng', 'spender' => 'Chủ',
            'content' => 'Tiền nhà T10', 'amount' => 5000000, 'center' => $center])->assertSuccessful();

        $data = $this->getJson('/api/revenue?period=monthly&year=2026&month=10')->assertOk()->json();
        // Cùng số với trang Check-in ca dạy
        $this->assertEquals($this->monthly()['total_salary'], $data['payroll']['total']);
        $this->assertEquals(350000, $data['payroll']['total']);
        $this->assertSame([['Cô Mai', 200000, 1], ['Thầy Bình', 150000, 1]],
            collect($data['payroll']['teachers'])->map(fn ($t) => [$t['name'], (int) $t['amount'], $t['shifts']])->all());
        $this->assertSame('10/2026', $data['payroll']['month']);
        $this->assertEquals([5000000, 350000, 5350000], [$data['profit']['manual_expense'], $data['profit']['payroll_expense'], $data['profit']['total_expense']]);
        $this->assertFalse($data['payroll']['manual_overlap']);

        // Tháng khác không có ca
        $this->assertEquals(0, $this->getJson('/api/revenue?period=monthly&year=2026&month=9')->json('payroll.total'));

        // Còn khoản "Lương giáo viên" nhập tay trước đây → cảnh báo tính hai lần
        $this->postJson('/api/revenue/expenses', ['date' => self::DATE, 'category' => 'Lương giáo viên', 'spender' => 'Chủ',
            'content' => 'Lương T10', 'amount' => 1, 'center' => $center])->assertSuccessful();
        $this->assertTrue($this->getJson('/api/revenue?period=monthly&year=2026&month=10')->json('payroll.manual_overlap'));
    }

    public function test_teacher_only_named_in_schedules_can_get_pay_in_staff_page(): void
    {
        // Thầy Tùng chỉ được gõ tên trong lịch học, chưa có tài khoản ở màn Nhân sự
        $this->student('C', 'Thầy Tùng', $this->piano, 'P1');
        $unlinked = collect($this->getJson('/api/staff')->assertOk()->json('unlinked_teachers'))->keyBy('name');
        $this->assertEquals(['group' => 0, 'one_on_one' => 0], $unlinked['Thầy Tùng']['pay']);

        $this->putJson('/api/staff/teacher-pay', ['name' => 'thầy tùng', 'pay_group' => 180000, 'pay_one_on_one' => 140000])->assertOk();
        $this->putJson('/api/staff/teacher-pay', ['name' => 'Không Có', 'pay_group' => 1, 'pay_one_on_one' => 1])->assertNotFound();
        $this->postJson('/api/teacher-checkin/checkin', ['shift_id' => $this->shifts()['Thầy Tùng']['id']])->assertOk();
        $this->assertEquals(140000, $this->monthly()['total_salary']);

        // Giáo viên khác không đặt được
        $this->loginAs('Cô Mai', 'Giáo viên');
        $this->assertSame([], $this->getJson('/api/staff')->json('unlinked_teachers'));
        $this->putJson('/api/staff/teacher-pay', ['name' => 'Thầy Tùng', 'pay_group' => 1, 'pay_one_on_one' => 1])->assertForbidden();
    }

    public function test_shift_type_follows_course_class_type(): void
    {
        // Hình thức tự nhận theo tên khóa khi tạo
        $group = $this->postJson('/api/courses', ['name' => '[HSSV-NHÓM] 1THÁNG/2BUỔI/ PIANO CƠ BẢN', 'default_sessions' => 8, 'price' => 1])->json('course');
        $solo = $this->postJson('/api/courses', ['name' => '[HSSV-CÁ NHÂN] 1THÁNG/ 1BUỔI/ PIANO NÂNG CAO', 'default_sessions' => 4, 'price' => 1])->json('course');
        $this->assertSame(['group', 'individual'], [$group['class_type'], $solo['class_type']]);
        $this->assertNull(Course::guessClassType('Guitar'));

        // Lớp nhóm chỉ còn 1 bạn vẫn là ca nhóm; 2 bạn học cá nhân cùng giáo viên, giờ, phòng → 2 ca 1:1 riêng
        $this->student('A', 'Cô Mai', Course::find($group['id']), 'G1');
        $this->student('B', 'Thầy Bình', Course::find($solo['id']), 'P1', '19:00');
        $this->student('C', 'Thầy Bình', Course::find($solo['id']), 'P1', '19:00');
        $this->setPay([
            ['name' => 'Cô Mai', 'group' => 200000, 'one_on_one' => 150000],
            ['name' => 'Thầy Bình', 'group' => 90000, 'one_on_one' => 120000],
        ]);

        $shifts = collect($this->getJson('/api/teacher-checkin?date='.self::DATE)->json('today_shifts'));
        $this->assertSame(['Ca nhóm'], $shifts->where('teacher_name', 'Cô Mai')->pluck('type')->all());
        $this->assertSame(['Ca 1-1', 'Ca 1-1'], $shifts->where('teacher_name', 'Thầy Bình')->pluck('type')->values()->all());

        foreach ($shifts as $shift) {
            $this->postJson('/api/teacher-checkin/checkin', ['shift_id' => $shift['id']])->assertOk();
        }
        // Ca nhóm 1 bạn (≤ nửa sĩ số 4) → 50% lương ca nhóm; mỗi ca 1:1 đủ lương 1:1
        $this->assertEquals(100000 + 120000 * 2, $this->monthly()['total_salary']);
    }
}
