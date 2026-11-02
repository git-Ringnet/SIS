<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Invoice;
use App\Models\Student;
use App\Models\User;
use App\Support\BrandSettings;
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
}
