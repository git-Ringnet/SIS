<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Course;
use App\Models\Invoice;
use App\Models\Student;
use App\Models\User;
use App\Support\StudentSchedule;
use App\Support\Tuition;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Thêm khóa: "học tiếp" (gia hạn = khóa mới dùng lại ca cũ, lịch nối sau khóa cũ) và "học song song" (ca riêng).
 * Không đổi ngày bắt đầu học / ngày kết thúc của khóa đầu tiên.
 */
class CourseContinuationTest extends TestCase
{
    use RefreshDatabase;

    private Course $piano;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Carbon::setTestNow('2026-10-19 08:00:00'); // Thứ 2
        Sanctum::actingAs(User::factory()->create());
        $this->piano = Course::create(['code' => 'P', 'name' => 'Piano', 'price' => 2000000, 'default_sessions' => 8]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** Học T2 + T5 17:00 (Piano, 8 buổi từ 19/10) và T7 09:00 (ca trống cho môn học thêm). */
    private function student(): Student
    {
        $id = $this->postJson('/api/students', [
            'name' => 'Bé An', 'gender' => 'Nam', 'parent_name' => 'PH', 'parent_phone' => '0900001234',
            'course_id' => $this->piano->id, 'total_sessions' => 8, 'status' => 'active', 'join_date' => '2026-10-19',
            'schedules' => [
                ['day' => 1, 'start' => '17:00', 'end' => '18:00', 'teacher' => 'Cô Mai'],
                ['day' => 4, 'start' => '17:00', 'end' => '18:00', 'teacher' => 'Cô Mai'],
            ],
        ])->assertCreated()->json('student.id');
        $this->putJson("/api/students/{$id}/courses/1", ['total_sessions' => 8])->assertOk();

        return Student::find($id);
    }

    private function portal(Student $student): array
    {
        return $this->postJson("/api/portal/students/{$student->id}/verify", ['phone_last4' => '1234'])->assertOk()->json();
    }

    public function test_continue_course_starts_after_the_current_one_and_keeps_first_course_dates(): void
    {
        $student = $this->student();
        $this->postJson("/api/attendance/{$student->id}/mark", ['date' => '2026-10-19', 'status' => 'present'])->assertOk();

        // Khóa đang học còn 7 buổi: 22, 26, 29/10, 02, 05, 09, 12/11 → gợi ý học tiếp từ T2 16/11
        $this->assertSame('2026-11-16', $this->getJson("/api/students/{$student->id}/courses/suggest-start?continue_from=1")->json('start_date'));
        $this->postJson("/api/students/{$student->id}/courses", ['course_id' => $this->piano->id, 'total_sessions' => 8, 'mode' => 'continue', 'continue_from' => 1])
            ->assertOk()->assertJsonPath('course_item_id', 2);

        $student->refresh();
        $item2 = collect($student->registered_courses)->firstWhere('id', 2);
        $this->assertSame(['2026-11-16', 1, 'continue'], [$item2['start_date'], $item2['continues'], $item2['mode']]);
        // Ngày bắt đầu học / kết thúc của khóa đầu không bị đổi
        $this->assertSame('2026-10-19', $student->join_date->toDateString());
        $courses = collect($this->portal($student)['courses'])->keyBy('course_item_id');
        $this->assertSame(['19/10/2026', '12/11/2026'], [$courses[1]['start'], $courses[1]['end']]);
        $this->assertSame(['16/11/2026', '10/12/2026'], [$courses[2]['start'], $courses[2]['end']]);

        // Cổng PH: khóa đang học là khóa 1; buổi dự kiến khóa 2 nối sau 12/11, đánh số lại từ Buổi 1
        $portal = $this->portal($student);
        $this->assertSame(1, $portal['current_course_item_id']);
        $next = collect($portal['sessions'])->where('status', 'upcoming')->where('course_item_id', 2)->values();
        $this->assertSame(['16/11/2026', 1], [$next[0]['date'], $next[0]['session_number']]);
        $this->assertCount(8, $next);
        $this->assertSame(8, $portal['courses'][1]['stats']['remaining_sessions']);

        // Trung tâm nghỉ 22/10: khóa 1 lùi tới 16/11 → khóa 2 tự nối từ 19/11 và trang Học viên cảnh báo cập nhật ngày bắt đầu
        $this->postJson('/api/teacher-checkin/center-offs', ['from' => '2026-10-22', 'to' => '2026-10-22'])->assertCreated();
        $next = collect($this->portal($student)['sessions'])->where('status', 'upcoming')->where('course_item_id', 2)->values();
        $this->assertSame('19/11/2026', $next[0]['date']);
        $row = collect($this->getJson('/api/students')->json('students'))->firstWhere('id', $student->id);
        $this->assertStringContainsString('16/11/2026', $row['course_warnings'][2]);

        // Điểm danh trừ khóa 1 tới khi hết buổi rồi mới sang khóa 2
        $this->assertSame(1, StudentSchedule::courseItemForSlot($student->fresh(), $student->schedules[0]['id'])['id']);
        $student->update(['registered_courses' => collect($student->fresh()->registered_courses)
            ->map(fn ($i) => $i['id'] === 1 ? [...$i, 'attended_sessions' => 8, 'remaining_sessions' => 0, 'status' => 'completed'] : $i)->all()]);
        Carbon::setTestNow('2026-11-19 18:00:00');
        $this->postJson("/api/attendance/{$student->id}/mark", ['date' => '2026-11-19', 'status' => 'present'])->assertOk();
        $this->assertSame(2, Attendance::whereDate('attended_at', '2026-11-19')->value('course_item_id'));
        $this->assertSame(2, $this->portal($student)['current_course_item_id']);
    }

    public function test_staff_without_invoice_page_cannot_record_payment_when_adding_course_or_student(): void
    {
        $student = $this->student();
        // Giáo vụ: bị chặn trang "Hóa đơn & Thu phí" ở màn Nhân sự
        $giaoVu = User::factory()->create(['email' => 'giaovu@test.vn']);
        Storage::put('staff_data.json', json_encode(['members' => [[
            'name' => 'Như Giáo Vụ', 'email' => 'giaovu@test.vn', 'role' => 'Giáo vụ', 'blocked_pages' => ['Hóa đơn & Thu phí', 'Doanh thu'],
        ]]]));
        Sanctum::actingAs($giaoVu);

        // Thêm khóa kèm số tiền đã thu → không có quyền; không kèm tiền → thêm được, khóa mới còn nợ
        $this->postJson("/api/students/{$student->id}/courses", ['course_id' => $this->piano->id, 'total_sessions' => 8, 'mode' => 'continue', 'paid_amount' => 500000])
            ->assertForbidden();
        $this->postJson("/api/students/{$student->id}/courses", ['course_id' => $this->piano->id, 'total_sessions' => 8, 'mode' => 'continue'])->assertOk();
        $this->assertSame(0, Invoice::count());

        // Thêm học viên chọn "Đã đóng học phí": không lập hóa đơn, khóa hiện còn nợ
        $id = $this->postJson('/api/students', ['name' => 'Bé Mới', 'gender' => 'Nam', 'parent_name' => 'PH', 'parent_phone' => '0900009999',
            'course_id' => $this->piano->id, 'total_sessions' => 8, 'status' => 'active', 'create_invoice' => true, 'invoice_amount' => 2000000, 'invoice_paid' => true])
            ->assertCreated()->json('student.id');
        $this->assertSame(0, Invoice::count());
        $this->assertEquals(2000000, Tuition::summary(Student::find($id))['debt']);
    }

    public function test_parallel_course_needs_its_own_slot_and_payment_creates_invoice(): void
    {
        $student = $this->student();
        $guitar = Course::create(['code' => 'G', 'name' => 'Guitar', 'price' => 1500000, 'default_sessions' => 8]);
        // Thêm ca T7 09:00 cho môn học thêm
        $schedules = [...$student->schedules, ['day' => 6, 'start' => '09:00', 'end' => '10:00', 'teacher' => 'Thầy Khoa']];
        $this->putJson("/api/students/{$student->id}", [...$student->only(['name', 'gender', 'parent_name', 'parent_phone', 'course_id', 'total_sessions', 'remaining_sessions', 'status', 'retention_status']),
            'schedules' => $schedules])->assertOk();
        $student->refresh();
        $mon = $student->schedules[0]['id'];
        $sat = collect($student->schedules)->firstWhere('day', 6)['id'];

        // Ca T7 đang là ca chung của khóa 1 nhưng chưa ghi riêng: học song song với ca T7, đóng trước 1.000.000
        $this->postJson("/api/students/{$student->id}/courses", ['course_id' => $guitar->id, 'total_sessions' => 8, 'mode' => 'parallel',
            'schedule_ids' => [$sat], 'start_date' => '2026-10-24', 'paid_amount' => 1000000, 'payment_method' => 'cash'])->assertOk();
        $student->refresh();
        $items = collect($student->registered_courses)->keyBy('id');
        $this->assertSame([$sat], $items[2]['schedule_ids']);
        $this->assertNotContains($sat, $items[1]['schedule_ids']);
        $this->assertSame($this->piano->id, $student->course_id); // khóa chính giữ nguyên
        $this->assertSame('2026-10-19', $student->join_date->toDateString());

        // Ca đã thuộc khóa khác thì không gán cho khóa song song
        $this->postJson("/api/students/{$student->id}/courses", ['course_id' => $guitar->id, 'total_sessions' => 8, 'mode' => 'parallel', 'schedule_ids' => [$mon]])
            ->assertUnprocessable();

        // Hóa đơn khóa thêm đã thu 1.000.000 / 1.500.000
        $payables = $this->getJson("/api/invoices/payables/{$student->id}")->json('courses');
        $this->assertEquals([2000000, 500000], array_column($payables, 'debt')); // Piano chưa đóng, Guitar còn 500.000

        // T7 24/10 trừ buổi khóa Guitar; T2 26/10 trừ khóa Piano
        Carbon::setTestNow('2026-10-26 18:00:00');
        $this->postJson("/api/attendance/{$student->id}/mark", ['date' => '2026-10-24', 'status' => 'present', 'slot_id' => $sat])->assertOk();
        $this->postJson("/api/attendance/{$student->id}/mark", ['date' => '2026-10-26', 'status' => 'present', 'slot_id' => $mon])->assertOk();
        $this->assertSame([2, 1], Attendance::orderBy('attended_at')->pluck('course_item_id')->all());

        // Học song song thêm môn mới với ca mới nhập ngay trong form (CN 08:00, Thầy Khoa): thêm vào lịch học và gán cho khóa
        $drum = Course::create(['code' => 'D', 'name' => 'Trống', 'price' => 1000000, 'default_sessions' => 8]);
        $this->postJson("/api/students/{$student->id}/courses", ['course_id' => $drum->id, 'total_sessions' => 8, 'mode' => 'parallel',
            'new_slots' => [['day' => 0, 'start' => '08:00', 'end' => '09:00', 'teacher' => 'Thầy Khoa', 'room' => 'P2']]])->assertOk();
        $student->refresh();
        $sunday = collect($student->schedules)->firstWhere('day', 0);
        $this->assertSame(['08:00', 'Thầy Khoa', 'P2'], [$sunday['start'], $sunday['teacher'], $sunday['room']]);
        $this->assertSame([$sunday['id']], collect($student->registered_courses)->firstWhere('id', 3)['schedule_ids']);
        $this->assertContains(0, $student->class_days);
        // Trùng ca đã có (CN 08:00) / không chọn ca nào → báo lỗi
        $this->postJson("/api/students/{$student->id}/courses", ['course_id' => $drum->id, 'total_sessions' => 8, 'mode' => 'parallel',
            'new_slots' => [['day' => 0, 'start' => '08:00']]])->assertUnprocessable();
        $this->postJson("/api/students/{$student->id}/courses", ['course_id' => $drum->id, 'total_sessions' => 8, 'mode' => 'parallel'])->assertUnprocessable();

        // Cổng PH: 2 khóa song song đều đang học, mỗi khóa số liệu riêng
        $courses = collect($this->portal($student)['courses'])->keyBy('course_item_id');
        $this->assertSame([7, 7], [$courses[1]['stats']['remaining_sessions'], $courses[2]['stats']['remaining_sessions']]);
        $this->assertSame([1, 1], [$courses[1]['stats']['completed_sessions'], $courses[2]['stats']['completed_sessions']]);
    }
}
