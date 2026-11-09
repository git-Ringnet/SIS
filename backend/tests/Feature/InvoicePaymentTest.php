<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Invoice;
use App\Models\Student;
use App\Models\User;
use App\Support\BrandSettings;
use App\Support\PagePermissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Hóa đơn = ghi nhận thanh toán khoản còn nợ (khóa học / học cụ): khoản đã đóng đủ không hiện để chọn,
 * cùng một con số công nợ ở trang Hóa đơn, trang Học viên, Cổng phụ huynh và phiếu thu PDF.
 */
class InvoicePaymentTest extends TestCase
{
    use RefreshDatabase;

    public function test_invoice_pays_course_and_supply_and_every_screen_shows_the_same_debt(): void
    {
        Storage::fake('local');
        Sanctum::actingAs(User::factory()->create());
        $course = Course::create(['code' => 'P', 'name' => 'Piano cơ bản', 'price' => 2000000, 'default_sessions' => 8]);
        $student = Student::create(['code' => 'HV-1', 'name' => 'Bé An', 'gender' => 'Nam', 'parent_name' => 'Mẹ An', 'parent_phone' => '0900001234',
            'course_id' => $course->id, 'total_sessions' => 8, 'remaining_sessions' => 8, 'status' => 'active', 'center' => 'Khoa Anh Music']);
        $item = $this->postJson('/api/inventory', ['name' => 'Sách', 'unit' => 'Cuốn', 'center' => 'Khoa Anh Music', 'cost_price' => 10000, 'sell_price' => 50000, 'quantity' => 5])->json('item.id');
        $supply = $this->postJson("/api/students/{$student->id}/supplies", ['delivery_date' => '2026-10-20', 'center' => 'Khoa Anh Music',
            'items' => [['inventory_id' => $item, 'name' => 'Sách', 'quantity' => 2, 'price' => 50000]]])->json('supplies.0');

        // Còn nợ: học phí 2.000.000 + học cụ 100.000
        $this->assertEquals(2100000, $this->getJson("/api/invoices/payables/{$student->id}")->json('debt'));

        // Hóa đơn: giảm 200.000, thu 1.000.000 học phí + đủ tiền học cụ → còn 800.000 học phí
        $id = $this->postJson('/api/invoices', ['student_id' => $student->id, 'payment_method' => 'cash', 'paid_at' => '2026-10-20', 'due_date' => '2026-11-15',
            'lines' => [['key' => 'course:1', 'amount' => 1000000, 'discount' => 200000], ['key' => "supply:{$supply['id']}", 'amount' => 100000]]])
            ->assertCreated()->assertJsonPath('invoice.amount', '1100000')->json('invoice.id');

        $payables = $this->getJson("/api/invoices/payables/{$student->id}")->json();
        $this->assertEquals([800000, 0, 800000], [$payables['courses'][0]['debt'], $payables['supplies'][0]['debt'], $payables['debt']]);
        // Học cụ đã đóng đủ: không chọn lại được
        $this->postJson('/api/invoices', ['student_id' => $student->id, 'payment_method' => 'cash', 'paid_at' => '2026-10-21',
            'lines' => [['key' => "supply:{$supply['id']}", 'amount' => 1000]]])->assertUnprocessable();

        // Trang Học viên: nút Học phí theo công nợ
        $row = collect($this->getJson('/api/students')->json('students'))->firstWhere('id', $student->id);
        $this->assertEquals(800000, $row['tuition']['debt']);
        $this->assertSame([$student->id], collect($this->getJson('/api/students?tuition_status=unpaid')->json('students'))->pluck('id')->all());

        // Cổng phụ huynh: còn cần thanh toán 800.000, hóa đơn ghi các khoản đã thu
        $portal = $this->postJson("/api/portal/students/{$student->id}/verify", ['phone_last4' => '1234'])->json();
        $this->assertEquals(800000, $portal['tuition']['debt']);
        $this->assertSame(['Piano cơ bản', 'Sách'], $portal['invoices'][0]['items']);
        $this->assertSame(Invoice::find($id)->invoice_code, $portal['invoices'][0]['code']);

        // Phiếu thu PDF: từng khoản và số còn phải thanh toán
        $html = view('pdf.payment-receipt', ['invoice' => Invoice::find($id), 'center' => null, 'brand' => BrandSettings::brand()])->render();
        $this->assertStringContainsString('Giảm giá 200.000', $html);
        $this->assertStringContainsString('Còn phải thanh toán', $html);
        $this->assertStringContainsString('800.000 ₫', $html);
        $this->get("/api/invoices/{$id}/pdf")->assertOk();

        // Doanh thu thực tế tách học phí / học cụ
        $revenue = $this->getJson('/api/revenue?month=10&year=2026')->json('metrics');
        $this->assertEquals([1000000, 100000], [$revenue['course_total'], $revenue['inventory_total']]);
        $this->assertEquals(800000, $revenue['receivable_total']);
    }

