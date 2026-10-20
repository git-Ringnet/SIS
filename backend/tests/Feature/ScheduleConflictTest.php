<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\User;
use App\Support\CenterStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ScheduleConflictTest extends TestCase
{
    use RefreshDatabase;

    private Course $course;

    private string $center;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Sanctum::actingAs(User::factory()->create());

        $this->course = Course::create(['code' => 'P', 'name' => 'Piano', 'price' => 1, 'default_sessions' => 12]);
        $this->center = CenterStore::defaultName();
        $centerId = CenterStore::all()[0]['id'];
        $this->postJson("/api/centers/{$centerId}/rooms", ['name' => 'PIANO 01', 'capacity' => 1])->assertSuccessful();
        $this->postJson("/api/centers/{$centerId}/rooms", ['name' => 'GUITAR 01', 'capacity' => 4])->assertSuccessful();
    }

    private function create(array $schedule): array
    {
        return $this->postJson('/api/students', [
            'name' => 'HV '.uniqid(), 'gender' => 'Nam', 'parent_name' => 'PH', 'parent_phone' => '0900000000',
            'course_id' => $this->course->id, 'total_sessions' => 12, 'status' => 'active', 'center' => $this->center,
            'class_days' => [1], ...$schedule,
        ])->assertCreated()->json('warnings');
    }

    public function test_warns_when_room_exceeds_capacity_with_overlapping_times(): void
    {
        $this->assertSame([], $this->create(['class_start_time' => '17:30', 'class_end_time' => '18:30', 'room' => 'PIANO 01']));

        // Lệch giờ nhưng giao nhau, phòng sức chứa 1
        $warnings = $this->create(['class_start_time' => '18:00', 'class_end_time' => '19:00', 'room' => 'PIANO 01']);
        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('vượt sức chứa 1', $warnings[0]);
    }

    public function test_no_warning_when_times_do_not_overlap_or_group_fits(): void
    {
        $this->create(['class_start_time' => '17:30', 'class_end_time' => '18:30', 'room' => 'PIANO 01']);
        $this->assertSame([], $this->create(['class_start_time' => '18:30', 'class_end_time' => '19:30', 'room' => 'PIANO 01']));

        // Lớp nhóm: cùng phòng cùng giờ cùng giáo viên, còn trong sức chứa
        $this->create(['class_start_time' => '09:00', 'class_end_time' => '10:00', 'room' => 'GUITAR 01', 'teacher' => 'Thầy A']);
        $this->assertSame([], $this->create(['class_start_time' => '09:00', 'class_end_time' => '10:00', 'room' => 'GUITAR 01', 'teacher' => 'Thầy A']));
    }

    public function test_warns_when_teacher_is_double_booked_in_another_room(): void
    {
        $this->create(['class_start_time' => '17:30', 'class_end_time' => '18:30', 'room' => 'PIANO 01', 'teacher' => 'Cô B']);

        $warnings = $this->create(['class_start_time' => '18:00', 'class_end_time' => '19:00', 'room' => 'GUITAR 01', 'teacher' => 'Cô B']);
        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('Giáo viên Cô B', $warnings[0]);
        $this->assertStringContainsString('trùng giờ', $warnings[0]);
    }
}
