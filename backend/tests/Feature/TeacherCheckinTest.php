<?php

namespace Tests\Feature;

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

class TeacherCheckinTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Sanctum::actingAs(User::factory()->create());

        // Thứ 3, 20/10/2026 — bé học Thứ 3 lúc 17:30 với Cô Mai
        Carbon::setTestNow('2026-10-20 17:00:00');
        $course = Course::create(['code' => 'P', 'name' => 'Piano', 'price' => 1, 'default_sessions' => 12]);
        Student::create([
            'code' => 'HV-1', 'name' => 'Bé An', 'gender' => 'Nam', 'parent_name' => 'PH', 'parent_phone' => '09',
            'course_id' => $course->id, 'total_sessions' => 12, 'remaining_sessions' => 12, 'status' => 'active',
            'class_days' => [2], 'class_start_time' => '17:30', 'class_end_time' => '18:30', 'teacher' => 'Cô Mai',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function shiftId(): int
    {
        return $this->getJson('/api/teacher-checkin?date=2026-10-20')->assertOk()->json('today_shifts.0.id');
    }

    public function test_checkin_before_start_is_on_time(): void
    {
        Carbon::setTestNow('2026-10-20 17:25:00');
        $id = $this->shiftId();

        $this->postJson('/api/teacher-checkin/checkin', ['shift_id' => $id])->assertOk();

        $this->getJson('/api/teacher-checkin?date=2026-10-20')
            ->assertJsonPath('today_shifts.0.status', 'checked_in')
            ->assertJsonPath('today_shifts.0.checkin_time', '17:25')
            ->assertJsonPath('today_shifts.0.minutes_late', 0);
    }

    /**
     * Thêm ca của giáo viên khác cùng ngày và đăng nhập bằng tài khoản giáo viên "Cô Mai".
     */
    private function actAsTeacherMai(): User
    {
        Student::create([
            'code' => 'HV-2', 'name' => 'Bé Bình', 'gender' => 'Nam', 'parent_name' => 'PH', 'parent_phone' => '09',
            'course_id' => Course::first()->id, 'total_sessions' => 12, 'remaining_sessions' => 12, 'status' => 'active',
            'class_days' => [2], 'class_start_time' => '17:30', 'class_end_time' => '18:30', 'teacher' => 'Thầy Bình',
        ]);

        $teacher = User::factory()->create(['name' => 'Cô Mai']);
        Storage::put('staff_data.json', json_encode(['members' => [[
            'id' => 2, 'name' => 'cô  mai', 'email' => $teacher->email, 'role' => 'Giáo viên',
            'blocked_pages' => PagePermissions::defaultBlockedPages('Giáo viên'), 'is_owner' => false,
        ]]]));
        Sanctum::actingAs($teacher);

        return $teacher;
    }

    public function test_teacher_sees_and_checks_in_only_own_shift_today(): void
    {
        $this->actAsTeacherMai();

        // Chỉ thấy ca của mình (khớp tên không phân biệt hoa thường / khoảng trắng)
        $this->getJson('/api/teacher-checkin?date=2026-10-20')
            ->assertJsonCount(1, 'today_shifts')
            ->assertJsonPath('today_shifts.0.teacher_name', 'Cô Mai');

        // Ca của cả hai giáo viên đã được sinh trong dữ liệu chấm công
        $ids = collect(json_decode(Storage::get('teacher_checkins.json'), true))->pluck('id', 'teacher_name');

        $qr = ['qr_center' => CenterStore::defaultName(), 'qr_token' => CheckinToken::current(CenterStore::defaultName())['token']];
        $this->postJson('/api/teacher-checkin/checkin', ['shift_id' => $ids['Thầy Bình'], ...$qr])->assertForbidden();
        $this->postJson('/api/teacher-checkin/checkin', ['shift_id' => $ids['Cô Mai'], ...$qr])->assertOk();
        $this->postJson('/api/teacher-checkin/checkin', ['shift_id' => $ids['Cô Mai'], ...$qr])->assertUnprocessable();

        $this->getJson('/api/teacher-checkin?date=2026-10-20')->assertJsonPath('today_shifts.0.checked_in_by', 'Cô Mai');
        $this->postJson('/api/teacher-checkin/absence', ['shift_id' => $ids['Cô Mai'], 'status' => 'skipped'])->assertForbidden();
        $this->postJson('/api/teacher-checkin/rates', ['one_on_one' => 1, 'group' => 1, 'trial' => 1])->assertForbidden();
    }

    public function test_teacher_must_scan_a_valid_qr_of_the_right_center(): void
    {
        $this->actAsTeacherMai();
        $this->getJson('/api/teacher-checkin?date=2026-10-20');
        $id = collect(json_decode(Storage::get('teacher_checkins.json'), true))->firstWhere('teacher_name', 'Cô Mai')['id'];
        $center = CenterStore::defaultName();

        // Không có mã / mã sai / mã của cơ sở khác
        $this->postJson('/api/teacher-checkin/checkin', ['shift_id' => $id])->assertForbidden();
        $this->postJson('/api/teacher-checkin/checkin', ['shift_id' => $id, 'qr_center' => $center, 'qr_token' => 'abc'])->assertForbidden();
        $this->postJson('/api/teacher-checkin/checkin', ['shift_id' => $id, 'qr_center' => 'Cơ sở khác',
            'qr_token' => CheckinToken::current('Cơ sở khác')['token']])->assertForbidden();

        // Mã chụp lại từ 2 phút trước đã hết hạn
        $old = CheckinToken::current($center)['token'];
        Carbon::setTestNow(now()->addMinutes(2));
        $this->postJson('/api/teacher-checkin/checkin', ['shift_id' => $id, 'qr_center' => $center, 'qr_token' => $old])->assertForbidden();

        // Mã đang hiện (hoặc vừa đổi trong vòng ~1 phút) thì được
        $fresh = CheckinToken::current($center)['token'];
        Carbon::setTestNow(now()->addSeconds(40));
        $this->postJson('/api/teacher-checkin/checkin', ['shift_id' => $id, 'qr_center' => $center, 'qr_token' => $fresh])->assertOk();

        // Giáo viên không lấy được mã QR
        $this->getJson('/api/teacher-checkin/qr-token')->assertForbidden();
    }

    public function test_teacher_default_blocks_everything_but_attendance_and_checkin(): void
    {
        $this->actAsTeacherMai();

        $this->getJson('/api/dashboard')->assertForbidden();
        $this->getJson('/api/revenue')->assertForbidden();
        $this->getJson('/api/birthdays')->assertForbidden();
        $this->getJson('/api/attendance?date=2026-10-20')->assertOk();
        $this->getJson('/api/teacher-checkin')->assertOk();
        $this->getJson('/api/auth/me')->assertOk()->assertJsonPath('user.is_teacher', true);
    }

    public function test_checkin_within_grace_is_on_time_and_after_grace_is_late(): void
    {
        $id = $this->shiftId();

        Carbon::setTestNow('2026-10-20 17:42:00');
        $this->postJson('/api/teacher-checkin/checkin', ['shift_id' => $id])
            ->assertOk()->assertJsonPath('message', 'Đã check-in Cô Mai lúc 17:42, trễ 12 phút.');

        $this->getJson('/api/teacher-checkin?date=2026-10-20')
            ->assertJsonPath('today_shifts.0.status', 'late')
            ->assertJsonPath('today_shifts.0.minutes_late', 12);
    }
}
