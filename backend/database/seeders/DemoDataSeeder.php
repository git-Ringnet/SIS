<?php

namespace Database\Seeders;

use App\Models\Attendance;
use App\Models\Course;
use App\Models\Invoice;
use App\Models\Lead;
use App\Models\RetentionLog;
use App\Models\Student;
use Carbon\Carbon;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

/**
 * Dữ liệu demo để thử nghiệm / trình diễn: khóa học, học viên, điểm danh, hóa đơn, khách hàng tiềm năng.
 * KHÔNG chạy khi cài cho khách. Chạy thủ công: php artisan db:seed --class=DemoDataSeeder
 */
class DemoDataSeeder extends Seeder
{
    // Dữ liệu khởi tạo không phải thao tác của người dùng nên không ghi vào Nhật ký hoạt động
    use WithoutModelEvents;

    public function run(): void
    {
        // 1. Courses (Music, Art, Languages)
        $courses = [
            [
                'name' => 'Piano Căn Bản & Cổ Điển',
                'code' => 'PIA-01',
                'category' => 'Âm nhạc',
                'default_sessions' => 12,
                'price' => 3600000,
                'color' => '#3b82f6', // blue
                'description' => 'Khóa học Piano 1-kèm-1 hoặc nhóm 2-3 bé, lộ trình chuẩn LCM/ABRSM.',
            ],
            [
                'name' => 'Guitar Acoustic & Fingerstyle',
                'code' => 'GUI-02',
                'category' => 'Âm nhạc',
                'default_sessions' => 12,
                'price' => 2800000,
                'color' => '#f59e0b', // amber
                'description' => 'Khóa đệm hát cơ bản đến nâng cao cho thanh thiếu niên và người lớn.',
            ],
            [
                'name' => 'Thanh Nhạc & Cảm Thụ Âm Nhạc',
                'code' => 'VOC-03',
                'category' => 'Âm nhạc',
                'default_sessions' => 12,
                'price' => 3200000,
                'color' => '#ec4899', // pink
                'description' => 'Luyện thanh, mở khẩu hình, nhả chữ và tự tin biểu diễn sân khấu.',
            ],
            [
                'name' => 'Mỹ Thuật Sáng Tạo & Màu Nước',
                'code' => 'ART-01',
                'category' => 'Mỹ thuật',
                'default_sessions' => 16,
                'price' => 2400000,
                'color' => '#10b981', // emerald
                'description' => 'Khơi gợi tư duy hình khối, pha màu và chất liệu sáng tạo cho trẻ từ 5-12 tuổi.',
            ],
            [
                'name' => 'Hội Họa Chì & Phác Thảo Ký Họa',
                'code' => 'ART-02',
                'category' => 'Mỹ thuật',
                'default_sessions' => 16,
                'price' => 2600000,
                'color' => '#8b5cf6', // purple
                'description' => 'Khóa vẽ luyện thi hoặc sở thích chuyên sâu hình họa giải phẫu và tĩnh vật.',
            ],
            [
                'name' => 'Tiếng Anh Giao Tiếp Phản Xạ 1:1',
                'code' => 'ENG-01',
                'category' => 'Ngoại ngữ',
                'default_sessions' => 24,
                'price' => 5400000,
                'color' => '#06b6d4', // cyan
                'description' => 'Tăng cường phản xạ nghe nói cùng giáo viên nước ngoài và trợ giảng.',
            ],
        ];

        $createdCourses = [];
        foreach ($courses as $c) {
            $createdCourses[] = Course::create($c);
        }

        // 2. Students (Active, Expiring < 6 sessions, Paused, Consecutive Absences)
        $sampleStudents = [
            // Expiring students (< 6 sessions) - HIGH RETENTION PRIORITY
            [
                'code' => 'HV-101',
                'name' => 'Nguyễn Minh Khang',
                'dob' => '2016-05-12',
                'gender' => 'Nam',
                'parent_name' => 'Chị Phương Thảo',
                'parent_phone' => '0912345678',
                'parent_zalo' => '0912345678',
                'course_index' => 0, // Piano
                'total_sessions' => 12,
                'remaining_sessions' => 2,
                'status' => 'active',
                'retention_status' => 'invoice_sent', // Blue
                'last_retention_contact_at' => Carbon::now()->subDays(1),
                'consecutive_absences' => 0,
                'join_date' => '2025-11-10',
                'notes' => 'Học lực rất tốt, mẹ quan tâm sát sao. Đã gửi hóa đơn qua Zalo ngày hôm qua.',
            ],
            [
                'code' => 'HV-102',
                'name' => 'Trần Bảo Ngọc',
                'dob' => '2017-08-20',
                'gender' => 'Nữ',
                'parent_name' => 'Anh Quốc Tuấn',
                'parent_phone' => '0987654321',
                'parent_zalo' => '0987654321',
                'course_index' => 3, // Mỹ thuật
                'total_sessions' => 16,
                'remaining_sessions' => 3,
                'status' => 'active',
                'retention_status' => 'following_up', // Orange
                'last_retention_contact_at' => Carbon::now()->subHours(5),
                'consecutive_absences' => 0,
                'join_date' => '2025-10-15',
                'notes' => 'Bố hẹn chuyển khoản sau ngày nhận lương 25.',
            ],
            [
                'code' => 'HV-103',
                'name' => 'Lê Gia Hưng',
                'dob' => '2015-02-14',
                'gender' => 'Nam',
                'parent_name' => 'Chị Thanh Mai',
                'parent_phone' => '0934567890',
                'parent_zalo' => '0934567890',
                'course_index' => 1, // Guitar
                'total_sessions' => 12,
                'remaining_sessions' => 1,
                'status' => 'active',
                'retention_status' => 'following_up', // Orange
                'last_retention_contact_at' => Carbon::now()->subDays(2),
                'consecutive_absences' => 0,
                'join_date' => '2025-12-01',
                'notes' => 'Mẹ đang phân vân cho bé lên khóa Guitar nâng cao hay học song song Piano.',
            ],
            [
                'code' => 'HV-104',
                'name' => 'Phạm Quỳnh Anh',
                'dob' => '2018-09-05',
                'gender' => 'Nữ',
                'parent_name' => 'Chị Kim Ngân',
                'parent_phone' => '0901234888',
                'parent_zalo' => '0901234888',
                'course_index' => 2, // Thanh Nhạc
                'total_sessions' => 12,
                'remaining_sessions' => 4,
                'status' => 'active',
                'retention_status' => 'renewed', // Green
                'last_retention_contact_at' => Carbon::now()->subHours(2),
                'consecutive_absences' => 0,
                'join_date' => '2026-01-05',
                'notes' => 'Đã đóng học phí khóa 24 buổi tiếp theo. Đã tạo hóa đơn.',
            ],
            [
                'code' => 'HV-105',
                'name' => 'Vũ Tuấn Kiệt',
                'dob' => '2014-11-28',
                'gender' => 'Nam',
                'parent_name' => 'Anh Hoàng Long',
                'parent_phone' => '0977889900',
                'parent_zalo' => '0977889900',
                'course_index' => 5, // Tiếng Anh
                'total_sessions' => 24,
                'remaining_sessions' => 5,
                'status' => 'active',
                'retention_status' => 'normal', // Normal
                'last_retention_contact_at' => null,
                'consecutive_absences' => 0,
                'join_date' => '2025-09-15',
                'notes' => 'Chuẩn bị bước vào chu kỳ nhắc phí đợt tới.',
            ],
            // Consecutive Absence Students (Alert)
            [
                'code' => 'HV-106',
                'name' => 'Đặng Hải Đăng',
                'dob' => '2015-07-19',
                'gender' => 'Nam',
                'parent_name' => 'Anh Đăng Khoa',
                'parent_phone' => '0918882233',
                'parent_zalo' => '0918882233',
                'course_index' => 0, // Piano
                'total_sessions' => 12,
                'remaining_sessions' => 8,
                'status' => 'active',
                'retention_status' => 'following_up',
                'last_retention_contact_at' => Carbon::now()->subHours(6),
                'consecutive_absences' => 3,
                'join_date' => '2026-02-10',
                'notes' => 'Vắng 3 buổi liên tiếp do gia đình về quê có việc. Cần gọi hỏi thăm và xếp lịch học bù.',
            ],
            [
                'code' => 'HV-107',
                'name' => 'Hoàng Yến Nhi',
                'dob' => '2017-03-30',
                'gender' => 'Nữ',
                'parent_name' => 'Chị Bích Thủy',
                'parent_phone' => '0903334455',
                'parent_zalo' => '0903334455',
                'course_index' => 4, // Mỹ thuật chì
                'total_sessions' => 16,
                'remaining_sessions' => 10,
                'status' => 'active',
                'retention_status' => 'normal',
                'last_retention_contact_at' => null,
                'consecutive_absences' => 2,
                'join_date' => '2026-01-20',
                'notes' => 'Vắng 2 buổi tuần này vì bé bị cảm sốt.',
            ],
            // Regular Healthy Active Students
            [
                'code' => 'HV-108',
                'name' => 'Bùi Đức Anh',
                'dob' => '2016-12-05',
                'gender' => 'Nam',
                'parent_name' => 'Anh Minh Trí',
                'parent_phone' => '0945671122',
                'parent_zalo' => '0945671122',
                'course_index' => 1, // Guitar
                'total_sessions' => 12,
                'remaining_sessions' => 9,
                'status' => 'active',
                'retention_status' => 'normal',
                'last_retention_contact_at' => null,
                'consecutive_absences' => 0,
                'join_date' => '2026-02-01',
                'notes' => 'Đi học rất đều đặn và chăm chỉ.',
            ],
            [
                'code' => 'HV-109',
                'name' => 'Cao Mỹ Uyên',
                'dob' => '2018-04-14',
                'gender' => 'Nữ',
                'parent_name' => 'Chị Thục Quyên',
                'parent_phone' => '0966778899',
                'parent_zalo' => '0966778899',
                'course_index' => 3, // Mỹ thuật sáng tạo
                'total_sessions' => 16,
                'remaining_sessions' => 14,
                'status' => 'active',
                'retention_status' => 'normal',
                'last_retention_contact_at' => null,
                'consecutive_absences' => 0,
                'join_date' => '2026-03-01',
                'notes' => 'Học viên mới đăng ký đầu tháng.',
            ],
            // Paused Student
            [
                'code' => 'HV-110',
                'name' => 'Ngô Thành Đạt',
                'dob' => '2014-06-18',
                'gender' => 'Nam',
                'parent_name' => 'Anh Ngô Hùng',
                'parent_phone' => '0988112233',
                'parent_zalo' => '0988112233',
                'course_index' => 5, // Tiếng Anh
                'total_sessions' => 24,
                'remaining_sessions' => 15,
                'status' => 'paused',
                'retention_status' => 'normal',
                'last_retention_contact_at' => Carbon::now()->subWeeks(2),
                'consecutive_absences' => 0,
                'join_date' => '2025-10-01',
                'notes' => 'Xin bảo lưu 1 tháng để chuẩn bị thi học kỳ tại trường.',
            ],
        ];

        foreach ($sampleStudents as $sData) {
            $cIdx = $sData['course_index'];
            unset($sData['course_index']);
            $sData['course_id'] = $createdCourses[$cIdx]->id;
            $student = Student::create($sData);

            // Generate past attendance history
            $attendedCount = $student->total_sessions - $student->remaining_sessions;
            for ($i = 1; $i <= max(1, $attendedCount); $i++) {
                $daysAgo = ($attendedCount - $i + 1) * 3;
                Attendance::create([
                    'student_id' => $student->id,
                    'course_id' => $student->course_id,
                    'session_number' => $i,
                    'attended_at' => Carbon::now()->subDays($daysAgo)->setHour(17)->setMinute(30),
                    'status' => 'present',
                    'note' => "Hoàn thành tốt bài học buổi #{$i}.",
                    'marked_by' => 'Cô Mai Hương',
                ]);
            }

            // If consecutive absences > 0, generate absent logs
            if ($student->consecutive_absences > 0) {
                for ($a = 1; $a <= $student->consecutive_absences; $a++) {
                    Attendance::create([
                        'student_id' => $student->id,
                        'course_id' => $student->course_id,
                        'session_number' => $attendedCount + $a,
                        'attended_at' => Carbon::now()->subDays(($student->consecutive_absences - $a + 1) * 2)->setHour(17)->setMinute(30),
                        'status' => $a === 1 ? 'absent_with_leave' : 'absent_without_leave',
                        'note' => $a === 1 ? 'Phụ huynh báo xin nghỉ phép.' : 'Không đến lớp, chưa liên lạc được.',
                        'marked_by' => 'Thầy Đức',
                    ]);
                }
            }

            // Generate initial enrollment invoice
            Invoice::create([
                'invoice_code' => 'HD-'.date('Ym').'-'.str_pad($student->id, 3, '0', STR_PAD_LEFT),
                'student_id' => $student->id,
                'course_id' => $student->course_id,
                'amount' => $createdCourses[$cIdx]->price,
                'sessions_added' => $student->total_sessions,
                'payment_method' => 'bank_transfer',
                'status' => 'paid',
                'paid_at' => Carbon::parse($student->join_date ?? '2026-01-01')->setHour(10),
                'note' => 'Học phí đăng ký khóa học ban đầu',
                'recorded_by' => 'Admin Thu Ngân',
            ]);

            // Add retention logs if relevant
            if ($student->retention_status === 'invoice_sent') {
                RetentionLog::create([
                    'student_id' => $student->id,
                    'action_type' => 'invoice_sent',
                    'status' => 'invoice_sent',
                    'contact_date' => Carbon::now()->subDays(1),
                    'content' => 'Đã gửi hóa đơn học phí và lịch học khóa mới qua Zalo cho mẹ Phương Thảo.',
                    'created_by' => 'Admin Thu Ngân',
                ]);
            } elseif ($student->retention_status === 'following_up') {
                RetentionLog::create([
                    'student_id' => $student->id,
                    'action_type' => 'call_care',
                    'status' => 'following_up',
                    'contact_date' => Carbon::now()->subHours(4),
                    'content' => 'Gọi điện trao đổi tình hình học tập và thảo luận về gia hạn khóa tiếp theo. Phụ huynh rất hài lòng và hẹn thanh toán sớm.',
                    'created_by' => 'Tư vấn viên Lan',
                ]);
            } elseif ($student->retention_status === 'renewed') {
                RetentionLog::create([
                    'student_id' => $student->id,
                    'action_type' => 'renewed_payment',
                    'status' => 'renewed',
                    'contact_date' => Carbon::now()->subHours(2),
                    'content' => 'Phụ huynh đã chuyển khoản 3.200.000đ gia hạn thành công khóa Thanh Nhạc 12 buổi.',
                    'created_by' => 'Admin Thu Ngân',
                ]);

                // Renewal invoice
                Invoice::create([
                    'invoice_code' => 'HD-RENEW-'.date('Ym').'-'.str_pad($student->id, 3, '0', STR_PAD_LEFT),
                    'student_id' => $student->id,
                    'course_id' => $student->course_id,
                    'amount' => 3200000,
                    'sessions_added' => 12,
                    'payment_method' => 'bank_transfer',
                    'status' => 'paid',
                    'paid_at' => Carbon::now()->subHours(2),
                    'note' => 'Gia hạn gói tái đăng ký giữ chân học viên',
                    'recorded_by' => 'Admin Thu Ngân',
                ]);
            }
        }

        // Today's revenue dummy invoice for dashboard display
        $firstStudent = Student::first();
        Invoice::create([
            'invoice_code' => 'HD-TODAY-'.rand(100, 999),
            'student_id' => $firstStudent->id,
            'course_id' => $firstStudent->course_id,
            'amount' => 2800000,
            'sessions_added' => 12,
            'payment_method' => 'bank_transfer',
            'status' => 'paid',
            'paid_at' => Carbon::today()->setHour(9)->setMinute(15),
            'note' => 'Thu học phí trong ngày',
            'recorded_by' => 'Admin Thu Ngân',
        ]);

        // 3. Leads (Kanban CRM Pipeline: Mới tiếp nhận -> Đã gọi -> Gửi báo giá -> Đã học thử -> Đăng ký)
        $leads = [
            [
                'parent_name' => 'Chị Hồng Nhung',
                'student_name' => 'Bé Gia Bảo (6 tuổi)',
                'phone' => '0981223344',
                'email' => 'hongnhung@gmail.com',
                'interested_course_id' => $createdCourses[0]->id, // Piano
                'source' => 'Facebook',
                'status' => 'new',
                'expected_revenue' => 3600000,
                'notes' => 'Inbox Fanpage hỏi lịch học Piano thứ 7 Chủ Nhật cho con.',
                'assigned_to' => 'Tư vấn viên Lan',
            ],
            [
                'parent_name' => 'Anh Đình Trọng',
                'student_name' => 'Bé Minh Triết (8 tuổi)',
                'phone' => '0973445566',
                'email' => 'trongdinh@gmail.com',
                'interested_course_id' => $createdCourses[3]->id, // Mỹ thuật
                'source' => 'Zalo',
                'status' => 'called',
                'expected_revenue' => 2400000,
                'notes' => 'Đã gọi điện tư vấn lộ trình mỹ thuật sáng tạo, bố muốn cho bé học thử trước 1 buổi.',
                'assigned_to' => 'Tư vấn viên Mai',
            ],
            [
                'parent_name' => 'Chị Bích Ngọc',
                'student_name' => 'Bé Tuệ Mẫn (5 tuổi)',
                'phone' => '0938776655',
                'email' => 'ngocbich@gmail.com',
                'interested_course_id' => $createdCourses[2]->id, // Thanh nhạc
                'source' => 'Hotline',
                'status' => 'quote_sent',
                'expected_revenue' => 3200000,
                'notes' => 'Đã gửi file PDF báo giá khóa thanh nhạc thiếu nhi và clip biểu diễn của các bạn học viên.',
                'assigned_to' => 'Tư vấn viên Lan',
            ],
            [
                'parent_name' => 'Anh Hoàng Bách',
                'student_name' => 'Bé Hoàng Phúc (10 tuổi)',
                'phone' => '0919228833',
                'email' => 'bachhoang@gmail.com',
                'interested_course_id' => $createdCourses[1]->id, // Guitar
                'source' => 'Giới thiệu',
                'status' => 'trial_done',
                'trial_date' => Carbon::now()->subDays(1)->setHour(18),
                'expected_revenue' => 2800000,
                'notes' => 'Đã học thử tối qua rất hào hứng. Thầy giáo đánh giá có năng khiếu bắt nhịp tốt.',
                'assigned_to' => 'Tư vấn viên Mai',
            ],
            [
                'parent_name' => 'Chị Thùy Dương',
                'student_name' => 'Bé Khánh Linh (7 tuổi)',
                'phone' => '0965112233',
                'email' => 'duongthuy@gmail.com',
                'interested_course_id' => $createdCourses[0]->id, // Piano
                'source' => 'Website',
                'status' => 'registered',
                'expected_revenue' => 3600000,
                'notes' => 'Đã xác nhận đăng ký và chuyển khoản giữ chỗ khai giảng tuần sau.',
                'assigned_to' => 'Tư vấn viên Lan',
            ],
        ];

        foreach ($leads as $l) {
            Lead::create($l);
        }
    }
}
