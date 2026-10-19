<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MessageTemplate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MessageTemplateApiController extends Controller
{
    /**
     * Get all message templates.
     */
    public function index(Request $request): JsonResponse
    {
        $category = $request->query('category');
        $query = MessageTemplate::query();

        if ($category && $category !== 'all') {
            $query->where('category', $category);
        }

        $templates = $query->orderBy('id', 'asc')->get();

        return response()->json([
            'templates' => $templates,
        ]);
    }

    /**
     * Store a new message template.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'category' => 'required|string|max:50',
            'template_content' => 'required|string',
        ]);

        $template = MessageTemplate::create([
            'title' => $validated['title'],
            'category' => $validated['category'],
            'template_content' => $validated['template_content'],
            'is_default' => false,
        ]);

        return response()->json([
            'message' => 'Tạo mẫu tin nhắn thành công!',
            'template' => $template,
        ], 201);
    }

    /**
     * Update an existing message template.
     */
    public function update(Request $request, MessageTemplate $template): JsonResponse
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'template_content' => 'required|string',
            'category' => 'sometimes|string|max:50',
        ]);

        $template->update($validated);

        return response()->json([
            'message' => 'Cập nhật mẫu tin nhắn thành công!',
            'template' => $template->fresh(),
        ]);
    }

    /**
     * Reset a default message template to original default content.
     */
    public function reset(Request $request, MessageTemplate $template): JsonResponse
    {
        $defaults = [
            'tuition_reminder' => "Dạ em chào anh/chị {ten_phu_huynh} ạ. Em là quản lý tại Trung tâm Đào tạo. Bé {ten_hoc_vien} hiện tại chỉ còn {so_buoi_con_lai} buổi học trong khóa {khoa_hoc}. Để không bị gián đoạn lịch học quen thuộc của bé cùng thầy/cô, trung tâm xin phép gửi thông tin học phí khóa tiếp theo ({so_tien}):\n- Số TK: {so_tai_khoan}\n- Ngân hàng: {ngan_hang}\n- Cú pháp: [Học phí {ten_hoc_vien} - SĐT {sdt_phu_huynh}]\nEm cảm ơn anh/chị nhiều ạ!",
            'absence_alert' => 'Dạ chào anh/chị {ten_phu_huynh}, trung tâm nhận thấy bé {ten_hoc_vien} đã vắng 2 buổi liên tiếp gần đây. Không biết bé nhà mình có gặp vấn đề về sức khỏe hay bận lịch học ở trường không ạ? Nếu cần sắp xếp buổi học bù, anh/chị phản hồi sớm để trung tâm hỗ trợ xếp lịch cho bé nhé ạ!',
            'payment_confirmation' => 'Dạ trung tâm đã nhận đủ học phí khóa mới cho bé {ten_hoc_vien} rồi ạ! Hệ thống đã tự động cộng thêm số buổi mới và giữ nguyên khung giờ học quen thuộc cho bé. Cảm ơn anh/chị {ten_phu_huynh} đã luôn tin tưởng và đồng hành cùng trung tâm ạ ❤️',
            'general_care' => 'Dạ chào anh/chị {ten_phu_huynh}, hôm nay thầy/cô bộ môn {khoa_hoc} có gửi lời khen bé {ten_hoc_vien} tiếp thu rất tốt, giữ nhịp đều và đã tự hoàn thành bài tập trên lớp rất xuất sắc. Trung tâm gửi video bài học qua Zalo để bố mẹ cùng xem với con nhé ạ!',
        ];

        if (isset($defaults[$template->category])) {
            $template->update([
                'template_content' => $defaults[$template->category],
            ]);
        }

        return response()->json([
            'message' => 'Đã khôi phục mẫu tin nhắn mặc định!',
            'template' => $template->fresh(),
        ]);
    }
}
