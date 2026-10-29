<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Course;
use App\Models\Student;
use App\Models\User;
use App\Support\PagePermissions;
use App\Support\StudentSchedule;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Quy tắc nghỉ của trung tâm và báo buổi cho phụ huynh:
 * nghỉ có phép không trừ buổi (trong số buổi được nghỉ của khóa), vắng không phép mất 1 buổi,
 * ngày trung tâm off / giáo viên nghỉ không tính buổi, buổi bù, xin nghỉ theo từng buổi ở Cổng phụ huynh.
 */
class LeaveRulesTest extends TestCase
{
    use RefreshDatabase;

    private const MONDAY = '2026-10-19';

    private const THURSDAY = '2026-10-22';

    private Course $course;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Carbon::setTestNow(self::MONDAY.' 08:00:00');
        Sanctum::actingAs(User::factory()->create()); // chủ trung tâm
        // Khóa 1 tháng: 8 buổi, được nghỉ có phép 1 buổi
        $this->course = Course::create(['code' => 'P1', 'name' => 'Piano 1 tháng', 'price' => 1, 'default_sessions' => 8, 'allowed_absences' => 1]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * Học viên học T2 và T5 lúc 17:00 với Cô Mai, 8 buổi.
     */
    private function createStudent(): Student
    {
        $id = $this->postJson('/api/students', [
            'name' => 'Bé An', 'gender' => 'Nam', 'parent_name' => 'PH', 'parent_phone' => '0900001234',
            'course_id' => $this->course->id, 'total_sessions' => 8, 'status' => 'active',
            'schedules' => [
                ['day' => 1, 'start' => '17:00', 'end' => '18:00', 'teacher' => 'Cô Mai', 'room' => 'P1'],
                ['day' => 4, 'start' => '17:00', 'end' => '18:00', 'teacher' => 'Cô Mai', 'room' => 'P1'],
            ],
        ])->assertCreated()->json('student.id');

        return Student::find($id);
    }

    private function mark(Student $student, string $date, string $status)
    {
        return $this->postJson("/api/attendance/{$student->id}/mark", ['date' => $date, 'status' => $status]);
    }

    private function portal(Student $student): array
    {
        return $this->postJson("/api/portal/students/{$student->id}/verify", ['phone_last4' => '1234'])->assertOk()->json();
    }

    public function test_leave_with_permission_does_not_deduct_until_allowance_runs_out(): void
    {
        $student = $this->createStudent();
        $this->assertSame(1, $student->allowed_absences); // lấy theo khóa

        // Nghỉ có phép lần 1: còn lượt nghỉ → không trừ buổi
        $this->mark($student, self::MONDAY, 'absent_with_leave')->assertOk()
            ->assertJsonPath('message', 'Đã ghi nhận Bé An nghỉ có phép, không trừ buổi (còn được nghỉ 0 buổi).');
        $student->refresh();
        $this->assertSame(8, $student->remaining_sessions);
        $this->assertSame(1, $student->absences_used);
        $first = Attendance::where('student_id', $student->id)->first();
        $this->assertFalse($first->deducts_session);
        $this->assertNull($first->session_number); // không chiếm số buổi → không "nhảy buổi"

        // Nghỉ có phép lần 2: hết lượt → trừ 1 buổi như vắng không phép
        $this->mark($student, self::THURSDAY, 'absent_with_leave')->assertOk();
        $student->refresh();
        $this->assertSame(7, $student->remaining_sessions);
        $this->assertSame(1, $student->absences_used);
        $this->assertTrue(Attendance::whereDate('attended_at', self::THURSDAY)->first()->deducts_session);

        // Hoàn tác buổi T2 → trả lại lượt nghỉ; hoàn tác T5 → trả lại buổi học
        $this->mark($student, self::MONDAY, 'not_checked')->assertOk();
        $this->mark($student, self::THURSDAY, 'not_checked')->assertOk();
        $student->refresh();
        $this->assertSame(8, $student->remaining_sessions);
        $this->assertSame(0, $student->absences_used);
    }

    public function test_absent_without_leave_loses_a_session_and_status_changes_revert_correctly(): void
    {
        $student = $this->createStudent();

        $this->mark($student, self::MONDAY, 'absent_without_leave')->assertOk();
        $this->assertSame(7, $student->fresh()->remaining_sessions);

        // Đổi sang có phép: trả lại buổi, dùng lượt nghỉ
        $this->mark($student, self::MONDAY, 'absent_with_leave')->assertOk();
        $student->refresh();
        $this->assertSame([8, 1], [$student->remaining_sessions, $student->absences_used]);

        // Đổi sang có mặt: trả lại lượt nghỉ, trừ 1 buổi
        $this->mark($student, self::MONDAY, 'present')->assertOk();
        $student->refresh();
        $this->assertSame([7, 0], [$student->remaining_sessions, $student->absences_used]);
        $this->assertSame(1, Attendance::first()->session_number);
    }

    public function test_attendance_page_shows_progress_without_jumping_on_excused_absence(): void
    {
        $student = $this->createStudent();
        $this->mark($student, self::MONDAY, 'absent_with_leave')->assertOk();

        $row = $this->getJson('/api/attendance?date='.self::MONDAY)->assertOk()->json('students.0');
        $this->assertSame('absent_with_leave', $row['status']);
        $this->assertFalse($row['deducts_session']);
        $this->assertNull($row['current_session']); // buổi nghỉ có phép không có số buổi chính thức
        $this->assertSame(0, $row['attended_sessions']); // màn điểm danh hiện "Đã học 0/8", không gán số buổi cho buổi nghỉ
        $this->assertSame(8, $row['remaining_sessions']);
        $this->assertSame([1, 0], [$row['allowed_absences'], $row['leave_left']]);
    }

    public function test_attendance_page_and_portal_show_same_session_number_including_makeup(): void
    {
        // T2 19/10 học (Buổi 1), T5 22/10 nghỉ có phép (không số), T7 24/10 học bù (Buổi 2), T2 26/10 học (Buổi 3)
        $student = $this->createStudent();
        $this->mark($student, self::MONDAY, 'present')->assertOk();
        $this->mark($student, self::THURSDAY, 'absent_with_leave')->assertOk();
        $this->postJson('/api/attendance/single-lesson', ['student_id' => $student->id, 'date' => '2026-10-24', 'is_makeup' => true])->assertCreated();
        $makeup = $this->getJson('/api/attendance?date=2026-10-24')->json('students.0');
        $this->postJson("/api/attendance/{$student->id}/mark", ['date' => '2026-10-24', 'status' => 'present', 'slot_id' => $makeup['slot_id']])->assertOk();
        $this->mark($student, '2026-10-26', 'present')->assertOk();

        // Trang Điểm danh
        $makeupRow = $this->getJson('/api/attendance?date=2026-10-24')->json('students.0');
        $this->assertSame([true, true, 2], [$makeupRow['is_makeup'], $makeupRow['deducts_session'], $makeupRow['current_session']]);
        $this->assertSame(3, $this->getJson('/api/attendance?date=2026-10-26')->json('students.0.current_session'));

        // Cổng PH cùng số; không hiện ghi chú tự điền "Điểm danh buổi #n" như nhận xét
        $sessions = collect($this->portal($student)['sessions'])->keyBy('date');
        $this->assertSame([1, null, 2, 3, 4], [$sessions['19/10/2026']['session_number'], $sessions['22/10/2026']['session_number'],
            $sessions['24/10/2026']['session_number'], $sessions['26/10/2026']['session_number'], $sessions['29/10/2026']['session_number']]);
        $this->assertNull($sessions['26/10/2026']['note']);

        // Số lưu trong điểm danh (xuất Excel) cũng là số chính thức, kể cả khi điểm danh bù một ngày cũ
        $this->assertSame([1, 2, 3], Attendance::where('student_id', $student->id)->whereNotNull('session_number')
            ->orderBy('attended_at')->pluck('session_number')->all());
        $this->assertSame('Điểm danh buổi #3', Attendance::whereDate('attended_at', '2026-10-26')->value('note'));
    }

    public function test_quick_attend_button_links_to_makeup_lesson_and_duplicate_booking_is_refused(): void
    {
        // Thứ 7 24/10 (không có ca cố định) chỉ có buổi bù; giáo vụ bấm "Điểm danh hôm nay" ở trang Học viên
        Carbon::setTestNow('2026-10-24 09:00:00');
        $student = $this->createStudent();
        $this->postJson('/api/attendance/single-lesson', ['student_id' => $student->id, 'date' => '2026-10-24', 'time' => '09:00 - 10:00', 'is_makeup' => true])
            ->assertCreated();
        // Đặt trùng (bấm lưu 2 lần) → báo lỗi, không tạo buổi bù thứ hai
        $this->postJson('/api/attendance/single-lesson', ['student_id' => $student->id, 'date' => '2026-10-24', 'time' => '09:00 - 10:00', 'is_makeup' => true])
            ->assertUnprocessable();
        $lessonId = $this->getJson('/api/attendance?date=2026-10-24')->json('students.0.slot_id');

        $this->postJson("/api/students/{$student->id}/attendance")->assertOk();
        $this->assertSame($lessonId, Attendance::where('student_id', $student->id)->value('slot_id'));

        // Cổng PH: một dòng buổi bù đã học (Buổi 1), không còn dòng buổi bù "sắp tới" bị trùng
        $data = $this->portal($student);
        $makeups = collect($data['sessions'])->where('is_makeup', true)->values();
        $this->assertCount(1, $makeups);
        $this->assertSame(['present', 1], [$makeups[0]['status'], $makeups[0]['session_number']]);
        $this->assertSame(1, $data['stats']['makeup_sessions']);
    }

    public function test_old_attendance_without_slot_on_makeup_day_counts_as_makeup_everywhere(): void
    {
        // Bản ghi cũ không gắn ca vào ngày chỉ có buổi bù: trang Điểm danh và Cổng PH đều coi là buổi bù
        $student = $this->createStudent();
        $this->postJson('/api/attendance/single-lesson', ['student_id' => $student->id, 'date' => '2026-10-24', 'is_makeup' => true])->assertCreated();
        Attendance::create(['student_id' => $student->id, 'course_id' => $this->course->id, 'attended_at' => '2026-10-24 18:00:00',
            'status' => 'present', 'deducts_session' => true]);

        $row = $this->getJson('/api/attendance?date=2026-10-24')->json('students.0');
        $this->assertSame([true, 'present', 1], [$row['is_makeup'], $row['status'], $row['current_session']]);
        $makeups = collect($this->portal($student)['sessions'])->where('is_makeup', true)->values();
        $this->assertCount(1, $makeups);
        $this->assertSame(['present', 1], [$makeups[0]['status'], $makeups[0]['session_number']]);
    }

    public function test_editing_schedule_day_keeps_past_attendance_on_its_own_weekday(): void
    {
        // Lịch T2 17:00 (ca a) + T5 17:00 (ca b); đã điểm danh T5 22/10 vào ca b, PH xin nghỉ T5 29/10
        $student = $this->createStudent();
        [$monday, $thursday] = [collect($student->schedules)->firstWhere('day', 1)['id'], collect($student->schedules)->firstWhere('day', 4)['id']];
        $this->postJson("/api/attendance/{$student->id}/mark", ['date' => self::THURSDAY, 'status' => 'present', 'slot_id' => $thursday])->assertOk();
        $this->postJson("/api/portal/students/{$student->id}/leave-request", ['phone_last4' => '1234', 'date' => '2026-10-29',
            'slot_id' => $thursday, 'reason' => 'Ốm'])->assertOk();

        // Sửa lịch: dòng của ca b đổi thành T2 18:00, dòng của ca a đổi thành T5 19:00 (mã ca giữ nguyên theo dòng)
        $this->putJson("/api/students/{$student->id}", [
            ...$student->fresh()->only(['name', 'gender', 'parent_name', 'parent_phone', 'course_id', 'total_sessions', 'remaining_sessions', 'status']),
            'retention_status' => 'normal',
            'schedules' => [
                ['id' => $thursday, 'day' => 1, 'start' => '18:00', 'end' => '19:00', 'teacher' => 'Cô Mai'],
                ['id' => $monday, 'day' => 4, 'start' => '19:00', 'end' => '20:00', 'teacher' => 'Cô Mai'],
            ],
        ])->assertOk();

        // Buổi T5 đã điểm danh / xin nghỉ gắn lại vào ca T5 hiện tại
        $this->assertSame([$monday, $monday], Attendance::where('student_id', $student->id)->orderBy('attended_at')->pluck('slot_id')->all());

        // Cổng PH: buổi T5 hiện giờ của ca T5, không hiện thêm buổi trùng ngày 29/10
        $sessions = collect($this->portal($student)['sessions']);
        $this->assertSame(['Thứ 5', '19:00 - 20:00', 'present'], [$sessions->firstWhere('date', '22/10/2026')['weekday'],
            $sessions->firstWhere('date', '22/10/2026')['time'], $sessions->firstWhere('date', '22/10/2026')['status']]);
        $this->assertSame(['absent_with_leave'], $sessions->where('date', '29/10/2026')->pluck('status')->values()->all());

        // Trang Điểm danh ngày 29/10 thấy đúng buổi PH xin nghỉ
        $this->assertSame('absent_with_leave', $this->getJson('/api/attendance?date=2026-10-29')->json('students.0.status'));
    }

    public function test_attendance_rows_are_sorted_by_start_time(): void
    {
        // Ca T2 17:00 theo lịch + buổi học lẻ gõ tay "9:00 - 10:00" + buổi học lẻ 20:30 cùng ngày
        $student = $this->createStudent();
        $this->postJson('/api/attendance/single-lesson', ['student_id' => $student->id, 'date' => self::MONDAY, 'time' => '20:30 - 21:30'])->assertCreated();
        $this->postJson('/api/attendance/single-lesson', ['student_id' => $student->id, 'date' => self::MONDAY, 'time' => '9:00 - 10:00'])->assertCreated();

        $times = collect($this->getJson('/api/attendance?date='.self::MONDAY)->json('students'))->pluck('shift_time')->all();
        $this->assertSame(['9:00 - 10:00', '17:00 - 18:00', '20:30 - 21:30'], $times);
    }

    public function test_staff_changing_status_keeps_parent_leave_reason(): void
    {
        $student = $this->createStudent();
        $thursday = collect($student->schedules)->firstWhere('day', 4)['id'];
        $this->postJson("/api/portal/students/{$student->id}/leave-request", ['phone_last4' => '1234', 'date' => self::THURSDAY,
            'slot_id' => $thursday, 'reason' => 'Bé đi khám răng'])->assertOk();

        // Giáo vụ bấm "Vắng có phép" rồi "Vắng K.phép" trên trang Điểm danh (gửi kèm ghi chú tự điền)
        foreach (['absent_without_leave' => 'Vắng không phép', 'absent_with_leave' => 'Nghỉ có phép'] as $status => $autoNote) {
            $this->postJson("/api/attendance/{$student->id}/mark", ['date' => self::THURSDAY, 'status' => $status, 'note' => $autoNote, 'slot_id' => $thursday])
                ->assertOk();
            $row = $this->getJson('/api/attendance?date='.self::THURSDAY)->json('students.0');
            $this->assertSame(['Bé đi khám răng', true, ''], [$row['parent_leave_reason'], $row['is_parent_request'], $row['checkin_note']]);
        }
        $this->assertSame('Bé đi khám răng', $this->getJson('/api/attendance?date='.self::THURSDAY)->json('parent_leave_requests.0.reason'));
    }

    public function test_slot_of_another_weekday_is_refused_and_old_duplicate_is_removed_with_refund(): void
    {
        $student = $this->createStudent(); // T2 17:00 (ca a) + T5 17:00 (ca b), 8 buổi
        [$monday, $thursday] = [collect($student->schedules)->firstWhere('day', 1)['id'], collect($student->schedules)->firstWhere('day', 4)['id']];

        // Đang xem T5 22/10 rồi đổi sang T2 19/10, bấm "Đi học" trên dòng cũ (ca T5) → bị chặn
        $this->postJson("/api/attendance/{$student->id}/mark", ['date' => self::MONDAY, 'status' => 'present', 'slot_id' => $thursday])
            ->assertJsonValidationErrors('slot_id');
        $this->assertSame(0, Attendance::count());

        // Dữ liệu cũ đã bị ghi kiểu đó: T2 19/10 có bản ghi ca T5 (thừa) + bản ghi đúng ca T2 → trừ 2 buổi
        $this->postJson("/api/attendance/{$student->id}/mark", ['date' => self::MONDAY, 'status' => 'present', 'slot_id' => $monday])->assertOk();
        $student->refresh();
        $bad = Attendance::create(['student_id' => $student->id, 'course_item_id' => 1, 'slot_id' => $thursday,
            'attended_at' => self::MONDAY.' 17:00:00', 'status' => 'present', 'deducts_session' => true]);
        StudentSchedule::consume($student, 1, 1);
        $student->save();
        $this->assertSame(6, $student->fresh()->remaining_sessions);

        $result = StudentSchedule::relinkAttendanceSlots($student->fresh());
        $this->assertSame([$bad->id], $result['removed']);
        $this->assertSame([$monday], Attendance::where('student_id', $student->id)->pluck('slot_id')->all());
        $this->assertSame(7, $student->fresh()->remaining_sessions); // trả lại buổi bị trừ dư
        $this->assertSame(1, collect($this->portal($student)['sessions'])->where('date', '19/10/2026')->count());
    }

    public function test_attendance_page_shows_only_real_notes_not_auto_filled_ones(): void
    {
        // Bấm "Vắng có phép" (ghi chú tự điền "Nghỉ có phép") khi không có đơn xin nghỉ → không hiện lý do / nhận xét
        $student = $this->createStudent();
        $this->mark($student, self::MONDAY, 'absent_with_leave')->assertOk();
        $this->postJson("/api/attendance/{$student->id}/mark", ['date' => self::MONDAY, 'status' => 'absent_with_leave', 'note' => 'Nghỉ có phép'])->assertOk();
        $row = $this->getJson('/api/attendance?date='.self::MONDAY)->json('students.0');
        $this->assertSame(['', null, false], [$row['checkin_note'], $row['parent_leave_reason'], $row['is_parent_request']]);
    }

    public function test_lesson_report_flag_and_status_change_keeps_report(): void
    {
        $student = $this->createStudent();
        $row = fn () => $this->getJson('/api/attendance?date='.self::MONDAY)->json('students.0');

        // Điểm danh có mặt (ghi chú tự điền) → chưa báo bài
        $this->mark($student, self::MONDAY, 'present')->assertOk();
        $this->assertFalse($row()['has_report']);

        // Giáo viên ghi nhận xét nhiều dòng → đã báo bài
        $report = "Bài cũ: còn chưa thuộc\nBài mới: ABC trang 10\nBTVN: tập 10p";
        $this->postJson("/api/attendance/{$student->id}/mark", ['date' => self::MONDAY, 'status' => 'present', 'note' => $report])->assertOk();
        $this->assertTrue($row()['has_report']);
        $this->assertSame($report, $row()['checkin_note']);

        // Bấm đổi trạng thái (ghi chú tự điền) không ghi đè nhận xét
        $this->postJson("/api/attendance/{$student->id}/mark", ['date' => self::MONDAY, 'status' => 'absent_with_leave', 'note' => 'Nghỉ có phép'])->assertOk();
        $this->assertSame([true, $report], [$row()['has_report'], $row()['checkin_note']]);
    }

    public function test_teacher_only_writes_lesson_report_academic_staff_marks_attendance(): void
    {
        $student = $this->createStudent();
        $owner = auth()->user();
        $login = function (string $name, string $role) {
            $user = User::factory()->create(['name' => $name]);
            Storage::put('staff_data.json', json_encode(['members' => [[
                'id' => 9, 'name' => $name, 'email' => $user->email, 'role' => $role,
                'blocked_pages' => PagePermissions::defaultBlockedPages($role),
            ]]]));
            Sanctum::actingAs($user);
        };
        $note = fn () => $this->postJson("/api/attendance/{$student->id}/note", ['date' => self::MONDAY, 'note' => "Bài cũ: ổn\nBTVN: tập 10p"]);

        // Giáo viên: không điểm danh / đặt buổi học lẻ, chưa điểm danh thì chưa báo bài được
        $login('Cô Mai', 'Giáo viên');
        $this->assertFalse($this->getJson('/api/attendance?date='.self::MONDAY)->json('can_mark'));
        $this->mark($student, self::MONDAY, 'present')->assertForbidden();
        $this->postJson('/api/attendance/single-lesson', ['student_id' => $student->id, 'date' => self::MONDAY])->assertForbidden();
        $note()->assertUnprocessable();

        // Giáo vụ điểm danh được
        $login('Chị Lan', 'Giáo vụ');
        $this->assertTrue($this->getJson('/api/attendance?date='.self::MONDAY)->json('can_mark'));
        $this->mark($student, self::MONDAY, 'present')->assertOk();

        // Giáo viên ghi báo bài cho buổi đã điểm danh: chỉ đổi nhận xét, giữ trạng thái
        $login('Cô Mai', 'Giáo viên');
        $note()->assertOk();
        $row = $this->getJson('/api/attendance?date='.self::MONDAY)->json('students.0');
        $this->assertSame(['present', true, "Bài cũ: ổn\nBTVN: tập 10p"], [$row['status'], $row['has_report'], $row['checkin_note']]);
        $this->assertSame(7, $student->fresh()->remaining_sessions);

        Sanctum::actingAs($owner);
    }

    public function test_center_off_day_is_shown_and_not_numbered_schedule_moves_last_session(): void
    {
        $student = $this->createStudent();
        $this->postJson('/api/teacher-checkin/center-offs', ['from' => self::THURSDAY, 'to' => self::THURSDAY, 'reason' => 'Nghỉ lễ'])
            ->assertCreated();

        // Trang điểm danh: ca T5 là "Trung tâm nghỉ", không tính chưa điểm danh
        $attendance = $this->getJson('/api/attendance?date='.self::THURSDAY)->assertOk()->json();
        $this->assertSame('off', $attendance['students'][0]['status']);
        $this->assertSame('Trung tâm nghỉ: Nghỉ lễ', $attendance['students'][0]['off_label']);
        $this->assertSame([0, 1], [$attendance['metrics']['not_checked'], $attendance['metrics']['off']]);

        $data = $this->portal($student);
        $sessions = collect($data['sessions']);
        $off = $sessions->firstWhere('status', 'off');
        $this->assertSame('22/10/2026', $off['date']);
        $this->assertNull($off['session_number']);
        $this->assertSame(1, $data['stats']['off_sessions']);

        // Buổi T5 nghỉ lễ không đánh số, không trừ buổi: đủ 8 buổi sắp tới (khớp trang Điểm danh), buổi cuối lùi sang 16/11
        $upcoming = $sessions->where('status', 'upcoming')->values();
        $this->assertCount(8, $upcoming);
        $this->assertSame(['19/10/2026', '26/10/2026'], $upcoming->take(2)->pluck('date')->all());
        $this->assertSame(range(1, 8), $upcoming->pluck('session_number')->all());
        $this->assertSame('16/11/2026', $upcoming->last()['date']);
    }

    public function test_sessions_before_start_date_are_not_shown_or_paid(): void
    {
        // Học T2 + T5 (Cô Mai), bắt đầu học thứ Năm 22/10 → T2 19/10 chưa học
        $student = $this->createStudent();
        $student->update(['join_date' => self::THURSDAY]);

        // Trang điểm danh 19/10 không có học viên; 22/10 có
        $this->assertSame([], $this->getJson('/api/attendance?date='.self::MONDAY)->json('students'));
        $this->assertCount(1, $this->getJson('/api/attendance?date='.self::THURSDAY)->json('students'));

        // Không sinh ca dạy (không tính tiền) cho Cô Mai ngày 19/10; ngày 22/10 có ca
        $this->assertSame([], $this->getJson('/api/teacher-checkin?date='.self::MONDAY)->json('today_shifts'));
        $this->assertSame(['Cô Mai'], collect($this->getJson('/api/teacher-checkin?date='.self::THURSDAY)->json('today_shifts'))->pluck('teacher_name')->all());

        // Cổng PH: buổi đầu tiên (dự kiến) là 22/10
        $first = collect($this->portal($student)['sessions'])->firstWhere('status', 'upcoming');
        $this->assertSame('22/10/2026', $first['date']);
    }

    public function test_portal_shows_course_start_and_estimated_end_date(): void
    {
        $student = $this->createStudent();

        // 8 buổi T2 + T5 từ 19/10: 19, 22, 26, 29/10, 02, 05, 09, 12/11
        $course = $this->portal($student)['courses'][0];
        $this->assertSame(['19/10/2026', '12/11/2026', true], [$course['start'], $course['end'], $course['end_estimated']]);

        // Ngày kết thúc cố định theo kế hoạch: trung tâm nghỉ, nghỉ có phép hay đặt buổi bù đều không làm ngày này lùi
        $this->postJson('/api/teacher-checkin/center-offs', ['from' => self::THURSDAY, 'to' => self::THURSDAY])->assertCreated();
        $this->mark($student, self::MONDAY, 'absent_with_leave')->assertOk();
        $this->postJson('/api/attendance/single-lesson', ['student_id' => $student->id, 'date' => '2026-11-14', 'is_makeup' => true])->assertCreated();
        $this->postJson('/api/attendance/single-lesson', ['student_id' => $student->id, 'date' => '2026-10-24'])->assertCreated(); // buổi học lẻ
        $this->mark($student, '2026-10-24', 'present')->assertOk();
        $this->assertSame('12/11/2026', $this->portal($student)['courses'][0]['end']);

        // Học 2 khóa: Piano (ca T2) đã học từ 12/10, Guitar (ca T5) đăng ký thêm, bắt đầu từ 29/10
        $monday = collect($student->schedules)->firstWhere('day', 1)['id'];
        $thursday = collect($student->schedules)->firstWhere('day', 4)['id'];
        $student->update(['join_date' => '2026-10-05', 'registered_courses' => [
            ['id' => 1, 'course_id' => $this->course->id, 'course_name' => 'Piano', 'total_sessions' => 8, 'attended_sessions' => 1,
                'remaining_sessions' => 7, 'start_date' => '2026-10-14', 'status' => 'active', 'schedule_ids' => [$monday]],
            ['id' => 2, 'course_id' => $this->course->id, 'course_name' => 'Guitar', 'total_sessions' => 8, 'attended_sessions' => 0,
                'remaining_sessions' => 8, 'start_date' => '2026-10-29', 'status' => 'active', 'schedule_ids' => [$thursday]],
        ]]);
        Attendance::create(['student_id' => $student->id, 'course_item_id' => 1, 'slot_id' => $monday,
            'attended_at' => '2026-10-12 17:00:00', 'status' => 'present', 'deducts_session' => true, 'session_number' => 1]);

        $courses = collect($this->portal($student)['courses'])->keyBy('course_name');
        // Piano: đã nhập ngày bắt đầu 14/10 → luôn hiện đúng ngày đã nhập dù có điểm danh sớm hơn (12/10)
        $this->assertSame(['14/10/2026', false], [$courses['Piano']['start'], $courses['Piano']['start_estimated']]);
        // Guitar: chưa học → ngày bắt đầu đã nhập của khóa; 8 buổi T5 → kết thúc 17/12
        $this->assertSame(['29/10/2026', false], [$courses['Guitar']['start'], $courses['Guitar']['start_estimated']]);
        $this->assertSame('17/12/2026', $courses['Guitar']['end']);

        // Trang Học viên dùng cùng ngày
        $row = collect($this->getJson('/api/students?page=1&per_page=20')->json('students'))->firstWhere('id', $student->id);
        $this->assertSame('29/10/2026', $row['course_dates'][2]['start']);
    }

    public function test_planned_end_date_counts_sessions_from_course_start(): void
    {
        // Ví dụ của trung tâm: bắt đầu T6 04/09, học T2 + T6, 3 tháng (24 buổi) → kết thúc T2 23/11
        $student = $this->createStudent();
        $student->update(['schedules' => [['id' => 'a', 'day' => 1, 'start' => '17:00'], ['id' => 'b', 'day' => 5, 'start' => '17:00']]]);
        $courses = $student->registered_courses_list;
        $courses[0] = [...$courses[0], 'start_date' => '2026-09-04', 'end_date' => null, 'total_sessions' => 24];
        $student->update(['registered_courses' => $courses]);
        $this->assertSame('23/11/2026', $student->fresh()->courseItemDates($courses[0])['end']);

        // Số buổi lẻ: 23 buổi → buổi cuối là T6 20/11 (không phải ngày học muộn nhất của tuần cuối)
        $courses[0]['total_sessions'] = 23;
        $student->update(['registered_courses' => $courses]);
        $this->assertSame('20/11/2026', $student->fresh()->courseItemDates($courses[0])['end']);

        // Bắt đầu 09/10 (T6), học T5: hiện đúng ngày bắt đầu đã nhập (như popup), lịch đếm từ T5 15/10 → 12 buổi kết thúc 31/12
        Carbon::setTestNow(self::THURSDAY.' 08:00:00');
        $student->update(['schedules' => [['id' => 'c', 'day' => 4, 'start' => '17:00']]]);
        $courses[0] = [...$courses[0], 'start_date' => '2026-10-09', 'total_sessions' => 12, 'sessions_per_week' => ''];
        $student->update(['registered_courses' => $courses]);
        $this->assertSame(['start' => '09/10/2026', 'start_estimated' => false, 'end' => '31/12/2026', 'end_estimated' => true], $student->fresh()->courseItemDates($courses[0]));

        // Khóa đã có thứ (1 ca T5): tính theo thứ đó (12 buổi = 12 tuần kết thúc 31/12), kể cả nhập 2 buổi/tuần
        $courses[0]['sessions_per_week'] = '2';
        $student->update(['registered_courses' => $courses]);
        $this->assertSame('31/12/2026', $student->fresh()->courseItemDates($courses[0])['end']);

        // Xếp 2 ca vào T5 (học 2 ca mỗi thứ Năm): 12 buổi = 6 tuần kết thúc 19/11
        $student->update(['schedules' => [
            ['id' => 'c1', 'day' => 4, 'start' => '17:00'],
            ['id' => 'c2', 'day' => 4, 'start' => '18:00'],
        ]]);
        $this->assertSame('19/11/2026', $student->fresh()->courseItemDates($courses[0])['end']);

        // Chưa nhập lịch tuần: theo số buổi/tuần (bắt đầu 09/10, 1 buổi/tuần, 12 buổi → 25/12), không để "chưa xác định"
        $student->update(['schedules' => null, 'class_days' => null, 'class_start_time' => null, 'schedule_info' => '1 buổi/tuần']);
        $courses[0] = [...$courses[0], 'start_date' => '2026-10-09', 'total_sessions' => 12, 'sessions_per_week' => ''];
        $student->update(['registered_courses' => $courses]);
        $this->assertSame(['25/12/2026', true], array_values(array_slice($student->fresh()->courseItemDates($courses[0]), 2)));
        $courses[0]['sessions_per_week'] = '2';
        $student->update(['registered_courses' => $courses]);
        $this->assertSame('13/11/2026', $student->fresh()->courseItemDates($courses[0])['end']);
    }

    public function test_portal_shows_past_scheduled_session_not_marked_yet(): void
    {
        // Học T2 + T5 từ 19/10, mới điểm danh 19/10; hôm nay 29/10 → 22/10 và 26/10 chưa điểm danh vẫn phải hiện
        $student = $this->createStudent();
        $this->mark($student, self::MONDAY, 'present')->assertOk();
        Carbon::setTestNow('2026-10-29 08:00:00');

        $sessions = collect($this->portal($student)['sessions']);
        $unmarked = $sessions->where('status', 'unmarked')->values();
        $this->assertSame(['22/10/2026', '26/10/2026'], $unmarked->pluck('date')->all());
        // Buổi chưa điểm danh vẫn là một buổi của khóa: có số, buổi sắp tới đánh số tiếp theo
        $this->assertSame([2, 3], $unmarked->pluck('session_number')->all());
        $this->assertFalse($unmarked[0]['can_request_leave']);
        $this->assertSame(['29/10/2026', 4], [$sessions->firstWhere('status', 'upcoming')['date'], $sessions->firstWhere('status', 'upcoming')['session_number']]);
        // Tổng vẫn đủ 8 buổi của khóa (buổi chưa điểm danh dùng 1 buổi còn lại, không cộng thêm buổi sắp tới)
        $this->assertSame(range(1, 8), $sessions->pluck('session_number')->filter()->values()->all());
        // Trang Điểm danh cùng số: 26/10 điểm danh xong vẫn là Buổi 3
        $this->mark($student, '2026-10-26', 'present')->assertOk();
        $this->assertSame(3, $this->getJson('/api/attendance?date=2026-10-26')->json('students.0.current_session'));
        $this->postJson("/api/attendance/{$student->id}/mark", ['date' => '2026-10-26', 'status' => 'not_checked'])->assertOk();

        // Ngày trung tâm nghỉ thì hiện "Trung tâm nghỉ", không phải "Chưa điểm danh"; điểm danh rồi thì hết
        $this->postJson('/api/teacher-checkin/center-offs', ['from' => self::THURSDAY, 'to' => self::THURSDAY])->assertCreated();
        $this->mark($student, '2026-10-26', 'present')->assertOk();
        $sessions = collect($this->portal($student)['sessions']);
        $this->assertSame([], $sessions->where('status', 'unmarked')->values()->all());
        $this->assertSame('22/10/2026', $sessions->firstWhere('status', 'off')['date']);
    }

    public function test_attendance_page_hides_future_days_after_remaining_sessions_run_out(): void
    {
        // 8 buổi T2 + T5 từ hôm nay 19/10: 19, 22, 26, 29/10, 02, 05, 09, 12/11 → T2 16/11 không còn buổi
        $student = $this->createStudent();
        $this->assertCount(1, $this->getJson('/api/attendance?date=2026-11-12')->json('students'));
        $this->assertSame([], $this->getJson('/api/attendance?date=2026-11-16')->json('students'));

        // Phụ huynh xin nghỉ có phép 26/10 (không trừ buổi) → còn đủ 8 buổi, buổi cuối lùi sang 16/11
        $this->mark($student, '2026-10-26', 'absent_with_leave')->assertOk();
        $this->assertCount(1, $this->getJson('/api/attendance?date=2026-10-26')->json('students'));
        $this->assertCount(1, $this->getJson('/api/attendance?date=2026-11-16')->json('students'));
        $this->assertSame([], $this->getJson('/api/attendance?date=2026-11-19')->json('students'));

        // Cổng phụ huynh khớp trang Điểm danh: buổi sắp tới cuối cùng cũng là 16/11
        $upcoming = collect($this->portal($student)['sessions'])->where('status', 'upcoming');
        $this->assertSame('16/11/2026', $upcoming->last()['date']);
    }

    public function test_portal_does_not_flag_sessions_before_system_was_used_as_unmarked(): void
    {
        // Học từ 01/09 nhưng mới dùng hệ thống: 10 buổi trước đó nhập tay ở "Đã học", điểm danh đầu tiên 19/10
        $student = $this->createStudent();
        $courses = $student->registered_courses_list;
        $courses[0] = [...$courses[0], 'start_date' => '2026-09-01', 'total_sessions' => 20, 'attended_sessions' => 10, 'remaining_sessions' => 10];
        $student->update(['join_date' => '2026-09-01', 'registered_courses' => $courses, 'total_sessions' => 20, 'remaining_sessions' => 10]);
        $this->mark($student, self::MONDAY, 'present')->assertOk();
        Carbon::setTestNow('2026-10-27 08:00:00');

        // Chỉ 22/10 và 26/10 (sau buổi điểm danh đầu tiên) là chưa điểm danh; các buổi T2/T5 tháng 9 không hiện
        $sessions = collect($this->portal($student)['sessions']);
        $this->assertSame(['22/10/2026', '26/10/2026'], $sessions->where('status', 'unmarked')->pluck('date')->values()->all());
        $this->assertSame(11, $sessions->firstWhere('status', 'present')['session_number']);
    }

    public function test_planned_end_date_counts_session_learned_before_first_scheduled_day(): void
    {
        // Bắt đầu học T5 29/10 rồi xếp lịch T3, 4 buổi: buổi 29/10 là buổi 1 → T3 03, 10, 17/11 (không phải 24/11)
        $student = $this->createStudent();
        $student->update(['join_date' => '2026-10-29', 'schedules' => [['id' => 't3', 'day' => 2, 'start' => '10:00']]]);
        $courses = $student->registered_courses_list;
        $courses[0] = [...$courses[0], 'start_date' => '2026-10-29', 'end_date' => null, 'total_sessions' => 4, 'schedule_ids' => []];
        $student->update(['registered_courses' => $courses]);
        $this->assertSame('24/11/2026', $student->fresh()->courseItemDates($courses[0])['end']);

        Attendance::create(['student_id' => $student->id, 'course_item_id' => $courses[0]['id'], 'slot_id' => null,
            'attended_at' => '2026-10-29 10:00:00', 'status' => 'present', 'deducts_session' => true, 'session_number' => 1]);
        $this->assertSame('17/11/2026', $student->fresh()->courseItemDates($courses[0])['end']);

        // Buổi bù / học lẻ sau khi đã vào lịch không làm ngày kết thúc đổi
        Attendance::create(['student_id' => $student->id, 'course_item_id' => $courses[0]['id'], 'slot_id' => null,
            'attended_at' => '2026-11-05 10:00:00', 'status' => 'present', 'deducts_session' => true, 'session_number' => 3]);
        $this->assertSame('17/11/2026', $student->fresh()->courseItemDates($courses[0])['end']);
    }

    public function test_teacher_leave_without_substitute_makes_session_off(): void
    {
        $student = $this->createStudent();
        $this->postJson('/api/teacher-checkin/leaves', ['teacher' => 'Cô Mai', 'from' => self::THURSDAY, 'to' => self::THURSDAY])->assertCreated();

        $off = collect($this->portal($student)['sessions'])->firstWhere('status', 'off');
        $this->assertSame(['Giáo viên nghỉ', 'teacher'], [$off['status_label'], $off['off_type']]);

        // Có người dạy thay thì vẫn học bình thường
        $this->postJson('/api/teacher-checkin/substitutions', ['original' => 'Cô Mai', 'substitute' => 'Thầy Bình', 'from' => self::THURSDAY, 'to' => self::THURSDAY])
            ->assertCreated();
        $this->assertNull(collect($this->portal($student)['sessions'])->firstWhere('status', 'off'));
    }

    public function test_parent_requests_leave_for_a_specific_upcoming_session(): void
    {
        $student = $this->createStudent();
        $thursday = collect($student->schedules)->firstWhere('day', 4)['id'];

        $plan = collect($this->portal($student)['sessions'])->firstWhere('date', '22/10/2026');
        $this->assertTrue($plan['can_request_leave']);
        $this->assertSame($thursday, $plan['slot_id']);

        $this->postJson("/api/portal/students/{$student->id}/leave-request", [
            'phone_last4' => '1234', 'date' => self::THURSDAY, 'slot_id' => $thursday, 'reason' => 'Bé bị ốm',
        ])->assertOk()->assertJsonPath('attendance.deducts_session', false);

        $student->refresh();
        $this->assertSame([8, 1], [$student->remaining_sessions, $student->absences_used]);

        // Trang điểm danh ngày T5 thấy đơn xin nghỉ của phụ huynh
        $row = $this->getJson('/api/attendance?date='.self::THURSDAY)->json('students.0');
        $this->assertTrue($row['is_parent_request']);

        // Cổng PH: buổi T5 là Vắng có phép, không đánh số buổi chính thức; không trừ buổi nên vẫn còn đủ 8 buổi sắp tới
        $data = $this->portal($student);
        $sessions = collect($data['sessions']);
        $leave = $sessions->firstWhere('status', 'absent_with_leave');
        $this->assertSame(['22/10/2026', null], [$leave['date'], $leave['session_number']]);
        $this->assertSame('Vắng có phép', $leave['status_label']);
        $this->assertSame([1, 0], [$data['stats']['leave_used'], $data['stats']['leave_left']]);
        $upcoming = $sessions->where('status', 'upcoming')->values();
        $this->assertCount(8, $upcoming);
        $this->assertSame('16/11/2026', $upcoming->last()['date']);
        $this->assertSame([1, 2, 3, 4, 5, 6, 7, 8], $upcoming->pluck('session_number')->all());

        // Đặt buổi bù thủ công → hiển thị buổi bù, đánh số theo thứ tự ngày (Buổi 2, sau buổi 19/10)
        $this->postJson('/api/attendance/single-lesson', ['student_id' => $student->id, 'date' => '2026-10-24', 'is_makeup' => true])->assertCreated();
        $data = $this->portal($student);
        $makeup = collect($data['sessions'])->firstWhere('is_makeup', true);
        $this->assertSame([1, 2], [$data['stats']['makeup_sessions'], $makeup['session_number']]);
        $makeupId = $makeup['slot_id'];
        $this->deleteJson("/api/attendance/single-lesson/{$makeupId}")->assertOk();

        // Xin nghỉ lại cùng buổi / buổi đã qua / ngày không có lịch / ngày trung tâm nghỉ → báo lỗi
        $request = fn (array $data) => $this->postJson("/api/portal/students/{$student->id}/leave-request", ['phone_last4' => '1234', 'reason' => 'x', ...$data]);
        $request(['date' => self::THURSDAY, 'slot_id' => $thursday])->assertUnprocessable();
        $request(['date' => '2026-10-15'])->assertUnprocessable();
        $request(['date' => '2026-10-20'])->assertUnprocessable();
        $this->postJson('/api/teacher-checkin/center-offs', ['from' => '2026-10-26', 'to' => '2026-10-26'])->assertCreated();
        $request(['date' => '2026-10-26'])->assertUnprocessable()->assertJsonPath('message', 'Ngày này Trung tâm nghỉ, phụ huynh không cần xin nghỉ.');
    }

    public function test_makeup_lesson_shows_as_makeup_session_in_portal(): void
    {
        $student = $this->createStudent();
        $this->postJson('/api/attendance/single-lesson', [
            'student_id' => $student->id, 'date' => '2026-10-24', 'time' => '09:00 - 10:00', 'teacher' => 'Cô Mai', 'is_makeup' => true,
        ])->assertCreated();

        $data = $this->portal($student);
        $makeup = collect($data['sessions'])->firstWhere('is_makeup', true);
        $this->assertSame(['24/10/2026', 'Buổi bù', 'upcoming'], [$makeup['date'], $makeup['status_label'], $makeup['status']]);
        $this->assertSame(1, $data['stats']['makeup_sessions']);
        // Buổi bù dùng 1 trong 8 buổi còn lại và được đánh số theo thứ tự ngày (19/10, 22/10, bù 24/10 = Buổi 3)
        $this->assertSame(3, $makeup['session_number']);
        $upcoming = collect($data['sessions'])->where('status', 'upcoming')->values();
        $this->assertSame(range(1, 8), $upcoming->pluck('session_number')->all());
        // Còn 7 buổi theo lịch: 19, 22, 26, 29/10, 02, 05, 09/11
        $this->assertSame('09/11/2026', $upcoming->where('is_makeup', false)->last()['date']);
    }

    public function test_makeup_lesson_booked_by_mistake_can_be_deleted(): void
    {
        $student = $this->createStudent();
        $book = fn () => $this->postJson('/api/attendance/single-lesson', [
            'student_id' => $student->id, 'date' => '2026-10-24', 'time' => '09:00 - 10:00', 'teacher' => 'Cô Mai', 'is_makeup' => true,
        ])->assertCreated();
        $lessonId = fn () => collect($this->getJson('/api/attendance?date=2026-10-24')->json('students'))->firstWhere('is_makeup', true)['slot_id'] ?? null;

        // Buổi đã điểm danh thì phải hoàn tác trước khi xóa
        $book();
        $id = $lessonId();
        $this->postJson("/api/attendance/{$student->id}/mark", ['date' => '2026-10-24', 'status' => 'present', 'slot_id' => $id])->assertOk();
        $this->deleteJson("/api/attendance/single-lesson/{$id}")->assertUnprocessable();
        $this->postJson("/api/attendance/{$student->id}/mark", ['date' => '2026-10-24', 'status' => 'not_checked', 'slot_id' => $id])->assertOk();

        $this->deleteJson("/api/attendance/single-lesson/{$id}")->assertOk()
            ->assertJsonPath('message', 'Đã xóa buổi học bù ngày 24/10/2026 của học viên Bé An.');
        $this->assertNull($lessonId());
        $this->assertSame(0, $this->portal($student)['stats']['makeup_sessions']);
        $this->assertSame(8, $student->fresh()->remaining_sessions);
        $this->deleteJson("/api/attendance/single-lesson/{$id}")->assertNotFound();

        // Đặt nhầm 2 buổi bù cùng ngày (khác giờ): cả 2 đều hiện trên trang Điểm danh để xóa từng buổi
        $book();
        $this->postJson('/api/attendance/single-lesson', [
            'student_id' => $student->id, 'date' => '2026-10-24', 'time' => '14:00 - 15:00', 'is_makeup' => true,
        ])->assertCreated();
        $rows = collect($this->getJson('/api/attendance?date=2026-10-24')->json('students'))->where('is_makeup', true)->values();
        $this->assertSame(['09:00 - 10:00', '14:00 - 15:00'], $rows->pluck('shift_time')->all());
        $this->deleteJson("/api/attendance/single-lesson/{$rows[1]['slot_id']}")->assertOk();
        $this->assertSame(['09:00 - 10:00'], collect($this->getJson('/api/attendance?date=2026-10-24')->json('students'))->where('is_makeup', true)->pluck('shift_time')->values()->all());
    }

    public function test_two_courses_have_separate_leave_allowances(): void
    {
        $student = $this->createStudent();
        $monday = collect($student->schedules)->firstWhere('day', 1)['id'];
        $thursday = collect($student->schedules)->firstWhere('day', 4)['id'];
        // Piano học ca T2, Guitar học ca T5; mỗi khóa được nghỉ có phép 3 buổi, Piano đã nghỉ hết 3 buổi
        $student->update(['registered_courses' => [
            ['id' => 1, 'course_id' => $this->course->id, 'course_name' => 'Piano', 'total_sessions' => 8, 'attended_sessions' => 0,
                'remaining_sessions' => 8, 'allowed_absences' => 3, 'absences_used' => 3, 'status' => 'active', 'schedule_ids' => [$monday]],
            ['id' => 2, 'course_id' => $this->course->id, 'course_name' => 'Guitar', 'total_sessions' => 8, 'attended_sessions' => 0,
                'remaining_sessions' => 8, 'allowed_absences' => 3, 'absences_used' => 0, 'status' => 'active', 'schedule_ids' => [$thursday]],
        ], 'total_sessions' => 16, 'remaining_sessions' => 16]);

        $data = $this->portal($student);
        $this->assertSame([['Piano', 0, 3], ['Guitar', 3, 3]], collect($data['stats']['leave_by_course'])->map(fn ($c) => [$c['course_name'], $c['left'], $c['allowed']])->all());
        $sessions = collect($data['sessions']);
        $this->assertSame([1, 'Piano'], [$sessions->firstWhere('date', '19/10/2026')['course_item_id'], $sessions->firstWhere('date', '19/10/2026')['course_name']]);
        $this->assertSame(2, $sessions->firstWhere('date', '22/10/2026')['course_item_id']);

        $request = fn (string $date, string $slot) => $this->postJson("/api/portal/students/{$student->id}/leave-request", [
            'phone_last4' => '1234', 'date' => $date, 'slot_id' => $slot, 'reason' => 'Bận',
        ])->assertOk()->json('attendance.deducts_session');

        // Buổi Piano: khóa Piano hết lượt nghỉ → trừ buổi; buổi Guitar: còn lượt → không trừ
        $this->assertTrue($request(self::MONDAY, $monday));
        $this->assertFalse($request(self::THURSDAY, $thursday));

        $courses = collect($student->fresh()->registered_courses)->keyBy('course_name');
        $this->assertSame([7, 3], [$courses['Piano']['remaining_sessions'], $courses['Piano']['absences_used']]);
        $this->assertSame([8, 1], [$courses['Guitar']['remaining_sessions'], $courses['Guitar']['absences_used']]);
    }

    public function test_added_course_takes_leave_allowance_from_course(): void
    {
        $student = $this->createStudent();
        $long = Course::create(['code' => 'P6', 'name' => 'Piano 6 tháng', 'price' => 1, 'default_sessions' => 48, 'allowed_absences' => 6]);

        $this->postJson("/api/students/{$student->id}/courses", ['course_id' => $long->id, 'total_sessions' => 48])->assertOk();

        $item = collect($student->fresh()->registered_courses)->firstWhere('course_id', $long->id);
        $this->assertSame(6, $item['allowed_absences']);
    }

    public function test_portal_counts_sessions_learned_before_using_the_system(): void
    {
        // 20 buổi, đã học 12 buổi trước khi dùng hệ thống (không có điểm danh) → còn 8 buổi sắp tới, đánh số từ Buổi 13
        $student = $this->createStudent();
        $student->update(['total_sessions' => 20, 'remaining_sessions' => 8]);

        $upcoming = collect($this->portal($student)['sessions'])->where('status', 'upcoming')->values();
        $this->assertCount(8, $upcoming);
        $this->assertSame([13, 20], [$upcoming->first()['session_number'], $upcoming->last()['session_number']]);
    }

    public function test_updating_student_keeps_entered_sessions_per_week_unless_schedule_changes(): void
    {
        $student = $this->createStudent();
        $courses = $student->registered_courses_list;
        $courses[0] = [...$courses[0], 'schedule_ids' => [], 'sessions_per_week' => '3', 'schedule_info' => '3 buổi/tuần'];
        $student->update(['registered_courses' => $courses]);

        $payload = fn (array $schedules) => [
            'name' => 'Bé An', 'gender' => 'Nam', 'parent_name' => 'PH', 'parent_phone' => '0900001234',
            'course_id' => $this->course->id, 'total_sessions' => 8, 'remaining_sessions' => 8,
            'status' => 'active', 'retention_status' => 'normal', 'schedules' => $schedules,
        ];
        $slots = collect(StudentSchedule::slots($student))->map(fn ($s) => collect($s)->only(['id', 'day', 'start', 'end', 'teacher', 'room'])->all())->all();

        // Sửa thông tin khác, lịch giữ nguyên → không ghi đè số buổi/tuần đã nhập
        $this->putJson("/api/students/{$student->id}", [...$payload($slots), 'notes' => 'ghi chú'])->assertOk();
        $this->assertSame('3', $student->fresh()->registered_courses[0]['sessions_per_week']);

        // Bỏ hết lịch tuần → vẫn giữ số buổi/tuần đã nhập
        $this->putJson("/api/students/{$student->id}", $payload([]))->assertOk();
        $this->assertSame('3', $student->fresh()->registered_courses[0]['sessions_per_week']);

        // Đổi lịch còn 1 ca → cập nhật theo lịch mới
        $this->putJson("/api/students/{$student->id}", $payload([['day' => 2, 'start' => '17:00', 'end' => '18:00']]))->assertOk();
        $item = $student->fresh()->registered_courses[0];
        $this->assertSame(['1', '1 buổi/tuần'], [$item['sessions_per_week'], $item['schedule_info']]);
    }

    public function test_single_lesson_with_old_attendance_without_slot_can_be_undone_and_deleted(): void
    {
        // Buổi học lẻ T7 (ngày không có ca cố định), điểm danh kiểu cũ chưa gắn ca (slot_id = null)
        $student = $this->createStudent();
        $this->postJson('/api/attendance/single-lesson', ['student_id' => $student->id, 'date' => '2026-10-24', 'time' => '09:00 - 10:00'])->assertCreated();
        Attendance::create(['student_id' => $student->id, 'course_id' => $this->course->id, 'attended_at' => '2026-10-24 09:00:00',
            'status' => 'present', 'deducts_session' => true, 'session_number' => 1]);
        $student->update(['remaining_sessions' => 7]);

        $row = $this->getJson('/api/attendance?date=2026-10-24')->json('students.0');
        $this->assertSame([true, 'present'], [$row['is_single_lesson'], $row['status']]);
        $this->deleteJson("/api/attendance/single-lesson/{$row['slot_id']}")->assertStatus(422);

        // Hoàn tác trên đúng dòng buổi lẻ → trả lại buổi, sau đó xóa được buổi
        $this->postJson("/api/attendance/{$student->id}/mark", ['date' => '2026-10-24', 'status' => 'not_checked', 'slot_id' => $row['slot_id']])->assertOk();
        $this->assertSame(0, Attendance::where('student_id', $student->id)->count());
        $this->assertSame(8, $student->fresh()->remaining_sessions);
        $this->deleteJson("/api/attendance/single-lesson/{$row['slot_id']}")->assertOk();
        $this->assertSame([], $this->getJson('/api/attendance?date=2026-10-24')->json('students'));
    }

    public function test_teacher_leave_for_a_time_range_only_makes_those_sessions_off(): void
    {
        // Học viên học T5 hai ca với Cô Mai: 16:00 và 17:00
        $student = $this->createStudent();
        $student->update(['schedules' => [
            ['id' => 'a', 'day' => 4, 'start' => '16:00', 'end' => '17:00', 'teacher' => 'Cô Mai', 'room' => 'P1'],
            ['id' => 'b', 'day' => 4, 'start' => '17:00', 'end' => '18:00', 'teacher' => 'Cô Mai', 'room' => 'P1'],
        ]]);

        // Đến giờ phải sau Từ giờ, nhập giờ thì phải đủ cả hai
        $this->postJson('/api/teacher-checkin/leaves', ['teacher' => 'Cô Mai', 'from' => self::THURSDAY, 'to' => self::THURSDAY, 'start_time' => '17:00', 'end_time' => '16:00'])
            ->assertJsonValidationErrors('end_time');
        $this->postJson('/api/teacher-checkin/leaves', ['teacher' => 'Cô Mai', 'from' => self::THURSDAY, 'to' => self::THURSDAY, 'start_time' => '16:00'])
            ->assertJsonValidationErrors('end_time');

        // Cô Mai bận 16:00 - 17:00, không có người dạy thay → chỉ ca 16h nghỉ, ca 17h vẫn học
        $this->postJson('/api/teacher-checkin/leaves', ['teacher' => 'Cô Mai', 'from' => self::THURSDAY, 'to' => self::THURSDAY,
            'start_time' => '16:00', 'end_time' => '17:00', 'reason' => 'Bận việc'])->assertCreated();

        $rows = collect($this->getJson('/api/attendance?date='.self::THURSDAY)->json('students'))->keyBy('slot_id');
        $this->assertSame(['off', 'Giáo viên nghỉ: Bận việc'], [$rows['a']['status'], $rows['a']['off_label']]);
        $this->assertSame(['not_checked', null], [$rows['b']['status'], $rows['b']['off_label']]);

        $thursday = collect($this->portal($student)['sessions'])->filter(fn ($s) => $s['date'] === '22/10/2026')->keyBy('status');
        $this->assertSame('16:00 - 17:00', $thursday['off']['time']);
        $this->assertSame('17:00 - 18:00', $thursday['upcoming']['time']);

        // Trang check-in: ca 16h của Cô Mai vắng có phép, ca 17h vẫn check-in được
        $shifts = collect($this->getJson('/api/teacher-checkin?date='.self::THURSDAY)->json('today_shifts'))->keyBy('start_time');
        $this->assertSame('excused_absence', $shifts['16:00']['status']);
        $this->assertSame('not_checked_in', $shifts['17:00']['status']);

        // Dạy thay theo khung giờ: Thầy Bình dạy thay ca 16h → ca đó học lại bình thường
        $this->postJson('/api/teacher-checkin/substitutions', ['original' => 'Cô Mai', 'substitute' => 'Thầy Bình', 'from' => self::THURSDAY, 'to' => self::THURSDAY,
            'start_time' => '16:00', 'end_time' => '17:00'])->assertCreated();
        $this->assertSame('not_checked', collect($this->getJson('/api/attendance?date='.self::THURSDAY)->json('students'))->firstWhere('slot_id', 'a')['status']);
        $shifts = collect($this->getJson('/api/teacher-checkin?date='.self::THURSDAY)->json('today_shifts'))->keyBy('start_time');
        $this->assertSame(['Thầy Bình', null], [$shifts['16:00']['substitute_teacher'], $shifts['17:00']['substitute_teacher'] ?? null]);
    }
}
