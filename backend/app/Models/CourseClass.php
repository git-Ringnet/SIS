<?php

namespace App\Models;

use App\Support\StudentSchedule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Lớp học thuộc một khóa. Học viên gán vào lớp qua students.assigned_class = tên lớp.
 * Lớp có thể có các khung giờ (thứ + giờ + giáo viên, mỗi ca giờ riêng); học viên trong lớp nhận lịch học theo lớp.
 */
class CourseClass extends Model
{
    protected $guarded = [];

    protected $casts = [
        'capacity' => 'integer',
        'schedules' => 'array',
    ];

    protected $appends = ['students_count'];

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    /**
     * Số học viên đang học trong lớp.
     */
    public function getStudentsCountAttribute(): int
    {
        return Student::where('assigned_class', $this->name)->where('status', 'active')->count();
    }

    public function isFull(?int $exceptStudentId = null): bool
    {
        if (! $this->capacity) {
            return false;
        }

        $count = Student::where('assigned_class', $this->name)->where('status', 'active')
            ->when($exceptStudentId, fn ($q) => $q->whereKeyNot($exceptStudentId))
            ->count();

        return $count >= $this->capacity;
    }

    /**
     * Lịch học học viên nhận khi vào lớp: các ca của lớp (thứ + giờ + giáo viên), phòng và cơ sở của lớp.
     * Trả về null nếu lớp chưa có khung giờ.
     */
    public function studentSchedule(): ?array
    {
        $slots = StudentSchedule::normalize(array_map(fn ($s) => [
            'id' => 'c'.$this->id.'-'.($s['day'] ?? '').'-'.str_replace(':', '', (string) ($s['start'] ?? '')),
            'day' => $s['day'] ?? null,
            'start' => $s['start'] ?? null,
            'end' => $s['end'] ?? null,
            'teacher' => $s['teacher'] ?? null,
            'room' => $this->room,
        ], $this->schedules ?? []));

        if ($slots === []) {
            return null;
        }

        return array_filter([
            ...StudentSchedule::legacyColumns($slots),
            'schedules' => $slots,
            'center' => $this->center,
        ], fn ($v) => $v !== null && $v !== '');
    }

    public function hasSlots(): bool
    {
        return ! empty($this->schedules);
    }

    /**
     * Áp lịch của lớp cho toàn bộ học viên trong lớp. Trả về số học viên đã cập nhật (null nếu không áp được).
     */
    public function syncStudents(): ?int
    {
        $schedule = $this->studentSchedule();
        if (! $schedule) {
            return null;
        }

        $students = Student::where('assigned_class', $this->name)->get();
        foreach ($students as $student) {
            $student->update($schedule);
            StudentSchedule::relinkAttendanceSlots($student);
        }

        return $students->count();
    }
}
