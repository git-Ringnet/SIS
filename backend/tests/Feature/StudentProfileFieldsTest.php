<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Form tạo / sửa học viên có đủ các trường như file Excel mẫu (ngày sinh, SĐT Zalo, email, địa chỉ, ngày nhập học...).
 */
class StudentProfileFieldsTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_and_update_keep_all_template_fields(): void
    {
        Storage::fake('local');
        Sanctum::actingAs(User::factory()->create());
        $course = Course::create(['code' => 'P', 'name' => 'Piano', 'price' => 1, 'default_sessions' => 12]);

        $fields = [
            'name' => 'Võ Minh An', 'gender' => 'Nữ', 'dob' => '2015-03-15',
            'parent_name' => 'Nguyễn Hoàng Kim Anh', 'parent_phone' => '0908168815', 'parent_zalo' => '0908000111',
            'parent_email' => 'phuhuynh@gmail.com', 'address' => '123 Lê Lợi, Hóc Môn',
            'course_id' => $course->id, 'total_sessions' => 12, 'status' => 'paused', 'join_date' => '2026-10-01',
            'notes' => 'Thích vẽ màu nước',
        ];
        $id = $this->postJson('/api/students', $fields)->assertCreated()->json('student.id');

        $student = Student::find($id);
        $this->assertSame(
            ['2015-03-15', '0908000111', 'phuhuynh@gmail.com', '123 Lê Lợi, Hóc Môn', 'paused', '2026-10-01', 'Nữ'],
            [$student->dob->toDateString(), $student->parent_zalo, $student->parent_email, $student->address, $student->status, $student->join_date->toDateString(), $student->gender],
        );

        $this->putJson("/api/students/{$id}", [
            ...$fields, 'remaining_sessions' => 12, 'retention_status' => 'normal', 'status' => 'active',
            'address' => '45 Tô Ký, Hóc Môn', 'join_date' => '2026-10-05', 'notes' => "Ghi chú mới\ndòng 2",
        ])->assertOk();

        $student->refresh();
        $this->assertSame(['45 Tô Ký, Hóc Môn', '2026-10-05', "Ghi chú mới\ndòng 2"], [$student->address, $student->join_date->toDateString(), $student->notes]);
    }
}