    private function studentOnCourse(int $price = 2000000): Student
    {
        $course = Course::create(['code' => 'P', 'name' => 'Piano cơ bản', 'price' => $price, 'default_sessions' => 8]);

        return Student::create(['code' => 'HV-1', 'name' => 'Bé An', 'gender' => 'Nam', 'parent_name' => 'Mẹ An', 'parent_phone' => '0900001234',
            'course_id' => $course->id, 'total_sessions' => 8, 'remaining_sessions' => 8, 'status' => 'active', 'center' => 'Khoa Anh Music']);
    }

    private function legacyInvoice(Student $student, int $amount, string $status): Invoice
    {
        return Invoice::create(['invoice_code' => 'PT-'.$status, 'student_id' => $student->id, 'course_id' => $student->course_id, 'center' => $student->center,
            'amount' => $amount, 'sessions_added' => 8, 'payment_method' => 'cash', 'status' => $status, 'paid_at' => '2026-10-20', 'recorded_by' => 'Admin']);
    }

    public function test_course_fee_can_be_set_for_one_student_without_touching_the_course_price(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $student = $this->studentOnCourse();

        // Giá khóa 2.000.000, học viên này thực tế 2.500.000 (cao hơn giá khóa vẫn sửa được)
        $res = $this->putJson("/api/students/{$student->id}/courses/1", ['tuition_fee' => 2500000])->assertOk();
        $this->assertEquals([2500000, 0, 2500000], [$res->json('student.tuition.courses.0.fee'), $res->json('student.tuition.courses.0.paid'), $res->json('student.tuition.courses.0.debt')]);
        $this->assertEquals(2000000, Course::find($student->course_id)->price);

        // Thu một phần theo học phí mới: biết đã đóng bao nhiêu, còn nợ bao nhiêu
        $this->postJson('/api/invoices', ['student_id' => $student->id, 'payment_method' => 'cash', 'paid_at' => '2026-10-20',
            'lines' => [['key' => 'course:1', 'amount' => 1000000]]])->assertCreated();
        $row = $this->getJson("/api/invoices/payables/{$student->id}")->json('courses.0');
        $this->assertEquals([2500000, 1000000, 1500000], [$row['fee'], $row['paid'], $row['debt']]);

        // Để trống: quay về giá khóa
        $res = $this->putJson("/api/students/{$student->id}/courses/1", ['tuition_fee' => null])->assertOk();
        $this->assertEquals(2000000, $res->json('student.tuition.courses.0.fee'));
        $this->assertArrayNotHasKey('tuition_fee', $res->json('student.registered_courses.0'));
    }

    public function test_course_fee_overrides_legacy_invoice_total_and_keeps_it_as_paid(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $student = $this->studentOnCourse();
        $this->legacyInvoice($student, 1500000, 'paid');

        $row = $this->putJson("/api/students/{$student->id}/courses/1", ['tuition_fee' => 1800000])->assertOk()->json('student.tuition.courses.0');

        $this->assertEquals([1800000, 1500000, 300000], [$row['fee'], $row['paid'], $row['debt']]);
    }

    public function test_course_fee_cannot_go_below_what_was_already_collected(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $student = $this->studentOnCourse();
        $this->postJson('/api/invoices', ['student_id' => $student->id, 'payment_method' => 'cash', 'paid_at' => '2026-10-20',
            'lines' => [['key' => 'course:1', 'amount' => 1000000, 'discount' => 200000]]])->assertCreated();

        $this->putJson("/api/students/{$student->id}/courses/1", ['tuition_fee' => 1100000])->assertUnprocessable();
        $this->putJson("/api/students/{$student->id}/courses/1", ['tuition_fee' => 1200000])->assertOk();
    }

    public function test_course_fee_is_refused_while_a_legacy_pending_invoice_is_open(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $student = $this->studentOnCourse();
        $this->legacyInvoice($student, 1500000, 'pending');

        $this->putJson("/api/students/{$student->id}/courses/1", ['tuition_fee' => 1800000])
            ->assertUnprocessable()->assertJsonFragment(['message' => 'Khóa Piano cơ bản đang có phiếu chờ thu PT-pending: hãy sửa số tiền của phiếu đó ở trang Hóa đơn.']);
    }

    public function test_staff_blocked_from_invoices_cannot_change_course_fee_but_can_edit_the_course(): void
    {
        Storage::fake('local');
        $student = $this->studentOnCourse();
        User::factory()->create(); // tài khoản đầu tiên luôn là chủ trung tâm
        $user = User::factory()->create(['name' => 'Giáo vụ']);
        Storage::put('staff_data.json', json_encode(['members' => [[
            'id' => 9, 'name' => 'Giáo vụ', 'email' => $user->email, 'role' => 'Giáo vụ',
            'blocked_pages' => PagePermissions::defaultBlockedPages('Giáo vụ'),
        ]]]));
        Sanctum::actingAs($user);

        $this->putJson("/api/students/{$student->id}/courses/1", ['tuition_fee' => 2500000])->assertForbidden();
        $this->putJson("/api/students/{$student->id}/courses/1", ['allowed_absences' => 5])->assertOk()->assertJsonPath('student.tuition.courses.0.fee', 2000000);
    }

