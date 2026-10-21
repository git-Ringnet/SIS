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
 * Thu học phí cộng buổi vào đúng khóa đã đăng ký: trước đây chỉ cộng vào tổng của học viên
 * nên lần điểm danh sau (tổng tính lại từ các khóa) làm mất số buổi đã đóng tiền.
 */
class TuitionSessionsTest extends TestCase
{
    use RefreshDatabase;

    private Course $course;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Carbon::setTestNow('2026-10-19 08:00:00');
        Sanctum::actingAs(User::factory()->create());
        $this->course = Course::create(['code' => 'F1', 'name' => 'Foundation 1', 'price' => 3600000, 'default_sessions' => 12]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function studentWithCourse(): Student
    {
        $id = $this->postJson('/api/students', [
            'name' => 'Võ Minh An', 'gender' => 'Nam', 'parent_name' => 'PH', 'parent_phone' => '0908168815',
            'course_id' => $this->course->id, 'total_sessions' => 12, 'status' => 'active',
            'schedules' => [['day' => 1, 'start' => '15:00', 'end' => '16:00']],
        ])->assertCreated()->json('student.id');
        // Có danh sách khóa đã đăng ký (như học viên thêm khóa ở trang chi tiết)
        $this->putJson("/api/students/{$id}/courses/1", ['total_sessions' => 12])->assertOk();

        return Student::find($id);
    }

    private function item(Student $student): array
    {
        return collect($student->fresh()->registered_courses)->firstWhere('course_id', $this->course->id);
    }

    public function test_renew_adds_sessions_to_course_and_they_survive_attendance(): void
    {
        $student = $this->studentWithCourse();
        $this->assertNotEmpty($student->registered_courses);

        $this->postJson("/api/retention/{$student->id}/status", ['status' => 'renewed', 'renew_sessions' => 12, 'renew_amount' => 3600000])
            ->assertOk();
        $this->assertSame([24, 24], [$this->item($student)['total_sessions'], $student->fresh()->total_sessions]);

        // Điểm danh: tổng tính lại từ các khóa vẫn giữ 24 buổi đã mua
        $this->postJson("/api/attendance/{$student->id}/mark", ['date' => '2026-10-19', 'status' => 'present'])->assertOk();
        $student->refresh();
        $this->assertSame([24, 23], [$student->total_sessions, $student->remaining_sessions]);

        // Học viên đã đóng học phí: danh sách trả phiếu thu gần nhất cho nút "Đã đóng HP"
        $row = collect($this->getJson('/api/students')->json('students'))->firstWhere('id', $student->id);
        $this->assertEquals(3600000, $row['latest_paid_invoice']['amount']);
    }

    public function test_new_student_without_payment_gets_pending_invoice_until_confirmed(): void
    {
        $id = $this->postJson('/api/students', [
            'name' => 'Bé Mới', 'gender' => 'Nam', 'parent_name' => 'PH', 'parent_phone' => '0900001111',
            'course_id' => $this->course->id, 'total_sessions' => 12, 'status' => 'active',
            'create_invoice' => true, 'invoice_amount' => 3600000, 'invoice_paid' => false,
        ])->assertCreated()->json('student.id');

        // Cổng phụ huynh: chưa thanh toán; không tính doanh thu tháng; trang Hóa đơn có trong danh sách chờ thu
        $portal = $this->postJson("/api/portal/students/{$id}/verify", ['phone_last4' => '1111'])->assertOk();
        $this->assertSame('pending', $portal->json('invoices.0.status'));
        $invoices = $this->getJson('/api/invoices?month=2026-10')->assertOk();
        $this->assertEquals(0, $invoices->json('monthlyTotal'));
        $pending = $invoices->json('pending_invoices.0');
        $this->assertSame($id, $pending['student_id']);

        $row = collect($this->getJson('/api/students')->json('students'))->firstWhere('id', $id);
        $this->assertNull($row['latest_paid_invoice']);
        $this->assertSame($pending['id'], $row['pending_invoice']['id']);

        // Xác nhận đã thu: thành đã thanh toán, tính doanh thu, không cộng thêm buổi
        $this->postJson("/api/invoices/{$pending['id']}/mark-paid", ['payment_method' => 'cash', 'paid_at' => '2026-10-19'])->assertOk();
        $this->assertSame('paid', $this->postJson("/api/portal/students/{$id}/verify", ['phone_last4' => '1111'])->json('invoices.0.status'));
        $this->assertEquals(3600000, $this->getJson('/api/invoices?month=2026-10')->json('monthlyTotal'));
        $this->assertSame([12, 12], [Student::find($id)->total_sessions, Student::find($id)->remaining_sessions]);
        $this->postJson("/api/invoices/{$pending['id']}/mark-paid", ['payment_method' => 'cash', 'paid_at' => '2026-10-19'])->assertUnprocessable();
    }

    public function test_wrong_renewal_can_be_cancelled_and_restored(): void
    {
        $student = $this->studentWithCourse();
        $this->postJson("/api/retention/{$student->id}/status", ['status' => 'renewed', 'renew_sessions' => 12, 'renew_amount' => 3600000])->assertOk();
        $invoice = $this->getJson('/api/invoices?month=2026-10')->json('invoices.0');
        $this->assertSame(24, $this->item($student)['total_sessions']);

        // Hủy phiếu gia hạn nhầm: trừ lại 12 buổi, không tính doanh thu, cổng PH không hiện, hết trạng thái Đã đóng
        $this->postJson("/api/invoices/{$invoice['id']}/cancel", ['reason' => 'Gia hạn nhầm'])->assertOk();
        $student->refresh();
        $this->assertSame([12, 12, 'normal'], [$this->item($student)['total_sessions'], $student->remaining_sessions, $student->retention_status]);
        $this->assertEquals(0, $this->getJson('/api/invoices?month=2026-10')->json('monthlyTotal'));
        $this->assertSame([], $this->postJson("/api/portal/students/{$student->id}/verify", ['phone_last4' => '8815'])->json('invoices'));
        $this->postJson("/api/invoices/{$invoice['id']}/cancel")->assertUnprocessable();

        // Khôi phục (hủy nhầm): cộng lại đúng 12 buổi, tính lại doanh thu
        $this->postJson("/api/invoices/{$invoice['id']}/restore")->assertOk();
        $this->assertSame(24, $this->item($student)['total_sessions']);
        $this->assertEquals(3600000, $this->getJson('/api/invoices?month=2026-10')->json('monthlyTotal'));

        // Hủy khi đã học một phần buổi mới: chỉ trừ được phần chưa học, khôi phục cộng lại đúng phần đã trừ
        $student->update(['registered_courses' => collect($student->fresh()->registered_courses)
            ->map(fn ($i) => [...$i, 'attended_sessions' => 20, 'remaining_sessions' => 4])->all(), 'remaining_sessions' => 4]);
        $this->postJson("/api/invoices/{$invoice['id']}/cancel")->assertOk()
            ->assertJsonPath('invoice.sessions_reverted', 4);
        $this->assertSame([20, 0], [$this->item($student)['total_sessions'], $this->item($student)['remaining_sessions']]);
        $this->postJson("/api/invoices/{$invoice['id']}/restore")->assertOk();
        $this->assertSame([24, 4], [$this->item($student)['total_sessions'], $this->item($student)['remaining_sessions']]);

        // Xóa hẳn phiếu đã hủy không trừ buổi lần nữa
        $this->postJson("/api/invoices/{$invoice['id']}/cancel")->assertOk();
        $this->deleteJson("/api/invoices/{$invoice['id']}")->assertOk();
        $this->assertSame(20, $this->item($student)['total_sessions']);
    }

    public function test_wrongly_marked_paid_goes_back_to_pending_keeping_sessions(): void
    {
        $student = $this->studentWithCourse();
        $invoice = $this->postJson('/api/invoices', [
            'student_id' => $student->id, 'course_id' => $this->course->id, 'amount' => 3600000, 'sessions_added' => 12,
            'payment_method' => 'cash', 'paid_at' => '2026-10-19',
        ])->assertCreated()->json('invoice');

        $this->postJson("/api/invoices/{$invoice['id']}/mark-unpaid")->assertOk();
        $data = $this->getJson('/api/invoices?month=2026-10')->json();
        $this->assertEquals(0, $data['monthlyTotal']);
        $this->assertSame($invoice['id'], $data['pending_invoices'][0]['id']);
        $this->assertSame(24, $this->item($student)['total_sessions']); // giữ buổi đã cấp
        $this->postJson("/api/invoices/{$invoice['id']}/mark-unpaid")->assertUnprocessable();
    }

    public function test_invoice_create_and_delete_adjust_the_course_sessions(): void
    {
        $student = $this->studentWithCourse();

        $invoice = $this->postJson('/api/invoices', [
            'student_id' => $student->id, 'course_id' => $this->course->id, 'amount' => 3600000, 'sessions_added' => 12,
            'payment_method' => 'cash', 'paid_at' => '2026-10-19',
        ])->assertCreated()->json('invoice');
        $this->assertSame(24, $this->item($student)['total_sessions']);

        $this->deleteJson("/api/invoices/{$invoice['id']}")->assertOk();
        $this->assertSame(12, $this->item($student)['total_sessions']);
        $this->assertSame(12, $student->fresh()->remaining_sessions);
    }
}
