<?php

namespace App\Support;

use App\Models\MessageTemplate;
use Illuminate\Support\Collection;

/**
 * Mẫu tin nhắn theo từng trang: mỗi trang (nhóm) dùng các loại mẫu riêng, lưu trong bảng message_templates.
 * Nhóm chưa có mẫu nào thì tự tạo các mẫu mặc định (có default_key để "Khôi phục gốc" cả tiêu đề lẫn nội dung).
 * Trước đây một số trang viết cứng mẫu trong code với id 1, 2, 3 nên bấm "Sửa mẫu" ở đó ghi đè mẫu của trang khác.
 */
class MessageTemplateDefaults
{
    /** Trang (nhóm) => các loại mẫu của trang */
    public const GROUPS = [
        'renewal' => ['tuition_reminder', 'absence_alert', 'payment_confirmation', 'general_care'],
        'attendance' => ['attendance_report'],
        'absence' => ['absence_care'],
        'birthday' => ['birthday_greeting'],
        'lead' => ['lead_care'],
        'inactive' => ['inactive_care'],
    ];

    public const CATEGORY_LABELS = [
        'tuition_reminder' => 'Nhắc học phí',
        'absence_alert' => 'Cảnh báo vắng',
        'payment_confirmation' => 'Xác nhận đóng phí',
        'general_care' => 'Chăm sóc & Lời khen',
        'attendance_report' => 'Báo bài / Điểm danh',
        'absence_care' => 'Hỏi thăm vắng học',
        'birthday_greeting' => 'Chúc mừng sinh nhật',
        'lead_care' => 'Khách hàng tiềm năng',
        'inactive_care' => 'Học viên nghỉ / bảo lưu',
    ];