    public function test_changing_to_another_course_drops_the_custom_fee(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $student = $this->studentOnCourse();
        $other = Course::create(['code' => 'G', 'name' => 'Guitar', 'price' => 3000000, 'default_sessions' => 8]);
        $this->putJson("/api/students/{$student->id}/courses/1", ['tuition_fee' => 2500000])->assertOk();

        $this->putJson("/api/students/{$student->id}/courses/1", ['course_id' => $student->course_id])->assertOk()->assertJsonPath('student.tuition.courses.0.fee', 2500000);
        $this->putJson("/api/students/{$student->id}/courses/1", ['course_id' => $other->id])->assertOk()->assertJsonPath('student.tuition.courses.0.fee', 3000000);
    }

    private function changeCoursePrice(Course $course, int $price): void
    {
        $this->putJson("/api/courses/{$course->id}", ['name' => $course->name, 'default_sessions' => $course->default_sessions, 'price' => $price])->assertOk();
    }

    private function payCourse(Student $student, int $amount): void
    {
        $this->postJson('/api/invoices', ['student_id' => $student->id, 'payment_method' => 'cash', 'paid_at' => '2026-10-20',
            'lines' => [['key' => 'course:1', 'amount' => $amount]]])->assertCreated();
    }

    /** @return array{0: float, 1: float, 2: float} học phí, đã đóng, còn nợ */
    private function courseFigures(Student $student): array
    {
        $row = $this->getJson("/api/invoices/payables/{$student->id}")->json('courses.0');

        return [$row['fee'], $row['paid'], $row['debt']];
    }

    public function test_changing_course_price_keeps_old_price_for_students_who_already_paid(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $fullyPaid = $this->studentOnCourse();
        $course = Course::find($fullyPaid->course_id);
        $another = fn (string $code) => Student::create(['code' => $code, 'name' => $code, 'gender' => 'Nam', 'parent_name' => 'PH', 'parent_phone' => '0900001234',
            'course_id' => $course->id, 'total_sessions' => 8, 'remaining_sessions' => 8, 'status' => 'active', 'center' => 'Khoa Anh Music']);
        $partlyPaid = $another('HV-2');
        $unpaid = $another('HV-3');
        $this->payCourse($fullyPaid, 2000000);
        $this->payCourse($partlyPaid, 500000);

        $this->changeCoursePrice($course, 2500000);

        $this->assertEquals([2000000, 2000000, 0], $this->courseFigures($fullyPaid));
        $this->assertEquals([2000000, 500000, 1500000], $this->courseFigures($partlyPaid));
        $this->assertEquals([2500000, 0, 2500000], $this->courseFigures($unpaid));
        $this->assertNull($unpaid->fresh()->registered_courses);

        // Đổi giá lần nữa: người đã chốt vẫn giữ giá lúc đóng tiền
        $this->changeCoursePrice($course->fresh(), 1500000);
        $this->assertEquals([2000000, 2000000, 0], $this->courseFigures($fullyPaid));
        $this->assertEquals([1500000, 0, 1500000], $this->courseFigures($unpaid));
    }

    public function test_changing_course_price_leaves_custom_fees_and_legacy_invoices_alone(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $custom = $this->studentOnCourse();
        $course = Course::find($custom->course_id);
        $this->putJson("/api/students/{$custom->id}/courses/1", ['tuition_fee' => 1800000])->assertOk();
        $this->payCourse($custom, 1800000);
        $legacy = Student::create(['code' => 'HV-2', 'name' => 'HV-2', 'gender' => 'Nam', 'parent_name' => 'PH', 'parent_phone' => '0900001234',
            'course_id' => $course->id, 'total_sessions' => 8, 'remaining_sessions' => 8, 'status' => 'active', 'center' => 'Khoa Anh Music']);
        $this->legacyInvoice($legacy, 1500000, 'paid');

        $this->changeCoursePrice($course, 2500000);

        $this->assertEquals([1800000, 1800000, 0], $this->courseFigures($custom));
        $this->assertEquals([1500000, 1500000, 0], $this->courseFigures($legacy));
        $this->assertArrayNotHasKey('tuition_fee', $legacy->fresh()->registered_courses_list[0]);
    }

    public function test_editing_a_course_without_changing_its_price_freezes_nothing(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $student = $this->studentOnCourse();
        $this->payCourse($student, 500000);

        $this->changeCoursePrice(Course::find($student->course_id), 2000000);

        $this->assertArrayNotHasKey('tuition_fee', $student->fresh()->registered_courses_list[0]);
    }
}
