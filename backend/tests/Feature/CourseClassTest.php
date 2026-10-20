<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Student;
use App\Models\User;
use App\Support\CenterStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CourseClassTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Sanctum::actingAs(User::factory()->create());
    }

    public function test_create_course_without_code_category_color(): void
    {
        $this->postJson('/api/courses', ['name' => 'Học guitar', 'default_sessions' => 12, 'price' => 3000000])
            ->assertCreated()
            ->assertJsonPath('course.code', 'KH-001')
            ->assertJsonPath('course.is_active', true);
        $this->postJson('/api/courses', ['name' => 'Piano', 'default_sessions' => 12, 'price' => 1])
            ->assertJsonPath('course.code', 'KH-002');
    }

    public function test_class_slots_become_student_schedule(): void
    {
        $course = Course::create(['code' => 'KH-001', 'name' => 'Học guitar', 'price' => 1, 'default_sessions' => 12]);
        $class = $this->postJson("/api/courses/{$course->id}/classes", ['name' => 'Nhóm 1'])->assertCreated()->json('class');
        $student = Student::create([
            'code' => 'HV-1', 'name' => 'Bé An', 'gender' => 'Nam', 'parent_name' => 'PH', 'parent_phone' => '09',
            'course_id' => $course->id, 'total_sessions' => 12, 'remaining_sessions' => 12, 'status' => 'active',
        ]);

        // Lớp CN + T2 cùng 15:30-16:30 với Cô Mai, sĩ số 6
        $this->putJson("/api/courses/classes/{$class['id']}", ['name' => 'Nhóm 1', 'capacity' => 6, 'schedules' => [
            ['day' => 0, 'start' => '15:30', 'end' => '16:30', 'teacher' => 'Cô Mai'],
            ['day' => 1, 'start' => '15:30', 'end' => '16:30', 'teacher' => 'Cô Mai'],
        ]])->assertOk()->assertJsonPath('class.capacity', 6);

        $this->putJson("/api/students/{$student->id}/progress", ['assigned_class' => 'Nhóm 1'])
            ->assertOk()->assertJsonPath('message', 'Đã gán vào lớp Nhóm 1 và cập nhật lịch học theo lớp.');
        $student->refresh();
        $this->assertSame([1, 0], $student->class_days); // T2 trước CN
        $this->assertSame('15:30', $student->class_start_time);
        $this->assertSame('Cô Mai', $student->teacher);

        // Đổi giờ lớp → học viên trong lớp đổi theo
        $this->putJson("/api/courses/classes/{$class['id']}", ['name' => 'Nhóm 1', 'schedules' => [
            ['day' => 3, 'start' => '18:00', 'end' => '19:00', 'teacher' => 'Thầy Bình'],
        ]])->assertOk()->assertJsonPath('message', 'Đã lưu lớp và cập nhật lịch cho 1 học viên trong lớp.');
        $this->assertSame([3], $student->fresh()->class_days);
        $this->assertSame('Thầy Bình', $student->fresh()->teacher);

        // Các ca khác giờ nhau (CN 15:30, T2 09:00) vẫn áp được: mỗi ca của học viên giữ giờ riêng
        $this->putJson("/api/courses/classes/{$class['id']}", ['name' => 'Nhóm 1', 'schedules' => [
            ['day' => 0, 'start' => '15:30', 'teacher' => 'Cô Mai'], ['day' => 1, 'start' => '09:00'],
        ]])->assertOk()->assertJsonPath('message', 'Đã lưu lớp và cập nhật lịch cho 1 học viên trong lớp.');
        $slots = collect($student->fresh()->schedules);
        $this->assertSame(['09:00', '15:30'], $slots->pluck('start')->all()); // T2 trước CN
        $this->assertSame([null, 'Cô Mai'], $slots->pluck('teacher')->all());

        // Giờ kết thúc trước giờ bắt đầu bị chặn
        $this->putJson("/api/courses/classes/{$class['id']}", ['name' => 'Nhóm 1', 'schedules' => [
            ['day' => 0, 'start' => '15:30', 'end' => '15:00'],
        ]])->assertUnprocessable();
    }

    public function test_classes_default_capacity_from_room_and_block_when_full(): void
    {
        $center = CenterStore::all()[0];
        $this->postJson("/api/centers/{$center['id']}/rooms", ['name' => 'Phòng guitar 1', 'capacity' => 2])->assertSuccessful();
        $course = Course::create(['code' => 'KH-001', 'name' => 'Học guitar', 'price' => 1, 'default_sessions' => 12]);

        $this->getJson('/api/courses/class-rooms')->assertOk()->assertJsonPath('rooms.0.room', 'Phòng guitar 1');

        $class = $this->postJson("/api/courses/{$course->id}/classes", ['name' => 'Nhóm A', 'center' => $center['name'], 'room' => 'Phòng guitar 1'])
            ->assertCreated()->assertJsonPath('class.capacity', 2)->json('class');
        $this->postJson("/api/courses/{$course->id}/classes", ['name' => 'Nhóm A'])->assertUnprocessable();

        $students = collect(range(1, 3))->map(fn ($i) => Student::create([
            'code' => "HV-{$i}", 'name' => "Bé {$i}", 'gender' => 'Nam', 'parent_name' => 'PH', 'parent_phone' => '09',
            'course_id' => $course->id, 'total_sessions' => 12, 'remaining_sessions' => 12, 'status' => 'active',
        ]));

        $this->putJson("/api/students/{$students[0]->id}/progress", ['assigned_class' => 'Nhóm A'])->assertOk();
        $this->putJson("/api/students/{$students[1]->id}/progress", ['assigned_class' => 'Nhóm A'])->assertOk();
        $this->putJson("/api/students/{$students[2]->id}/progress", ['assigned_class' => 'Nhóm A'])
            ->assertUnprocessable()->assertJsonPath('message', 'Lớp Nhóm A đã đủ sĩ số (2/2). Hãy chọn lớp khác hoặc tăng sĩ số lớp.');
        $this->putJson("/api/students/{$students[2]->id}/progress", ['assigned_class' => 'Lớp không có'])->assertUnprocessable();

        $this->getJson('/api/courses')->assertJsonPath('courses.0.classes.0.students_count', 2);

        // Đổi tên lớp → học viên đi theo; xoá lớp → học viên về "chưa gán lớp"
        $this->putJson("/api/courses/classes/{$class['id']}", ['name' => 'Nhóm Guitar A', 'capacity' => 5])->assertOk();
        $this->assertSame('Nhóm Guitar A', $students[0]->fresh()->assigned_class);
        $this->deleteJson("/api/courses/classes/{$class['id']}")->assertOk();
        $this->assertNull($students[0]->fresh()->assigned_class);
    }
}
