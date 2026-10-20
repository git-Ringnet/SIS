<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

class StudentImportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Sanctum::actingAs(User::factory()->create());
    }

    public function test_can_download_student_excel_template_with_guide(): void
    {
        $response = $this->get('/api/students/template');

        $response->assertStatus(200);
        $response->assertHeader('Content-Disposition', 'attachment; filename=mau_nhap_hoc_vien.xlsx');

        $path = tempnam(sys_get_temp_dir(), 'tpl');
        file_put_contents($path, $response->streamedContent());
        $book = IOFactory::load($path);

        // Mở file là thấy sheet hướng dẫn; sheet nhập liệu chỉ có tiêu đề (không có dòng mẫu bị import nhầm)
        $this->assertSame(['Hướng dẫn', 'Học viên'], $book->getSheetNames());
        $data = $book->getSheetByName('Học viên');
        $this->assertSame('Họ tên học viên *', $data->getCell('A1')->getValue());
        $this->assertSame('SĐT phụ huynh *', $data->getCell('B1')->getValue());
        $this->assertSame('', (string) $data->getCell('A2')->getValue());
        $this->assertStringContainsString('BẮT BUỘC', $data->getComment('A1')->getText()->getPlainText());
    }

    public function test_can_import_filled_excel_template_and_reports_skipped_rows(): void
    {
        Course::create(['code' => 'G', 'name' => 'Guitar cơ bản', 'price' => 2000000, 'default_sessions' => 16, 'is_active' => true]);

        $path = tempnam(sys_get_temp_dir(), 'tpl');
        file_put_contents($path, $this->get('/api/students/template')->streamedContent());
        $book = IOFactory::load($path);
        $sheet = $book->getSheetByName('Học viên');
        // Họ tên, SĐT PH, Tên PH, Giới tính, Ngày sinh, Email, Zalo, Địa chỉ, Cơ sở, Khóa học, Số buổi, Ngày nhập học, Trạng thái, Lịch học, GV, Phòng, Ghi chú
        $sheet->fromArray([
            ['Bé An', '0908168815', 'Chị Lan', 'Nữ', '15/03/2015', '', '', '', '', 'guitar cơ bản', '', '01/10/2026', 'Tạm dừng', 'Thu 6 17:30-19:00; CN 08:00-09:30', 'Cô Mai', 'G1', ''],
            ['Bé Bình', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', ''],
            ['', '0901111111', '', '', '', '', '', '', '', '', '', '', '', '', '', '', ''],
        ], null, 'A2', true);
        IOFactory::createWriter($book, 'Xlsx')->save($path);

        $response = $this->postJson('/api/students/import', [
            'csv_file' => UploadedFile::fake()->createWithContent('mau.xlsx', file_get_contents($path)),
        ]);

        $response->assertOk()
            ->assertJsonPath('imported_count', 1)
            ->assertJsonPath('skipped.0.row', 3)
            ->assertJsonPath('skipped.0.reason', 'Thiếu SĐT phụ huynh')
            ->assertJsonPath('skipped.1.reason', 'Thiếu Họ tên học viên');

        $an = Student::where('name', 'Bé An')->first();
        $this->assertSame('Nữ', $an->gender);
        $this->assertSame('paused', $an->status);
        $this->assertSame(16, $an->total_sessions); // số buổi mặc định của khóa
        $this->assertSame([5, 0], $an->class_days); // Thứ 6 + CN (không bị hiểu nhầm thành Thứ 5), sắp T2 → CN
        $this->assertSame(['17:30', '08:00'], collect($an->schedules)->pluck('start')->all()); // mỗi ca giờ riêng
        $this->assertSame('17:30', $an->class_start_time);
        $this->assertSame('19:00', $an->class_end_time);
        $this->assertSame('Cô Mai', $an->teacher);
        $this->assertSame('2015-03-15', $an->dob->toDateString());
    }

    public function test_can_import_students_from_csv(): void
    {
        $course = Course::create([
            'code' => 'KH-PIANO',
            'name' => 'Piano Căn Bản',
            'price' => 2000000,
            'default_sessions' => 12,
            'is_active' => true,
        ]);

        $csvContent = "# Hướng dẫn mẫu import\n"
            ."full_name,birthday,parent_name,parent_phone,parent_email,student_phone,address,enrollment_date,notes,status,center_name,schedules,courses\n"
            .'"VÕ MINH AN",15/03/2015,"NGUYỄN HOÀNG KIM ANH",0908168815,parent@email.com,,123 Lê Lợi,01/01/2026,Loves painting,active,Global Art Bình Thọ,"Thứ 2 15:00; Thứ 4 17:00","Piano Căn Bản"'."\n"
            .'"TRẦN GIA HÂN",20/08/2016,"TRẦN VĂN BÌNH",0912345678,,,456 Hai Bà Trưng,10/02/2026,Chăm chỉ,active,Global Art Bình Thọ,"Thứ 3 18:00","Guitar Cơ Bản"'."\n";

        $file = UploadedFile::fake()->createWithContent('students_template.csv', $csvContent);

        $response = $this->postJson('/api/students/import', [
            'csv_file' => $file,
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'imported_count' => 2,
        ]);

        $this->assertDatabaseHas('students', [
            'name' => 'VÕ MINH AN',
            'parent_phone' => '0908168815',
            'course_id' => $course->id,
            'address' => '123 Lê Lợi',
            'dob' => '2015-03-15',
        ]);

        $this->assertDatabaseHas('students', [
            'name' => 'TRẦN GIA HÂN',
            'parent_phone' => '0912345678',
            'address' => '456 Hai Bà Trưng',
            'dob' => '2016-08-20',
        ]);

        $studentVo = Student::where('name', 'VÕ MINH AN')->first();
        $this->assertNotNull($studentVo);
        $this->assertContains(1, $studentVo->class_days); // Thứ 2 = 1
        $this->assertContains(3, $studentVo->class_days); // Thứ 4 = 3
        $this->assertEquals('15:00', $studentVo->class_start_time);
    }
}
