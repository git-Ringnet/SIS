<?php

namespace Database\Seeders;

use App\Models\MessageTemplate;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Dữ liệu tối thiểu để hệ thống chạy được cho khách hàng mới: tài khoản quản trị và mẫu tin nhắn.
 * Dữ liệu demo (khóa học, học viên, hóa đơn, khách hàng tiềm năng...) nằm ở DemoDataSeeder:
 *   php artisan db:seed --class=DemoDataSeeder
 */
class DatabaseSeeder extends Seeder
{
    // Dữ liệu khởi tạo không phải thao tác của người dùng nên không ghi vào Nhật ký hoạt động
    use WithoutModelEvents;

    public function run(): void
    {
        // 1. Tài khoản chủ trung tâm (ADMIN_* trong .env). Mặc định mật khẩu "password", khách tự đổi sau khi nhận.
        // Tạo trước để là tài khoản đầu tiên = chủ trung tâm gốc.
        $admin = config('app.admin');
        $this->createAccount($admin['name'], $admin['email'], $admin['password'] ?: 'password');

        // 2. Tài khoản kỹ thuật cho bên triển khai (SYSTEM_ADMIN_* trong .env): toàn quyền, không hiện ở màn Nhân sự
        // nên khách không xoá / đổi mật khẩu được. Không đặt mật khẩu thì tự sinh ngẫu nhiên (KHÔNG dùng mật khẩu dễ đoán).
        $system = config('app.system_admin');
        if ($system['email']) {
            $this->createAccount('System Admin', $system['email'], $system['password'] ?: Str::password(16, symbols: false));
        }

        // 3. Message Templates for Quick Zalo/SMS Copy
        $templates = [
            [
                'title' => 'Nhắc học phí tái đăng ký (Sắp hết buổi)',
                'category' => 'tuition_reminder',
                'template_content' => "Dạ em chào anh/chị {ten_phu_huynh} ạ. Em là quản lý tại Trung tâm Đào tạo. Bé {ten_hoc_vien} hiện tại chỉ còn {so_buoi_con_lai} buổi học trong khóa {khoa_hoc}. Để không bị gián đoạn lịch học quen thuộc của bé cùng thầy/cô, trung tâm xin phép gửi thông tin học phí khóa tiếp theo ({so_tien}):\n- Số TK: {so_tai_khoan}\n- Ngân hàng: {ngan_hang}\n- Cú pháp: [Học phí {ten_hoc_vien} - SĐT {sdt_phu_huynh}]\nEm cảm ơn anh/chị nhiều ạ!",
                'is_default' => true,
            ],
            [
                'title' => 'Cảnh báo học viên vắng học 2 buổi liên tiếp',
                'category' => 'absence_alert',
                'template_content' => 'Dạ chào anh/chị {ten_phu_huynh}, trung tâm nhận thấy bé {ten_hoc_vien} đã vắng 2 buổi liên tiếp gần đây. Không biết bé nhà mình có gặp vấn đề về sức khỏe hay bận lịch học ở trường không ạ? Nếu cần sắp xếp buổi học bù, anh/chị phản hồi sớm để trung tâm hỗ trợ xếp lịch cho bé nhé ạ!',
                'is_default' => true,
            ],
            [
                'title' => 'Xác nhận đã nhận học phí & Gia hạn thành công',
                'category' => 'payment_confirmation',
                'template_content' => 'Dạ trung tâm đã nhận đủ học phí khóa mới cho bé {ten_hoc_vien} rồi ạ! Hệ thống đã tự động cộng thêm số buổi mới và giữ nguyên khung giờ học quen thuộc cho bé. Cảm ơn anh/chị {ten_phu_huynh} đã luôn tin tưởng và đồng hành cùng trung tâm ạ ❤️',
                'is_default' => true,
            ],
            [
                'title' => 'Cập nhật tiến độ học tập & Lời khen của giáo viên',
                'category' => 'general_care',
                'template_content' => 'Dạ chào anh/chị {ten_phu_huynh}, hôm nay thầy/cô bộ môn {khoa_hoc} có gửi lời khen bé {ten_hoc_vien} tiếp thu rất tốt, giữ nhịp đều và đã tự hoàn thành bài tập trên lớp rất xuất sắc. Trung tâm gửi video bài học qua Zalo để bố mẹ cùng xem với con nhé ạ!',
                'is_default' => false,
            ],
        ];

        foreach ($templates as $t) {
            MessageTemplate::firstOrCreate(['category' => $t['category']], $t);
        }
    }

    /**
     * Tạo tài khoản nếu chưa có (chạy seed lại không đổi mật khẩu đã đổi) và in thông tin đăng nhập.
     */
    private function createAccount(string $name, string $email, string $password): void
    {
        if (User::where('email', $email)->exists()) {
            return;
        }

        User::create([
            'name' => $name,
            'email' => $email,
            'password' => Hash::make($password),
        ]);

        $this->command?->warn("Đã tạo tài khoản: {$email} / {$password}");
    }
}
