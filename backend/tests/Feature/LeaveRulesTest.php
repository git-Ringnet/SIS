<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Course;
use App\Models\Student;
use App\Models\User;
use App\Support\PagePermissions;
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
        $this->assertSame(1, $row['current_session']); // buổi 1 vẫn là buổi kế tiếp
        $this->assertSame(0, $row['attended_sessions']); // màn điểm danh hiện "Đã học 0/8", không gán số buổi cho buổi nghỉ
        $this->assertSame(8, $row['remaining_sessions']);
        $this->assertSame([1, 0], [$row['allowed_absences'], $row['leave_left']]);
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

    public function test_center_off_day_is_shown_and_pushes_schedule_back(): void
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

        // Vẫn đủ 8 buổi sắp tới, buổi T5 nghỉ lễ bị bỏ qua → lịch lùi 1 buổi
        $upcoming = $sessions->where('status', 'upcoming')->values();
        $this->assertCount(8, $upcoming);
        $this->assertSame(['19/10/2026', '26/10/2026'], $upcoming->take(2)->pluck('date')->all());
        $this->assertSame(range(1, 8), $upcoming->pluck('session_number')->all());
        // 19/10, 26/10, 29/10, 02/11, 05/11, 09/11, 12/11, 16/11 (bỏ 22/10)
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
        // Piano: ngày buổi điểm danh đầu tiên (thực tế), không phải ngày lưu trong khóa
        $this->assertSame(['12/10/2026', false], [$courses['Piano']['start'], $courses['Piano']['start_estimated']]);
        // Guitar: chưa học → buổi T5 đầu tiên từ ngày bắt đầu của khóa (dự kiến); 8 buổi T5 → kết thúc 17/12
        $this->assertSame(['29/10/2026', true], [$courses['Guitar']['start'], $courses['Guitar']['start_estimated']]);
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

        // Cổng PH: buổi T5 là nghỉ có phép (không đánh số). Không tự thêm buổi cuối lịch:
        // buổi được giữ lại là "Chờ xếp buổi bù" (chưa có ngày) đến khi trung tâm đặt buổi bù
        $data = $this->portal($student);
        $sessions = collect($data['sessions']);
        $leave = $sessions->firstWhere('status', 'absent_with_leave');
        $this->assertSame(['22/10/2026', null], [$leave['date'], $leave['session_number']]);
        $this->assertSame([1, 0], [$data['stats']['leave_used'], $data['stats']['leave_left']]);
        $upcoming = $sessions->where('status', 'upcoming')->values();
        $this->assertCount(8, $upcoming);
        $this->assertSame('12/11/2026', $upcoming->whereNotNull('date')->last()['date']); // 7 buổi theo lịch, không lùi sang 16/11
        $this->assertSame(['Chờ xếp buổi bù', null], [$upcoming->last()['status_label'], $upcoming->last()['date']]);
        $this->assertSame(1, $data['stats']['pending_makeup_sessions']);

        // Đặt buổi bù → thay cho buổi chờ xếp
        $this->postJson('/api/attendance/single-lesson', ['student_id' => $student->id, 'date' => '2026-10-24', 'is_makeup' => true])->assertCreated();
        $data = $this->portal($student);
        $this->assertSame([0, 1], [$data['stats']['pending_makeup_sessions'], $data['stats']['makeup_sessions']]);
        $this->assertCount(8, collect($data['sessions'])->where('status', 'upcoming'));
        $makeupId = collect($data['sessions'])->firstWhere('is_makeup', true)['slot_id'];
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
        // Buổi bù dùng 1 trong 8 buổi còn lại
        $this->assertCount(8, collect($data['sessions'])->where('status', 'upcoming'));
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
}
