<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\CourseClass;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CenterInvoiceConfigurationTest extends TestCase
{
    use RefreshDatabase;

    private Course $course;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        Sanctum::actingAs(User::factory()->create());
        $this->course = Course::create([
            'code' => 'MUSIC',
            'name' => 'Piano',
            'price' => 3000000,
            'default_sessions' => 12,
        ]);
    }

    public function test_can_upload_center_image(): void
    {
        $file = UploadedFile::fake()->image('logo.png', 100, 100);

        $res = $this->postJson('/api/centers/upload', [
            'file' => $file,
        ])->assertOk();

        $this->assertNotEmpty($res->json('url'));
    }

    public function test_can_save_center_with_invoice_prefix_bank_info_logo_and_qr(): void
    {
        $res = $this->postJson('/api/centers', [
            'name' => 'Cơ sở Quận 4',
            'address' => '456 Hoàng Diệu, Q4',
            'phone' => '0901234567',
            'invoice_prefix' => '4CS',
            'logo_url' => '/storage/centers/logo.png',
            'bank_info' => "Số tài khoản: 123456\nTên: Trung Tâm Q4\nNgân hàng: VCB",
            'qr_url' => '/storage/centers/qr.png',
        ])->assertCreated();

        $center = $res->json('center');
        $this->assertSame('4CS', $center['invoice_prefix']);
        $this->assertSame('/storage/centers/logo.png', $center['logo_url']);
        $this->assertStringContainsString('123456', $center['bank_info']);
        $this->assertSame('/storage/centers/qr.png', $center['qr_url']);
    }

    public function test_invoice_numbers_follow_center_prefix(): void
    {
        $this->postJson('/api/centers', [
            'name' => 'Cơ sở Bình Thạnh',
            'invoice_prefix' => 'BT',
        ])->assertCreated();

        $studentBT = Student::create([
            'code' => Student::nextCode(),
            'name' => 'Nguyễn Văn A',
            'gender' => 'Nam',
            'parent_name' => 'Phụ huynh A',
            'parent_phone' => '0909000001',
            'course_id' => $this->course->id,
            'total_sessions' => 12,
            'remaining_sessions' => 12,
            'status' => 'active',
            'center' => 'Cơ sở Bình Thạnh',
        ]);

        $res1 = $this->postJson('/api/invoices', [
            'student_id' => $studentBT->id,
            'course_id' => $this->course->id,
            'amount' => 3000000,
            'sessions_added' => 12,
            'payment_method' => 'bank_transfer',
            'paid_at' => now()->toDateString(),
        ])->assertCreated();

        $this->assertSame('BT0001', $res1->json('invoice.invoice_code'));

        $res2 = $this->postJson('/api/invoices', [
            'student_id' => $studentBT->id,
            'course_id' => $this->course->id,
            'amount' => 3000000,
            'sessions_added' => 12,
            'payment_method' => 'bank_transfer',
            'paid_at' => now()->toDateString(),
        ])->assertCreated();

        $this->assertSame('BT0002', $res2->json('invoice.invoice_code'));
    }

    public function test_empty_invoice_prefix_defaults_to_inv(): void
    {
        $this->postJson('/api/centers', [
            'name' => 'Cơ sở Mặc Định',
            'invoice_prefix' => '',
        ])->assertCreated();

        $student = Student::create([
            'code' => Student::nextCode(),
            'name' => 'Trần Thị B',
            'gender' => 'Nữ',
            'parent_name' => 'Phụ huynh B',
            'parent_phone' => '0909000002',
            'course_id' => $this->course->id,
            'total_sessions' => 12,
            'remaining_sessions' => 12,
            'status' => 'active',
            'center' => 'Cơ sở Mặc Định',
        ]);

        $res = $this->postJson('/api/invoices', [
            'student_id' => $student->id,
            'amount' => 1500000,
            'sessions_added' => 6,
            'payment_method' => 'cash',
            'paid_at' => now()->toDateString(),
        ])->assertCreated();

        $this->assertSame('INV0001', $res->json('invoice.invoice_code'));
    }

    public function test_tuition_invoice_pdf_renders_with_center_info(): void
    {
        $this->postJson('/api/centers', [
            'name' => 'Cơ sở Test PDF',
            'invoice_prefix' => 'TPDF',
            'bank_info' => 'STK: 99998888',
        ])->assertCreated();

        $student = Student::create([
            'code' => Student::nextCode(),
            'name' => 'Lê Văn C',
            'gender' => 'Nam',
            'parent_name' => 'Phụ huynh C',
            'parent_phone' => '0909000003',
            'course_id' => $this->course->id,
            'total_sessions' => 12,
            'remaining_sessions' => 12,
            'status' => 'active',
            'center' => 'Cơ sở Test PDF',
        ]);

        $response = $this->get("/api/students/{$student->id}/tuition-invoice.pdf");
        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
    }

    public function test_filter_students_by_unassigned_class_teacher_and_staff(): void
    {
        // 1. Học viên đã gán lớp, giáo viên
        $assignedStudent = Student::create([
            'code' => Student::nextCode(),
            'name' => 'HV Đã Gán',
            'gender' => 'Nam',
            'parent_name' => 'PH',
            'parent_phone' => '0901111111',
            'course_id' => $this->course->id,
            'total_sessions' => 12,
            'remaining_sessions' => 12,
            'status' => 'active',
            'assigned_class' => 'Lớp Piano 01',
            'teacher' => 'Thầy Nam',
        ]);

        // 2. Học viên chưa gán
        $unassignedStudent = Student::create([
            'code' => Student::nextCode(),
            'name' => 'HV Chưa Gán',
            'gender' => 'Nữ',
            'parent_name' => 'PH',
            'parent_phone' => '0902222222',
            'course_id' => $this->course->id,
            'total_sessions' => 12,
            'remaining_sessions' => 12,
            'status' => 'active',
            'assigned_class' => null,
            'teacher' => null,
        ]);

        // Lọc Chưa gán lớp
        $resClass = $this->getJson('/api/students?assigned_class=unassigned')->assertOk();
        $classIds = collect($resClass->json('students'))->pluck('id')->all();
        $this->assertContains($unassignedStudent->id, $classIds);
        $this->assertNotContains($assignedStudent->id, $classIds);

        // Lọc Chưa gán giáo viên
        $resTeacher = $this->getJson('/api/students?teacher=unassigned')->assertOk();
        $teacherIds = collect($resTeacher->json('students'))->pluck('id')->all();
        $this->assertContains($unassignedStudent->id, $teacherIds);
        $this->assertNotContains($assignedStudent->id, $teacherIds);

        // Lọc Chưa gán người phụ trách
        $resStaff = $this->getJson('/api/students?staff=unassigned')->assertOk();
        $staffIds = collect($resStaff->json('students'))->pluck('id')->all();
        $this->assertContains($unassignedStudent->id, $staffIds);
        $this->assertNotContains($assignedStudent->id, $staffIds);

        // Danh sách lớp trả về bao gồm cả lớp từ CourseClass
        CourseClass::create([
            'course_id' => $this->course->id,
            'name' => 'Nhóm 1',
            'capacity' => 6,
        ]);
        $resAll = $this->getJson('/api/students')->assertOk();
        $classes = $resAll->json('classes');
        $this->assertTrue(collect($classes)->contains(fn ($c) => $c['id'] === 'Nhóm 1' && str_contains($c['name'], 'Nhóm 1 — Piano')));
    }
}
