<?php

namespace App\Support;

use App\Models\Student;

/**
 * Cảnh báo (không chặn) khi xếp lịch học: phòng vượt sức chứa, giáo viên bị trùng giờ.
 * Xét từng ca của học viên; hai ca trùng khi cùng thứ và khoảng giờ giao nhau (17:30-18:30 trùng 18:00-19:00).
 */
class ScheduleConflicts
{
    private const DAY_LABELS = [1 => 'Thứ 2', 2 => 'Thứ 3', 3 => 'Thứ 4', 4 => 'Thứ 5', 5 => 'Thứ 6', 6 => 'Thứ 7', 0 => 'Chủ nhật'];

    // Ca không nhập giờ kết thúc thì coi như học 60 phút
    private const DEFAULT_MINUTES = 60;

    /**
     * @return list<string>
     */
    public static function warningsFor(Student $student): array
    {
        $mySlots = StudentSchedule::slots($student);
        if ($student->status !== 'active' || $mySlots === []) {
            return [];
        }

        // Ca của các học viên khác đang học: [student, slot]
        $others = Student::where('status', 'active')
            ->whereKeyNot($student->getKey())
            ->whereNotNull('class_days')
            ->get()
            ->flatMap(fn (Student $o) => array_map(fn ($slot) => ['student' => $o, 'slot' => $slot], StudentSchedule::slots($o)));

        $warnings = [];
        foreach ($mySlots as $slot) {
            [$start, $end] = self::range($slot);
            $sameTime = $others->filter(function ($e) use ($slot, $start, $end) {
                [$oStart, $oEnd] = self::range($e['slot']);

                return (int) $e['slot']['day'] === (int) $slot['day'] && $oStart < $end && $start < $oEnd;
            });
            $label = (self::DAY_LABELS[(int) $slot['day']] ?? '').' '.StudentSchedule::timeLabel($slot);

            // 1. Phòng vượt sức chứa (chỉ khi phòng có khai báo sức chứa ở màn Trung tâm)
            if ($slot['room']) {
                $capacity = self::capacity($student->center, $slot['room']);
                $inRoom = $sameTime->filter(fn ($e) => $e['slot']['room'] === $slot['room'] && $e['student']->center === $student->center)->count() + 1;
                if ($capacity !== null && $inRoom > $capacity) {
                    $warnings[] = "Phòng {$slot['room']} {$label} có {$inRoom} học viên, vượt sức chứa {$capacity}.";
                }
            }

            // 2. Giáo viên đang dạy ở phòng / cơ sở khác cùng giờ (cùng phòng cùng giờ = lớp nhóm, không tính)
            if ($slot['teacher']) {
                $clash = $sameTime->first(fn ($e) => TeacherName::same($e['slot']['teacher'], $slot['teacher'])
                    && ($e['slot']['room'] !== $slot['room'] || $e['student']->center !== $student->center));
                if ($clash) {
                    $where = $clash['slot']['room'] ? "phòng {$clash['slot']['room']}" : 'chưa xếp phòng';
                    $warnings[] = "Giáo viên {$slot['teacher']} {$label} đã có lịch dạy {$clash['student']->name} "
                        .StudentSchedule::timeLabel($clash['slot'])." ({$where}), trùng giờ.";
                }
            }
        }

        // 3. Hai ca của chính học viên chồng giờ nhau
        foreach ($mySlots as $i => $a) {
            foreach (array_slice($mySlots, $i + 1) as $b) {
                [$aStart, $aEnd] = self::range($a);
                [$bStart, $bEnd] = self::range($b);
                if ((int) $a['day'] === (int) $b['day'] && $aStart < $bEnd && $bStart < $aEnd) {
                    $warnings[] = 'Lịch học có 2 ca chồng giờ nhau: '.(self::DAY_LABELS[(int) $a['day']] ?? '').' '
                        .StudentSchedule::timeLabel($a).' và '.StudentSchedule::timeLabel($b).'.';
                }
            }
        }

        return array_values(array_unique($warnings));
    }

    /**
     * Khoảng giờ của ca theo phút trong ngày.
     *
     * @return array{int, int}
     */
    private static function range(array $slot): array
    {
        $start = self::minutes($slot['start']);
        $end = ! empty($slot['end']) ? self::minutes($slot['end']) : $start + self::DEFAULT_MINUTES;

        return [$start, max($end, $start + 1)];
    }

    private static function minutes(string $time): int
    {
        [$h, $m] = array_map('intval', explode(':', $time) + [0, 0]);

        return $h * 60 + $m;
    }

    private static function capacity(?string $center, string $room): ?int
    {
        foreach (CenterStore::all() as $c) {
            if ($center && $c['name'] !== $center) {
                continue;
            }
            foreach ($c['rooms'] ?? [] as $r) {
                if ($r['name'] === $room) {
                    return (int) ($r['capacity'] ?? 1);
                }
            }
        }

        return null;
    }
}
