<?php

namespace App\Support;

use App\Models\Attendance;
use App\Models\Student;
use Carbon\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Ghi nhận điểm danh một ca học (dùng chung cho trang Điểm danh và đơn xin nghỉ ở Cổng phụ huynh).
 *
 * Quy tắc trừ buổi:
 * - Có mặt: trừ 1 buổi.
 * - Nghỉ có phép: không trừ buổi, dùng 1 lượt nghỉ của khoá; hết lượt nghỉ thì trừ 1 buổi như vắng không phép.
 * - Vắng không phép: trừ 1 buổi.
 * Đổi trạng thái / hoàn tác thì trả lại đúng phần đã trừ trước đó.
 */
class AttendanceMarker
{
    public const PARENT_PORTAL = 'Phụ huynh (Cổng PH)';

    /**
     * @return array{attendance: ?Attendance, message: string}
     *
     * @throws ValidationException khi học viên đã hết buổi mà điểm danh có mặt, hoặc ca không có trong lịch của ngày đó
     */
    public static function mark(Student $student, string $date, string $status, ?string $note, ?string $slotId, string $markedBy = 'Giáo viên / Admin'): array
    {
        // Ca phải thuộc lịch của chính ngày điểm danh (vd đổi ngày trên trang Điểm danh rồi bấm khi danh sách cũ chưa tải lại
        // sẽ gửi ca Thứ 5 cho ngày Thứ 3). Hoàn tác thì vẫn cho, để gỡ được bản ghi lệch đã có.
        if ($slotId !== null && $status !== 'not_checked' && ! in_array($slotId, self::slotIdsOn($student, $date), true)) {
            throw ValidationException::withMessages([
                'slot_id' => 'Ca này không có trong lịch học ngày '.Carbon::parse($date)->format('d/m/Y').'. Hãy tải lại trang rồi điểm danh lại.',
            ]);
        }

        $isFirstSlot = $slotId === null || StudentSchedule::firstSlotIdOn($student, $date) === $slotId;

        // Điểm danh đã có của đúng ca này (bản ghi cũ không có ca được tính cho ca đầu tiên)
        $records = Attendance::where('student_id', $student->id)->whereDate('attended_at', $date)->get();
        $existing = $records->firstWhere('slot_id', $slotId)
            ?? ($isFirstSlot ? $records->first(fn ($r) => $r->slot_id === null) : null);

        $itemId = $existing?->course_item_id
            ?? (StudentSchedule::courseItemForSlot($student, $slotId)['id'] ?? null);

        if ($existing) {
            self::revert($student, $existing);
        }

        if ($status === 'not_checked') {
            if ($existing) {
                if ($existing->status !== 'present' && $student->consecutive_absences > 0) {
                    $student->consecutive_absences--;
                }
                $existing->delete();
                $student->save();
                StudentSchedule::syncOfficialNumbers($student);
            }

            return ['attendance' => null, 'message' => "Đã hoàn tác điểm danh cho {$student->name}."];
        }

        $deducts = self::apply($student, $itemId, $status);

        if ($status === 'present') {
            $student->consecutive_absences = 0;
        } elseif (! $existing || $existing->status === 'present') {
            $student->consecutive_absences++;
        }

        // Số buổi chính thức gán lại sau khi lưu (syncOfficialNumbers): chỉ buổi theo lịch có trừ buổi mới có số,
        // nghỉ có phép không trừ buổi / học bù / học lẻ không chiếm số buổi ("nhảy buổi")
        $sessionNumber = null;

        if ($existing) {
            $existing->status = $status;
            $existing->deducts_session = $deducts;
            $existing->session_number = $sessionNumber;
            // Đổi trạng thái (ghi chú tự điền) không ghi đè báo bài giáo viên đã nhập hay lý do phụ huynh xin nghỉ
            $keepsNote = $existing->hasLessonReport() || str_starts_with((string) $existing->note, 'PH xin nghỉ');
            if ($note && ! (Attendance::isAutoNote($note) && $keepsNote)) {
                $existing->note = $note;
            }
            $existing->slot_id ??= $slotId;
            $existing->course_item_id ??= $itemId;
            $existing->save();
            $attendance = $existing;
        } else {
            $attendance = Attendance::create([
                'student_id' => $student->id,
                'course_id' => StudentSchedule::courseItem($student, $itemId)['course_id'] ?? $student->course_id,
                'slot_id' => $slotId,
                'course_item_id' => $itemId,
                'session_number' => $sessionNumber,
                'attended_at' => Carbon::parse("{$date} ".self::startTime($student, $slotId, $date)),
                'status' => $status,
                'deducts_session' => $deducts,
                'note' => $note ?: ($status === 'present' ? 'Điểm danh buổi #' : 'Vắng học'),
                'marked_by' => $markedBy,
            ]);
        }

        $student->save();
        StudentSchedule::syncOfficialNumbers($student);
        $attendance->refresh();

        return ['attendance' => $attendance, 'message' => self::message($student, $status, $deducts, $itemId)];
    }

