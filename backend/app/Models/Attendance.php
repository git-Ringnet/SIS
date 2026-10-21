<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Attendance extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'attended_at' => 'datetime',
        'session_number' => 'integer',
        'deducts_session' => 'boolean',
    ];

    /**
     * Buổi này đã trừ 1 buổi học của học viên: có mặt, vắng không phép, hoặc nghỉ có phép khi đã hết lượt nghỉ.
     * (Bản ghi cũ chưa có cột deducts_session: chỉ buổi có mặt mới trừ buổi.)
     */
    public function deductsSession(): bool
    {
        return $this->status === 'present' || $this->deducts_session;
    }

    /**
     * Ghi chú hệ thống tự điền khi bấm trạng thái điểm danh / phụ huynh xin nghỉ, không phải báo bài của giáo viên.
     */
    public static function isAutoNote(?string $note): bool
    {
        $note = trim((string) $note);

        return $note === ''
            || in_array($note, ['Vắng học', 'Nghỉ có phép', 'Vắng không phép', 'Điểm danh hôm nay'], true)
            || preg_match('/^Điểm danh buổi #\d*$/u', $note) === 1
            || str_starts_with($note, 'PH xin nghỉ qua Cổng phụ huynh');
    }

    /**
     * Giáo viên đã báo bài (nhận xét buổi học) cho buổi này.
     */
    public function hasLessonReport(): bool
    {
        return ! self::isAutoNote($this->note);
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }
}
