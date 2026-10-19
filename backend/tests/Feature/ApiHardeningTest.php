<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Invoice;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ApiHardeningTest extends TestCase
{
    use RefreshDatabase;

    private Course $course;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake();
        $this->course = Course::create(['code' => 'PIANO', 'name' => 'Piano', 'price' => 3000000, 'default_sessions' => 12]);
    }

    private function makeStudent(array $overrides = []): Student
    {
        static $n = 100;
        $n++;

        return Student::create(array_merge([
            'code' => "HV-{$n}",
            'name' => "Học viên {$n}",
            'gender' => 'Nam',
            'parent_name' => 'Phụ huynh',
            'parent_phone' => '0900000000',
            'course_id' => $this->course->id,
            'status' => 'active',
            'total_sessions' => 12,
            'remaining_sessions' => 12,
        ], $overrides));
    }

    public function test_business_api_requires_authentication(): void
    {
        $this->getJson('/api/students')->assertUnauthorized();
        $this->putJson('/api/settings/password', ['password' => 'x'])->assertUnauthorized();
    }

    public function test_password_change_applies_to_the_logged_in_user_not_the_first_user(): void
    {
        $owner = User::factory()->create();
        $staff = User::factory()->create();
        $ownerHash = $owner->password;
        $staffHash = $staff->password;
        Sanctum::actingAs($staff);

        $this->putJson('/api/settings/password', ['password' => 'secret123', 'password_confirmation' => 'secret123'])
            ->assertOk();

        $this->assertSame($ownerHash, $owner->fresh()->password);
        $this->assertNotSame($staffHash, $staff->fresh()->password);
    }

    public function test_students_list_is_paginated_only_when_page_is_requested(): void
    {
        Sanctum::actingAs(User::factory()->create());
        foreach (range(1, 25) as $i) {
            $this->makeStudent();
        }

        $this->getJson('/api/students?page=2&per_page=10')
            ->assertOk()
            ->assertJsonCount(10, 'students')
            ->assertJsonPath('pagination.total', 25)
            ->assertJsonPath('pagination.last_page', 3)
            ->assertJsonPath('pagination.from', 11);

        // Dropdown chọn học viên không gửi page nên vẫn nhận đủ danh sách
        $this->getJson('/api/students')->assertOk()->assertJsonCount(25, 'students');
    }

    public function test_one_click_attendance_only_deducts_one_session_per_day(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $student = $this->makeStudent(['remaining_sessions' => 5]);

        $this->postJson("/api/students/{$student->id}/attendance")->assertOk();
        $this->postJson("/api/students/{$student->id}/attendance")->assertStatus(422);

        $this->assertSame(4, $student->fresh()->remaining_sessions);
    }

    public function test_marking_absent_student_present_cannot_make_sessions_negative(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $student = $this->makeStudent(['remaining_sessions' => 0]);
        $date = now()->toDateString();

        $this->postJson("/api/attendance/{$student->id}/mark", ['date' => $date, 'status' => 'absent_without_leave'])->assertOk();
        $this->postJson("/api/attendance/{$student->id}/mark", ['date' => $date, 'status' => 'present'])->assertStatus(422);

        $this->assertSame(0, $student->fresh()->remaining_sessions);
    }

    public function test_new_student_code_does_not_collide_with_existing_codes(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $this->makeStudent(['code' => 'HV-101']);

        $this->postJson('/api/students', [
            'name' => 'Mới',
            'gender' => 'Nam',
            'parent_name' => 'PH',
            'parent_phone' => '0911111111',
            'course_id' => $this->course->id,
            'total_sessions' => 12,
            'status' => 'active',
            'create_invoice' => true,
        ])->assertCreated()->assertJsonPath('student.code', 'HV-102');
    }

    public function test_invoice_codes_stay_unique_for_the_same_student_in_a_month(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $student = $this->makeStudent();
        $payload = [
            'student_id' => $student->id,
            'amount' => 1000000,
            'sessions_added' => 4,
            'payment_method' => 'cash',
            'paid_at' => now()->toDateString(),
        ];

        $this->postJson('/api/invoices', $payload)->assertCreated();
        $this->postJson('/api/invoices', $payload)->assertCreated();

        $this->assertSame(2, Invoice::distinct()->count('invoice_code'));
    }

    public function test_student_with_combined_retention_status_or_inactive_status_can_be_updated(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $student = $this->makeStudent(['status' => 'inactive', 'retention_status' => 'invoice_sent,following_up']);

        $this->putJson("/api/students/{$student->id}", array_merge($student->toArray(), ['name' => 'Đã đổi tên']))
            ->assertOk();

        $this->assertSame('Đã đổi tên', $student->fresh()->name);
    }

    public function test_dropped_filter_includes_inactive_students(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $this->makeStudent(['status' => 'dropped']);
        $this->makeStudent(['status' => 'inactive']);
        $this->makeStudent();

        $this->getJson('/api/students?status=dropped')->assertOk()->assertJsonCount(2, 'students');
    }
}
