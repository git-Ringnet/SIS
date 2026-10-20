<?php

namespace App\Models;

use App\Models\Concerns\LogsActivity;
use App\Support\StudentSchedule;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Student extends Model
{
    use HasFactory, LogsActivity;

    public const ACTIVITY_SUBJECT = 'student';

    protected $guarded = [];

    protected $appends = [
        'progress_percent',
        'attendance_dots',
        'attendance_status',
        'registered_courses_list',
        'schedule_slots',
    ];

    protected $casts = [
        'dob' => 'date:Y-m-d',
        'join_date' => 'date:Y-m-d',
        'end_date' => 'date:Y-m-d',
        'paused_until' => 'date:Y-m-d',
        'last_retention_contact_at' => 'datetime',
        'total_sessions' => 'integer',
        'remaining_sessions' => 'integer',
        'consecutive_absences' => 'integer',
        'allowed_absences' => 'integer',
        'absences_used' => 'integer',
        'supplies' => 'array',
        'class_days' => 'array',
        'schedules' => 'array',
        'registered_courses' => 'array',
        'birthday_gift_years' => 'array',
    ];

    /**
     * Mã học viên tiếp theo dựa trên số lớn nhất trong các mã HV-xxx đã có
     * (không dựa vào id vì dữ liệu cũ có mã không khớp id, ví dụ HV-101 cho id 1).
     */
    public static function nextCode(): string
    {
        $max = static::where('code', 'like', 'HV-%')
            ->pluck('code')
            ->map(fn ($code) => (int) substr($code, 3))
            ->max() ?? 0;

        return 'HV-'.str_pad((string) ($max + 1), 3, '0', STR_PAD_LEFT);
    }

    public const WEEKDAY_LABELS = [1 => 'T2', 2 => 'T3', 3 => 'T4', 4 => 'T5', 5 => 'T6', 6 => 'T7', 0 => 'CN'];

    /**
     * Mô tả lịch học ngắn gọn, ví dụ "T3, T5 • 17:30 - 18:30".
     */
    public function scheduleSummary(): ?string
    {
        $days = collect($this->class_days ?? [])
            ->map(fn ($d) => (int) $d)
            ->sortBy(fn ($d) => $d === 0 ? 7 : $d)
            ->map(fn ($d) => self::WEEKDAY_LABELS[$d] ?? null)
            ->filter()
            ->implode(', ');

        if ($days === '') {
            return null;
        }

        $time = $this->class_start_time
            ? ' • '.$this->class_start_time.($this->class_end_time ? ' - '.$this->class_end_time : '')
            : '';

        return $days.$time;
    }

    /**
     * Danh sách ca học (nhiều ca / ngày), xem App\Support\StudentSchedule.
     */
    public function getScheduleSlotsAttribute(): array
    {
        return StudentSchedule::slots($this);
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function attendances(): HasMany
    {
        return $this->hasMany(Attendance::class)->orderBy('attended_at', 'desc');
    }

    public function latestAttendance(): HasOne
    {
        return $this->hasOne(Attendance::class)->latestOfMany('attended_at');
    }

    public function retentionLogs(): HasMany
    {
        return $this->hasMany(RetentionLog::class)->orderBy('contact_date', 'desc');
    }

    public function latestRetentionLog(): HasOne
    {
        return $this->hasOne(RetentionLog::class)->latestOfMany('contact_date');
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class)->orderBy('created_at', 'desc');
    }

    // Scopes for high performance queries
    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeExpiring($query, $threshold = 5)
    {
        return $query->where('status', 'active')
            ->where('remaining_sessions', '<=', $threshold);
    }

    public function scopeConsecutiveAbsences($query, $threshold = 2)
    {
        return $query->where('status', 'active')
            ->where('consecutive_absences', '>=', $threshold);
    }

    // Progress percentage helper
    public function getProgressPercentAttribute(): int
    {
        if ($this->total_sessions <= 0) {
            return 0;
        }
        $attended = max(0, $this->total_sessions - $this->remaining_sessions);

        return min(100, (int) round(($attended / $this->total_sessions) * 100));
    }

    /**
     * Dãy 5 chấm điểm danh gần nhất (SenyApp attendance dots)
     * 🟢 present | 🔴 absent | 🟡 excused | ⚪ upcoming
     */
    public function getAttendanceDotsAttribute(): array
    {
        $attendances = $this->relationLoaded('attendances')
            ? $this->attendances->sortByDesc('attended_at')->take(5)->reverse()->values()
            : $this->attendances()->orderBy('attended_at', 'desc')->take(5)->get()->reverse()->values();

        $dots = [];
        foreach ($attendances as $att) {
            $status = match ($att->status) {
                'present' => 'present',
                'absent_with_leave' => 'excused',
                default => 'absent',
            };
            $dots[] = [
                'status' => $status,
                'date' => $att->attended_at ? Carbon::parse($att->attended_at)->format('d/m') : '',
                'session' => $att->session_number,
                'note' => $att->note,
            ];
        }

        // Bù các buổi chưa diễn ra cho đủ 5 chấm
        while (count($dots) < 5) {
            $dots[] = [
                'status' => 'upcoming',
                'date' => '',
                'session' => null,
                'note' => 'Chưa diễn ra',
            ];
        }

        return $dots;
    }

    /**
     * Trạng thái chuyên cần (5 cấp độ theo chuẩn SenyApp)
     * excellent (🔥), good (😊), attention (⚠️), at_risk (🚨), returned (💪)
     */
    public function getAttendanceStatusAttribute(): string
    {
        // 1. Nguy cơ nghỉ học: vắng liên tiếp từ 2 buổi trở lên
        if ($this->consecutive_absences >= 2) {
            return 'at_risk';
        }

        // 2. Cần quan tâm: vắng 1 buổi gần đây
        if ($this->consecutive_absences === 1) {
            return 'attention';
        }

        // 3. Đã quay lại học đều: từng có vắng nhưng buổi gần nhất đã đi học lại
        $latest = $this->relationLoaded('latestAttendance') ? $this->latestAttendance : $this->latestAttendance()->first();
        if ($this->absences_used > 0 && $this->consecutive_absences === 0 && $latest && $latest->status === 'present') {
            return 'returned';
        }

        // 4. Chuyên cần xuất sắc: đi học đầy đủ, chuỗi liên tục không vắng
        if ($this->consecutive_absences === 0 && $this->absences_used === 0) {
            return 'excellent';
        }

        // 5. Chuyên cần tốt
        return 'good';
    }

    /**
     * Danh sách các khóa học đã đăng ký của học viên.
     */
    public function getRegisteredCoursesListAttribute(): array
    {
        if (! empty($this->registered_courses) && is_array($this->registered_courses)) {
            return $this->registered_courses;
        }

        if ($this->course_id) {
            return [
                [
                    'id' => 1,
                    'course_id' => (int) $this->course_id,
                    'course_name' => $this->course?->name ?? 'Khóa học',
                    'total_sessions' => (int) $this->total_sessions,
                    'attended_sessions' => max(0, (int) $this->total_sessions - (int) $this->remaining_sessions),
                    'remaining_sessions' => (int) $this->remaining_sessions,
                    'start_date' => $this->join_date ? Carbon::parse($this->join_date)->format('Y-m-d') : null,
                    'end_date' => $this->end_date ? Carbon::parse($this->end_date)->format('Y-m-d') : null,
                    'schedule_info' => $this->schedule_info ?? '1 buổi/tuần',
                    'sessions_per_week' => '1 buổi/tuần',
                    'allowed_absences' => (int) ($this->allowed_absences ?? 3),
                    'absences_used' => (int) ($this->absences_used ?? 0),
                    'status' => $this->remaining_sessions <= 0 ? 'completed' : 'active',
                ],
            ];
        }

        return [];
    }
}
