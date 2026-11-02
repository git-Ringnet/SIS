<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Invoice;
use App\Models\Student;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phiếu thu học phí: cột "Số buổi khóa học" là khoản tiền này cho khóa bao nhiêu buổi, không phải "+n buổi cộng thêm".
 */
class PaymentReceiptTest extends TestCase
{
    use RefreshDatabase;

    private function receipt(Invoice $invoice): string
    {
        return view('pdf.payment-receipt', ['invoice' => $invoice->load(['student', 'course']), 'center' => null, 'brand' => []])->render();
    }

    public function test_receipt_shows_course_sessions_not_added_sessions(): void
    {
        $course = Course::create(['code' => 'P', 'name' => 'Piano cơ bản', 'price' => 2000000, 'default_sessions' => 12]);
        $student = Student::create(['code' => 'HV-104', 'name' => 'Huỳnh Tuấn Khải', 'gender' => 'Nam', 'parent_name' => 'Mẹ Audi',
            'parent_phone' => '0938100384', 'course_id' => $course->id, 'total_sessions' => 8, 'remaining_sessions' => 6, 'status' => 'active']);
        $invoice = fn (int $sessions) => Invoice::create(['invoice_code' => 'INV'.$sessions, 'student_id' => $student->id, 'course_id' => $course->id,
            'amount' => 2000000, 'sessions_added' => $sessions, 'payment_method' => 'bank_transfer', 'status' => 'paid', 'paid_at' => now()]);

        // Phiếu nhập học / gia hạn (có cấp buổi): số buổi của gói, không có dấu "+"
        $html = $this->receipt($invoice(8));
        $this->assertStringContainsString('Số buổi khóa học', $html);
        $this->assertStringContainsString('<strong>8 buổi</strong>', $html);
        $this->assertStringNotContainsString('+8 buổi', $html);

        // Phiếu chỉ thu tiền khóa đang học (không cấp buổi): tổng số buổi của khóa, không hiện "0 buổi"
        $html = $this->receipt($invoice(0));
        $this->assertStringNotContainsString('<strong>0 buổi</strong>', $html);
        $this->assertStringContainsString('<strong>8 buổi</strong>', $html);
    }
}