    /**
     * Mã các ca có học ngày $date: ca theo lịch và buổi học lẻ / học bù đặt cho ngày đó.
     */
    private static function slotIdsOn(Student $student, string $date): array
    {
        $date = Carbon::parse($date)->toDateString();

        return [
            ...array_column(StudentSchedule::slotsOnDate($student, $date), 'id'),
            ...array_column(array_filter(SingleLessons::forStudent($student->id), fn ($l) => ($l['date'] ?? null) === $date), 'id'),
        ];
    }

    /**
     * Xoá hẳn một bản ghi điểm danh và trả lại phần đã trừ (buổi học hoặc lượt nghỉ có phép), đánh số lại các buổi.
     */
    public static function remove(Student $student, Attendance $attendance): void
    {
        self::revert($student, $attendance);
        $attendance->delete();
        $student->save();
        StudentSchedule::syncOfficialNumbers($student);
    }

    /**
     * Trả lại phần đã trừ của một bản ghi điểm danh (buổi học hoặc lượt nghỉ có phép). Không lưu.
     */
    private static function revert(Student $student, Attendance $attendance): void
    {
        if ($attendance->deductsSession()) {
            StudentSchedule::consume($student, $attendance->course_item_id, -1);
        } elseif ($attendance->status === 'absent_with_leave') {
            StudentSchedule::useLeave($student, $attendance->course_item_id, -1);
        }
    }

    /**
     * Áp trạng thái mới, trả về true nếu buổi này trừ 1 buổi học. Không lưu.
     */
    private static function apply(Student $student, ?int $itemId, string $status): bool
    {
        $remaining = StudentSchedule::remainingSessions($student, $itemId);

        if ($status === 'present') {
            if ($remaining <= 0) {
                throw ValidationException::withMessages(['status' => 'Học viên đã hết buổi của khóa này!']);
            }
            StudentSchedule::consume($student, $itemId, 1);

            return true;
        }

        if ($status === 'absent_with_leave' && StudentSchedule::leaveLeft($student, $itemId) > 0) {
            StudentSchedule::useLeave($student, $itemId, 1);

            return false;
        }

        // Vắng không phép, hoặc nghỉ có phép khi đã hết lượt nghỉ: mất 1 buổi (nếu khoá còn buổi)
        if ($remaining > 0) {
            StudentSchedule::consume($student, $itemId, 1);

            return true;
        }

        return false;
    }

    private static function startTime(Student $student, ?string $slotId, string $date): string
    {
        // Giờ của buổi học: theo ca; buổi học lẻ theo giờ đã đặt; không có thì giờ bấm (hôm nay) hoặc đầu ngày
        $slot = StudentSchedule::find($student, $slotId);
        $lesson = $slot ? null : SingleLessons::find($slotId);

        return $slot['start']
            ?? ($lesson ? SingleLessons::startTime($lesson) : null)
            ?? (Carbon::parse($date)->isToday() ? Carbon::now()->format('H:i') : '00:00');
    }

    private static function message(Student $student, string $status, bool $deducts, ?int $itemId): string
    {
        $left = StudentSchedule::leaveLeft($student, $itemId);

        return match (true) {
            $status === 'present' => "Điểm danh thành công cho {$student->name}!",
            $status === 'absent_with_leave' && ! $deducts => "Đã ghi nhận {$student->name} nghỉ có phép, không trừ buổi (còn được nghỉ {$left} buổi).",
            $status === 'absent_with_leave' => "{$student->name} đã hết số buổi được nghỉ có phép: buổi này bị trừ 1 buổi như vắng không phép.",
            $deducts => "Đã ghi nhận {$student->name} vắng không phép, trừ 1 buổi.",
            default => "Đã ghi nhận {$student->name} vắng học.",
        };
    }
}
