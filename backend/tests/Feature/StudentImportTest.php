<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StudentImportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Sanctum::actingAs(User::factory()->create());
    }

    public function test_can_download_student_excel_template(): void
    {
        $response = $this->get('/api/students/template');

        $response->assertStatus(200);
        $response->assertHeader('Content-Disposition', 'attachment; filename=students_template.xlsx');
    }

    public function test_can_import_students_from_downloaded_excel_template(): void
    {
        $content = $this->get('/api/students/template')->streamedContent();
        $file = UploadedFile::fake()->createWithContent('students_template.xlsx', $content);

        $response = $this->postJson('/api/students/import', ['csv_file' => $file]);

        $response->assertStatus(200)->assertJson(['imported_count' => 1]);
        $this->assertDatabaseHas('students', [
            'name' => 'VÕ MINH AN',
            'parent_phone' => '0908168815',
            'dob' => '2015-03-15',
        ]);
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
