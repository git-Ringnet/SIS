<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MessageTemplate;
use App\Support\MessageTemplateDefaults;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class MessageTemplateApiController extends Controller
{
    /**
     * Mẫu tin nhắn của một trang (?group=renewal|attendance|absence|birthday|lead|inactive), kèm danh sách loại mẫu.
     * Loại mẫu chưa có mẫu nào thì tự tạo mẫu mặc định.
     */
    public function index(Request $request): JsonResponse
    {
        $group = (string) $request->query('group', 'renewal');
        $templates = MessageTemplateDefaults::forGroup($group);

        $category = $request->query('category');
        if ($category && $category !== 'all') {
            $templates = $templates->where('category', $category)->values();
        }

        return response()->json([
            'templates' => $templates,
            'categories' => MessageTemplateDefaults::categoryOptions($group),
        ]);
    }

    /**
     * Thêm mẫu tin nhắn mới (mẫu tự tạo, xóa được).
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'category' => ['required', 'string', Rule::in(array_keys(MessageTemplateDefaults::CATEGORY_LABELS))],
            'template_content' => 'required|string',
        ]);

        $template = MessageTemplate::create([
            'title' => $validated['title'],
            'category' => $validated['category'],
            'template_content' => $validated['template_content'],
            'is_default' => false,
        ]);

        return response()->json([
            'message' => 'Đã thêm mẫu tin nhắn mới!',
            'template' => $template,
        ], 201);
    }

    /**
     * Sửa tiêu đề / nội dung mẫu (loại mẫu giữ nguyên để mẫu không chạy sang trang khác).
     */
    public function update(Request $request, MessageTemplate $template): JsonResponse
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'template_content' => 'required|string',
        ]);

        $template->update($validated);

        return response()->json([
            'message' => 'Cập nhật mẫu tin nhắn thành công!',
            'template' => $template->fresh(),
        ]);
    }

    /**
     * Xóa mẫu tự tạo. Mẫu mặc định không xóa, chỉ sửa hoặc khôi phục gốc.
     */
    public function destroy(MessageTemplate $template): JsonResponse
    {
        if ($template->default_key) {
            return response()->json(['message' => 'Mẫu mặc định không xóa được, chỉ có thể sửa hoặc khôi phục gốc.'], 422);
        }
        $template->delete();

        return response()->json(['message' => 'Đã xóa mẫu tin nhắn!']);
    }

    /**
     * Khôi phục mẫu mặc định về tiêu đề và nội dung ban đầu.
     */
    public function reset(MessageTemplate $template): JsonResponse
    {
        $default = MessageTemplateDefaults::all()[$template->default_key] ?? null;
        if (! $default) {
            return response()->json(['message' => 'Mẫu tự tạo không có nội dung gốc để khôi phục.'], 422);
        }

        $template->update(['title' => $default[1], 'template_content' => $default[2]]);

        return response()->json([
            'message' => 'Đã khôi phục mẫu tin nhắn mặc định!',
            'template' => $template->fresh(),
        ]);
    }
}