    /**
     * Mẫu mặc định: default_key => [loại, tiêu đề, nội dung].
     *
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function all(): array
    {
        return [
            'tuition_reminder' => ['tuition_reminder', 'Nhắc học phí khóa tiếp theo',
                "Dạ em chào anh/chị {ten_phu_huynh} ạ. Em là quản lý tại Trung tâm Đào tạo. Bé {ten_hoc_vien} hiện tại chỉ còn {so_buoi_con_lai} buổi học trong khóa {khoa_hoc}. Để không bị gián đoạn lịch học quen thuộc của bé cùng thầy/cô, trung tâm xin phép gửi thông tin học phí khóa tiếp theo ({so_tien}):\n- Số TK: {so_tai_khoan}\n- Ngân hàng: {ngan_hang}\n- Cú pháp: [Học phí {ten_hoc_vien} - SĐT {sdt_phu_huynh}]\nEm cảm ơn anh/chị nhiều ạ!"],
            'absence_alert' => ['absence_alert', 'Cảnh báo học viên vắng học 2 buổi liên tiếp',
                'Dạ chào anh/chị {ten_phu_huynh}, trung tâm nhận thấy bé {ten_hoc_vien} đã vắng 2 buổi liên tiếp gần đây. Không biết bé nhà mình có gặp vấn đề về sức khỏe hay bận lịch học ở trường không ạ? Nếu cần sắp xếp buổi học bù, anh/chị phản hồi sớm để trung tâm hỗ trợ xếp lịch cho bé nhé ạ!'],
            'payment_confirmation' => ['payment_confirmation', 'Xác nhận đã nhận học phí & Gia hạn thành công',
                'Dạ trung tâm đã nhận đủ học phí khóa mới cho bé {ten_hoc_vien} rồi ạ! Hệ thống đã tự động cộng thêm số buổi mới và giữ nguyên khung giờ học quen thuộc cho bé. Cảm ơn anh/chị {ten_phu_huynh} đã luôn tin tưởng và đồng hành cùng trung tâm ạ ❤️'],
            'general_care' => ['general_care', 'Cập nhật tiến độ học tập & Lời khen của giáo viên',
                'Dạ chào anh/chị {ten_phu_huynh}, hôm nay thầy/cô bộ môn {khoa_hoc} có gửi lời khen bé {ten_hoc_vien} tiếp thu rất tốt, giữ nhịp đều và đã tự hoàn thành bài tập trên lớp rất xuất sắc. Trung tâm gửi video bài học qua Zalo để bố mẹ cùng xem với con nhé ạ!'],

            'attendance_report' => ['attendance_report', 'Báo bài buổi học gửi phụ huynh',
                "Chào phụ huynh bé {ten_hoc_vien}! Trung tâm gửi báo bài buổi học {khoa_hoc} ngày {ngay_hoc}:\n\n{nhan_xet}\n\nCon còn {so_buoi_con_lai} buổi học. Ba mẹ nhớ nhắc con luyện tập ở nhà nhé ạ! ❤️"],
            'attendance_absent' => ['attendance_report', 'Thông báo học viên vắng học',
                'Dạ trung tâm xin chào phụ huynh {ten_hoc_vien}! Hôm nay không thấy bé đến lớp học môn {khoa_hoc}, trung tâm nhắn tin hỏi thăm tình hình sức khỏe của con ạ. Ba mẹ phản hồi giúp trung tâm để xếp lịch học bù cho bé nhé!'],

            'absence_care_makeup' => ['absence_care', 'Hỏi thăm học viên vắng 2 buổi & Hẹn lịch học bù',
                'Dạ trung tâm xin chào phụ huynh {ten_hoc_vien} ạ! Thầy cô thấy bé đã vắng 2 buổi học gần đây nên nhắn tin hỏi thăm tình hình sức khỏe và lịch trình của bé ạ. Trung tâm có thể hỗ trợ sắp xếp lịch học bù bổ sung kiến thức cho con vào tuần tới, anh/chị tiện giờ nào để trung tâm xếp lớp cho bé ạ? ❤️'],
            'absence_care_reminder' => ['absence_care', 'Nhắc lịch học và giữ số buổi bảo lưu',
                'Chào anh/chị phụ huynh bé {ten_hoc_vien}! Khóa học {khoa_hoc} của con hiện còn {so_buoi_con_lai} buổi. Để đảm bảo tiến độ và phản xạ ngón đàn của bé, trung tâm gửi tin nhắn để hỗ trợ gia đình mình sắp xếp lịch học sớm nhất nhé ạ!'],

            'birthday_voucher' => ['birthday_greeting', 'Chúc mừng sinh nhật học viên & Tặng voucher ưu đãi',
                'Trung tâm xin gửi lời chúc mừng sinh nhật tuổi mới đến bé {ten_hoc_vien} 🎂🎉! Chúc con luôn vui vẻ, chăm ngoan và thăng hoa cùng niềm đam mê âm nhạc. Nhân dịp sinh nhật con, trung tâm xin gửi tặng bé và gia đình voucher ưu đãi 10% cho khóa học tiếp theo hoặc 1 phần quà học cụ đặc biệt. Chúc con có một ngày sinh nhật thật ấm áp bên gia đình ạ! ❤️'],
            'birthday_short' => ['birthday_greeting', 'Lời chúc sinh nhật ngắn gọn qua Zalo',
                'Happy Birthday bé {ten_hoc_vien} 🎈! Thầy cô bộ môn {khoa_hoc} và trung tâm chúc con thêm một tuổi mới thật nhiều niềm vui, học giỏi và luôn yêu thích từng giờ học nhé con ơi!'],

            'lead_trial' => ['lead_care', 'Xác nhận lịch hẹn học thử / Test năng khiếu âm nhạc',
                'Dạ trung tâm xin chào phụ huynh {ten_hoc_vien} ạ! Trung tâm đã ghi nhận lịch hẹn buổi test năng khiếu & tư vấn lộ trình học cho bé vào ngày {ngay_hoc_thu}. Thầy cô rất mong được đón tiếp gia đình mình tại trung tâm ạ! ❤️'],
            'lead_pricing' => ['lead_care', 'Gửi bảng học phí & Ưu đãi khai giảng khóa mới',
                'Chào anh/chị, em gửi anh/chị thông tin học phí khóa {khoa_hoc} của trung tâm: Khóa 12 buổi (1-kèm-1), học 1-2 buổi/tuần, học phí ưu đãi tặng kèm 1 buổi test và giáo trình. Anh/chị cần em hỗ trợ thêm giờ học nào tiện cho bé không ạ?'],
            'lead_after_trial' => ['lead_care', 'Hỏi thăm sau buổi học thử đầu tiên',
                'Dạ em chào anh/chị, hôm nay bé {ten_hoc_vien} học thử buổi đầu tiên về bé có chia sẻ cảm nhận gì với gia đình không ạ? Thầy/cô nhận xét bé có cảm thụ nhịp rất tốt đó ạ!'],

            'inactive_return' => ['inactive_care', 'Mời học viên quay lại lớp sau kỳ bảo lưu',
                'Dạ em chào anh/chị {ten_phu_huynh} ạ. Em là quản lý tại trung tâm. Thời gian bảo lưu/tạm dừng của bé {ten_hoc_vien} sắp kết thúc rồi ạ. Thầy cô bộ môn {khoa_hoc} đang rất mong gặp lại con. Anh/chị cho em xin lịch rảnh trong tuần để trung tâm xếp khung giờ đẹp nhất cho con quay lại lớp nhé ạ!'],
            'inactive_winback' => ['inactive_care', 'Hỏi thăm học viên đã nghỉ & Giới thiệu khóa mới',
                'Dạ em chào anh/chị {ten_phu_huynh}. Lâu rồi trung tâm chưa gặp lại bé {ten_hoc_vien}. Không biết dạo này bé có còn tự luyện tập tại nhà không ạ? Tháng này trung tâm đang có chương trình trải nghiệm nâng cao và ưu đãi đặc quyền cho cựu học viên quay lại lớp, bố mẹ tham khảo qua nhé ạ!'],
        ];
    }

    public static function categories(string $group): array
    {
        return self::GROUPS[$group] ?? self::GROUPS['renewal'];
    }

    /**
     * Mẫu tin nhắn của một trang; loại mẫu nào chưa có mẫu nào thì tạo các mẫu mặc định của loại đó.
     */
    public static function forGroup(string $group): Collection
    {
        $categories = self::categories($group);
        $existing = MessageTemplate::whereIn('category', $categories)->pluck('category')->unique()->all();

        foreach (self::all() as $key => [$category, $title, $content]) {
            if (in_array($category, $categories, true) && ! in_array($category, $existing, true)) {
                MessageTemplate::create(['default_key' => $key, 'category' => $category, 'title' => $title,
                    'template_content' => $content, 'is_default' => true]);
            }
        }

        return MessageTemplate::whereIn('category', $categories)->orderBy('id')->get();
    }

    /**
     * Danh sách loại mẫu của trang cho bộ lọc / ô chọn khi thêm mẫu: [{id, label}].
     */
    public static function categoryOptions(string $group): array
    {
        return array_map(fn ($c) => ['id' => $c, 'label' => self::CATEGORY_LABELS[$c] ?? $c], self::categories($group));
    }
}
