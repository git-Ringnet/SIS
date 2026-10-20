<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RevenuePaymentRecordingTest extends TestCase
{
    use RefreshDatabase;

    public function test_payment_invoice_accepts_an_attached_pdf(): void
    {
        Storage::fake('public');
        Sanctum::actingAs(User::factory()->create());
        $course = Course::create(['code' => 'PIANO', 'name' => 'Piano', 'price' => 3000000, 'default_sessions' => 12]);
        $student = Student::create([
            'code' => 'HV-001', 'name' => 'Hoc vien', 'gender' => 'Nam', 'parent_name' => 'Phu huynh', 'parent_phone' => '0900000000',
            'course_id' => $course->id, 'total_sessions' => 12, 'remaining_sessions' => 12, 'status' => 'active',
        ]);

        $response = $this->post('/api/invoices', [
            'student_id' => $student->id,
            'course_id' => $course->id,
            'amount' => 3000000,
            'sessions_added' => 12,
            'payment_method' => 'cash',
            'paid_at' => now()->toDateString(),
            'invoice_pdf' => UploadedFile::fake()->create('hoa-don.pdf', 40, 'application/pdf'),
        ]);

        $response->assertCreated()->assertJsonPath('invoice.course_id', $course->id);
        Storage::disk('public')->assertExists($response->json('invoice.invoice_file_path'));
    }
}
