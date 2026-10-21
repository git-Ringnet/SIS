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

class RemainingPagesTest extends TestCase
{
    use RefreshDatabase;

    private Course $course;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Carbon::setTestNow('2026-10-20 08:00:00');
        Sanctum::actingAs(User::factory()->create(['name' => 'Lễ tân']));
        $this->course = Course::create(['code' => 'G', 'name' => 'Guitar', 'price' => 1, 'default_sessions' => 12]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function student(string $name, string $center = 'Cơ sở A', array $extra = []): Student
    {
        return Student::create([
            'code' => 'HV-'.$name, 'name' => $name, 'gender' => 'Nam', 'parent_name' => 'PH', 'parent_phone' => '09',
            'course_id' => $this->course->id, 'total_sessions' => 12, 'remaining_sessions' => 12, 'status' => 'active',
            'center' => $center, ...$extra,
        ]);
    }

    private function csv(string $uri): string
    {
        return $this->get($uri)->assertOk()->streamedContent();
    }

    public function test_export_uses_real_data_and_center_filter(): void
    {
        $this->student('An', 'Cơ sở A');
        $this->student('Cường', 'Cơ sở B');
        $this->postJson('/api/inventory', ['name' => 'Capo', 'unit' => 'Cái', 'center' => 'Cơ sở B', 'cost_price' => 5, 'sell_price' => 10, 'quantity' => 3])->assertCreated();

        $supplies = $this->csv('/api/export/supplies');
        $this->assertStringContainsString('Capo', $supplies);
        $this->assertStringNotContainsString('Alfred', $supplies); // không còn dữ liệu mẫu cứng
        $this->assertStringNotContainsString('Capo', $this->csv('/api/export/supplies?center='.urlencode('Cơ sở A')));

        $students = $this->csv('/api/export/students?center='.urlencode('Cơ sở A'));
        $this->assertStringContainsString('An', $students);
        $this->assertStringNotContainsString('Cường', $students);
    }

    public function test_attendance_export_has_status_and_note(): void
    {
        $student = $this->student('An');
        $this->postJson("/api/attendance/{$student->id}/mark", ['date' => '2026-10-19', 'status' => 'absent_with_leave', 'note' => 'Bé ốm'])->assertOk();

        $csv = $this->csv('/api/export/attendance');
        $this->assertStringContainsString('Vắng có phép', $csv);
        $this->assertStringContainsString('Bé ốm', $csv);
    }

    public function test_giving_supplies_deducts_stock_and_records_revenue(): void
    {
        $student = $this->student('An');
        $id = $this->postJson('/api/inventory', ['name' => 'Capo', 'unit' => 'Cái', 'center' => 'Cơ sở A', 'cost_price' => 50000, 'sell_price' => 95000, 'quantity' => 5])->json('item.id');

        $this->postJson("/api/students/{$student->id}/supplies", [
            'delivery_date' => '2026-10-20', 'center' => 'Cơ sở A',
            'items' => [['inventory_id' => $id, 'name' => 'Capo', 'quantity' => 2, 'price' => 95000]],
        ])->assertOk();

        $this->assertSame(3, collect($this->getJson('/api/inventory')->json('items'))->firstWhere('id', $id)['quantity']);
        $revenue = $this->getJson('/api/revenue?type=inventory')->assertOk();
        $this->assertEquals(190000, $revenue->json('metrics.inventory_total'));
        $this->assertSame('An', $revenue->json('transactions.0.student_name'));

        // Bán lẻ từ kho cũng ghi doanh thu
        $this->postJson("/api/inventory/{$id}/adjust", ['delta' => -1, 'reason' => 'Bán trực tiếp cho học viên'])->assertOk();
        $this->assertEquals(285000, $this->getJson('/api/revenue?type=inventory')->json('metrics.inventory_total'));
    }

    public function test_returning_wrongly_given_supplies_restocks_and_reduces_revenue(): void
    {
        $student = $this->student('An');
        $capo = $this->postJson('/api/inventory', ['name' => 'Capo', 'unit' => 'Cái', 'center' => 'Cơ sở A', 'cost_price' => 50000, 'sell_price' => 95000, 'quantity' => 5])->json('item.id');
        $book = $this->postJson('/api/inventory', ['name' => 'Sách 1A', 'unit' => 'Cuốn', 'center' => 'Cơ sở A', 'cost_price' => 10000, 'sell_price' => 50000, 'quantity' => 5])->json('item.id');

        // Giao 1 lần 2 món: Capo x3, Sách x1 → 335.000đ trên một dòng doanh thu
        $supplies = collect($this->postJson("/api/students/{$student->id}/supplies", [
            'delivery_date' => '2026-10-20', 'center' => 'Cơ sở A',
            'items' => [
                ['inventory_id' => $capo, 'name' => 'Capo', 'quantity' => 3, 'price' => 95000],
                ['inventory_id' => $book, 'name' => 'Sách 1A', 'quantity' => 1, 'price' => 50000],
            ],
        ])->assertOk()->json('supplies'))->keyBy('name');
        $stock = fn ($id) => collect($this->getJson('/api/inventory')->json('items'))->firstWhere('id', $id)['quantity'];
        $revenue = fn () => $this->getJson('/api/revenue?type=inventory')->json('metrics.inventory_total');
        $this->assertEquals(335000, $revenue());

        // Thu hồi 1 Capo giao nhầm: còn 2, kho +1, doanh thu -95.000
        $this->postJson("/api/students/{$student->id}/supplies/{$supplies['Capo']['id']}/return", ['quantity' => 1, 'reason' => 'Giao nhầm'])
            ->assertOk()->assertJsonPath('supplies.0.quantity', 2);
        $this->assertSame([3, 240000], [$stock($capo), (int) $revenue()]);

        // Thu hồi hết Sách: bỏ khỏi học viên (cổng phụ huynh không còn thấy), kho +1
        $this->postJson("/api/students/{$student->id}/supplies/{$supplies['Sách 1A']['id']}/return")->assertOk();
        $this->assertSame(['Capo'], collect($student->fresh()->supplies)->pluck('name')->all());
        $this->assertSame([5, 190000], [$stock($book), (int) $revenue()]);

        // Thu hồi quá số đã giao / học cụ không có → báo lỗi
        $this->postJson("/api/students/{$student->id}/supplies/{$supplies['Capo']['id']}/return", ['quantity' => 3])->assertUnprocessable();
        $this->postJson("/api/students/{$student->id}/supplies/khong-co/return")->assertNotFound();
    }

    public function test_revenue_and_expense_can_be_edited_and_deleted(): void
    {
        $tx = $this->postJson('/api/revenue', [
            'recorded_by' => 'Lễ tân', 'date' => '2026-10-20', 'student_name' => 'Khách', 'payment_method' => 'Tiền mặt',
            'inventory_fee' => 100000, 'center' => 'Cơ sở A', // không có tài khoản nhận tiền vẫn lưu được
        ])->assertCreated()->json('transaction');

        $this->putJson("/api/revenue/{$tx['id']}", [...$tx, 'inventory_fee' => 150000])->assertOk()->assertJsonPath('transaction.total_amount', 150000);
        $this->deleteJson("/api/revenue/{$tx['id']}")->assertOk();
        $this->assertSame([], $this->getJson('/api/revenue')->json('transactions'));

        $ex = $this->postJson('/api/revenue/expenses', ['date' => '2026-10-20', 'category' => 'Thuê mặt bằng', 'spender' => 'A', 'content' => 'Thuê', 'amount' => 10, 'center' => 'Cơ sở A'])
            ->assertCreated()->json('expense');
        $this->putJson("/api/revenue/expenses/{$ex['id']}", [...$ex, 'amount' => 20])->assertOk()->assertJsonPath('expense.amount', 20);
        $this->deleteJson("/api/revenue/expenses/{$ex['id']}")->assertOk();
        $this->assertSame([], $this->getJson('/api/revenue')->json('expenses'));
    }

    public function test_invoice_revenue_rows_are_edited_on_invoice_screen(): void
    {
        $student = $this->student('An');
        $this->postJson('/api/invoices', ['student_id' => $student->id, 'amount' => 1000000, 'sessions_added' => 4, 'payment_method' => 'cash', 'paid_at' => '2026-10-20'])->assertCreated();

        $id = $this->getJson('/api/revenue')->json('transactions.0.id');
        $this->deleteJson("/api/revenue/{$id}")->assertUnprocessable();
    }

    public function test_pause_keeps_course_end_date_and_notes(): void
    {
        $student = $this->student('An', 'Cơ sở A', ['end_date' => '2026-12-31', 'notes' => 'Thích piano']);
        $this->student('Bình', 'Cơ sở B', ['status' => 'paused', 'paused_until' => '2026-10-22']);

        $this->postJson("/api/inactive-students/{$student->id}/pause", ['end_date' => '2026-10-25', 'notes' => 'Đi du lịch'])->assertOk();
        $student->refresh();
        $this->assertSame('2026-12-31', $student->end_date->toDateString());
        $this->assertSame('Thích piano', $student->notes);
        $this->assertSame('2026-10-25', $student->paused_until->toDateString());
        $this->assertSame('Đi du lịch', $student->pause_note);

        // "Sắp đi học lại" theo đúng trung tâm đang lọc
        $this->assertSame(['An'], collect($this->getJson('/api/inactive-students?center='.urlencode('Cơ sở A'))->json('upcoming_return_students'))->pluck('name')->all());
        $this->assertCount(2, $this->getJson('/api/inactive-students')->json('upcoming_return_students'));

        $this->postJson("/api/inactive-students/{$student->id}/reactivate")->assertOk();
        $this->assertNull($student->fresh()->paused_until);
    }

    public function test_reports_use_real_data_per_center_and_period(): void
    {
        // Dữ liệu trắng: không có số liệu "ảo"
        $this->assertSame([0, 0, 0, 0, 0, 0, 0, 0, 0, 0], array_slice(array_column($this->getJson('/api/reports?year=2026')->json('active_students_trend'), 'count'), 0, 10));

        $an = $this->student('An', 'Cơ sở A', ['join_date' => '2026-09-05']);
        $this->student('Cường', 'Cơ sở B', ['join_date' => '2026-10-02']);
        $this->postJson("/api/attendance/{$an->id}/mark", ['date' => '2026-10-05', 'status' => 'present'])->assertOk();
        $this->postJson("/api/attendance/{$an->id}/mark", ['date' => '2026-10-12', 'status' => 'present'])->assertOk();
        $this->postJson("/api/attendance/{$an->id}/mark", ['date' => '2026-10-19', 'status' => 'absent_without_leave'])->assertOk();

        $year = $this->getJson('/api/reports?period=year&year=2026&center='.urlencode('Cơ sở A'))->assertOk();
        $active = array_column($year->json('active_students_trend'), 'count');
        $this->assertSame([0, 1, 1], [$active[7], $active[8], $active[9]]); // Th8, Th9, Th10
        $this->assertNull($active[11]); // Th12 chưa tới
        $this->assertSame(67, $year->json('attendance_rate_trend.9.rate')); // 2 có mặt / 3 buổi

        $this->assertSame(2, $this->getJson('/api/reports?period=year&year=2026')->json('active_students_trend.9.count'));
        $this->assertSame(['Th10', 'Th11', 'Th12'], array_column($this->getJson('/api/reports?period=quarter&year=2026&month=10')->json('new_vs_drop_students'), 'label'));
        $this->assertCount(31, $this->getJson('/api/reports?period=month&year=2026&month=10')->json('attendance_rate_trend'));
    }

    public function test_lead_can_be_edited_and_export_follows_filters(): void
    {
        $lead = $this->postJson('/api/leads', ['parent_name' => 'Hồng Phạm', 'phone' => '0900', 'source' => 'Facebook', 'status' => 'new', 'center' => 'Cơ sở A'])
            ->assertCreated()->json('lead');
        $this->postJson('/api/leads', ['parent_name' => 'Lan', 'phone' => '0911', 'source' => 'Zalo', 'status' => 'new', 'center' => 'Cơ sở B'])->assertCreated();

        $this->putJson("/api/leads/{$lead['id']}", [...$lead, 'phone' => '0982531765', 'notes' => 'Hẹn test tối T6'])
            ->assertOk()->assertJsonPath('lead.phone', '0982531765')->assertJsonPath('lead.notes', 'Hẹn test tối T6');

        $csv = $this->get('/api/leads/export?center='.urlencode('Cơ sở A'))->assertOk()->streamedContent();
        $this->assertStringContainsString('Hồng Phạm', $csv);
        $this->assertStringNotContainsString('Lan', $csv);
    }

    public function test_cannot_hand_over_more_supplies_than_in_stock(): void
    {
        $student = $this->student('An');
        $id = $this->postJson('/api/inventory', ['name' => 'Capo', 'unit' => 'Cái', 'center' => 'Cơ sở A', 'cost_price' => 1, 'sell_price' => 95000, 'quantity' => 2])->json('item.id');
        $other = $this->postJson('/api/inventory', ['name' => 'Dây đàn', 'unit' => 'Bộ', 'center' => 'Cơ sở B', 'cost_price' => 1, 'sell_price' => 1, 'quantity' => 9])->json('item.id');
        $give = fn (array $items) => $this->postJson("/api/students/{$student->id}/supplies", ['delivery_date' => '2026-10-20', 'center' => 'Cơ sở A', 'items' => $items]);

        $give([['inventory_id' => $id, 'name' => 'Capo', 'quantity' => 3, 'price' => 95000]])
            ->assertUnprocessable()->assertJsonPath('errors.items.0', '"Capo" chỉ còn 2 Cái trong kho, không giao được 3.');
        // Hai dòng cùng học cụ cộng dồn vượt tồn
        $give([['inventory_id' => $id, 'name' => 'Capo', 'quantity' => 2], ['inventory_id' => $id, 'name' => 'Capo', 'quantity' => 1]])->assertUnprocessable();
        // Học cụ của trung tâm khác / không có trong kho
        $give([['inventory_id' => $other, 'name' => 'Dây đàn', 'quantity' => 1]])->assertUnprocessable();
        $give([['name' => 'Bút chì', 'quantity' => 1]])->assertUnprocessable();

        // Bị chặn thì không giao, không trừ kho, không ghi doanh thu
        $this->assertEmpty($student->fresh()->supplies);
        $this->assertSame(2, collect($this->getJson('/api/inventory')->json('items'))->firstWhere('id', $id)['quantity']);
        $this->assertEquals(0, $this->getJson('/api/revenue?type=inventory')->json('metrics.inventory_total'));

        $give([['inventory_id' => $id, 'name' => 'Capo', 'quantity' => 2, 'price' => 95000]])->assertOk();
        $this->assertSame(0, collect($this->getJson('/api/inventory')->json('items'))->firstWhere('id', $id)['quantity']);
        $give([['inventory_id' => $id, 'name' => 'Capo', 'quantity' => 1]])->assertUnprocessable(); // đã hết hàng
    }

    public function test_invoice_pdfs_show_course_start_and_end_dates(): void
    {
        $piano = Course::create(['code' => 'P', 'name' => 'Piano', 'price' => 1, 'default_sessions' => 8]);
        $student = $this->student('An', 'Cơ sở A', ['join_date' => '2026-09-01', 'end_date' => '2026-12-31', 'registered_courses' => [
            ['id' => 1, 'course_id' => $this->course->id, 'course_name' => 'Guitar', 'start_date' => '2026-09-01', 'end_date' => '2026-11-30'],
            ['id' => 2, 'course_id' => $piano->id, 'course_name' => 'Piano', 'start_date' => '2026-10-05', 'end_date' => '2026-12-20'],
        ]]);

        // Theo đúng khóa của hóa đơn
        $this->assertSame(['start' => '05/10/2026', 'end' => '20/12/2026', 'end_estimated' => false], $student->courseDates($piano->id));
        $this->assertSame(['start' => '01/09/2026', 'end' => '30/11/2026', 'end_estimated' => false], $student->courseDates());
        // Khóa không có trong danh sách đăng ký → ngày nhập học / kết thúc dự kiến của học viên
        $this->assertSame(['start' => '01/09/2026', 'end' => '31/12/2026', 'end_estimated' => false], $student->courseDates(999));

        $id = $this->postJson('/api/invoices', ['student_id' => $student->id, 'course_id' => $piano->id, 'amount' => 1000000, 'sessions_added' => 0, 'payment_method' => 'cash', 'paid_at' => '2026-10-20'])
            ->assertCreated()->json('invoice.id');
        $this->get("/api/invoices/{$id}/pdf")->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->get("/api/students/{$student->id}/tuition-invoice.pdf")->assertOk();

        $html = view('pdf.payment-receipt', ['invoice' => Invoice::find($id), 'brand' => BrandSettings::brand()])->render();
        $this->assertStringContainsString('Ngày bắt đầu học: <strong>05/10/2026</strong>', $html);
        $this->assertStringContainsString('Ngày kết thúc: <strong>20/12/2026</strong>', $html);
    }
}
