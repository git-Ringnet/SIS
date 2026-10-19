<?php

namespace App\Support;

use App\Models\ActivityLog;
use Illuminate\Support\Facades\Storage;
use Throwable;

class ActivityLogger
{
    // Nhãn tiếng Việt cho các trường hay gặp, trường không có ở đây sẽ hiển thị nguyên tên cột
    private const FIELD_LABELS = [
        'name' => 'Tên',
        'code' => 'Mã',
        'email' => 'Email',
        'phone' => 'SĐT',
        'address' => 'Địa chỉ',
        'note' => 'Ghi chú',
        'notes' => 'Ghi chú',
        'status' => 'Trạng thái',
        'price' => 'Giá',
        'amount' => 'Số tiền',
        'center' => 'Trung tâm',
        'course_id' => 'Khóa học',
        'dob' => 'Ngày sinh',
        'gender' => 'Giới tính',
        'parent_name' => 'Tên phụ huynh',
        'parent_phone' => 'SĐT phụ huynh',
        'parent_zalo' => 'Zalo phụ huynh',
        'join_date' => 'Ngày nhập học',
        'end_date' => 'Ngày kết thúc',
        'total_sessions' => 'Tổng số buổi',
        'remaining_sessions' => 'Số buổi còn lại',
        'consecutive_absences' => 'Số buổi vắng liên tiếp',
        'allowed_absences' => 'Số buổi được phép vắng',
        'absences_used' => 'Số buổi đã vắng',
        'assigned_class' => 'Lớp',
        'schedule_info' => 'Lịch học',
        'supplies' => 'Học cụ',
        'retention_status' => 'Trạng thái tái ký',
        'default_sessions' => 'Số buổi mặc định',
        'is_active' => 'Đang hoạt động',
        'invoice_code' => 'Mã hoá đơn',
        'sessions_added' => 'Số buổi cộng thêm',
        'payment_method' => 'Hình thức thanh toán',
        'paid_at' => 'Ngày thanh toán',
        'source' => 'Nguồn',
        'role' => 'Vai trò',
        'blocked_pages' => 'Trang bị chặn',
        'title' => 'Tiêu đề',
        'content' => 'Nội dung',
        'brand_name' => 'Tên thương hiệu',
        'category' => 'Loại hình',
        'scale' => 'Quy mô học viên',
        'logo_url' => 'Logo',
        'company_name' => 'Tên công ty',
        'tax_code' => 'Mã số thuế',
        'vat_email' => 'Email gửi hoá đơn',
        'vat_address' => 'Địa chỉ thuế',
        'capacity' => 'Sức chứa',
        'description' => 'Mô tả',
    ];

    private const IGNORED_FIELDS = ['id', 'created_at', 'updated_at', 'password', 'remember_token'];

    public static function log(
        string $action,
        string $subjectType,
        ?string $subjectLabel = null,
        string|int|null $subjectId = null,
        array $changes = [],
        ?string $context = null,
    ): void {
        try {
            $user = auth('sanctum')->user();

            ActivityLog::create([
                'user_id' => $user?->id,
                'user_name' => $user?->name ?? 'Hệ thống',
                'user_email' => $user?->email,
                'user_role' => $user ? self::resolveRole($user->email) : null,
                'action' => $action,
                'subject_type' => $subjectType,
                'subject_id' => $subjectId !== null ? (string) $subjectId : null,
                'subject_label' => $subjectLabel,
                'context' => $context,
                'changes' => $changes ?: null,
                'ip' => request()?->ip(),
            ]);
        } catch (Throwable $e) {
            // Ghi nhật ký không được làm hỏng thao tác chính
            report($e);
        }
    }

    /**
     * So sánh hai mảng dữ liệu và trả về danh sách thay đổi [{field, label, old, new}].
     */
    public static function diff(array $old, array $new): array
    {
        $changes = [];
        $fields = array_unique(array_merge(array_keys($old), array_keys($new)));

        foreach ($fields as $field) {
            if (in_array($field, self::IGNORED_FIELDS, true)) {
                continue;
            }

            $before = self::normalize($old[$field] ?? null);
            $after = self::normalize($new[$field] ?? null);

            if ($before === $after) {
                continue;
            }

            $changes[] = [
                'field' => $field,
                'label' => self::FIELD_LABELS[$field] ?? $field,
                'old' => $before,
                'new' => $after,
            ];
        }

        return $changes;
    }

    private static function normalize(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_bool($value)) {
            return $value ? 'Có' : 'Không';
        }
        if (is_array($value)) {
            return $value === [] ? null : json_encode($value, JSON_UNESCAPED_UNICODE);
        }
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }

        return (string) $value;
    }

    private static function resolveRole(?string $email): string
    {
        if ($email && Storage::exists('staff_data.json')) {
            $staff = json_decode(Storage::get('staff_data.json'), true);
            foreach ($staff['members'] ?? [] as $member) {
                if (($member['email'] ?? null) === $email) {
                    return $member['role'] ?? 'Chủ trung tâm';
                }
            }
        }

        return 'Chủ trung tâm';
    }
}
