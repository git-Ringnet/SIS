<?php

namespace App\Models;

use App\Models\Concerns\LogsActivity;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MessageTemplate extends Model
{
    use HasFactory, LogsActivity;

    public const ACTIVITY_SUBJECT = 'message_template';

    public function activityLabel(): ?string
    {
        return $this->title ?? $this->name ?? null;
    }

    protected $guarded = [];

    protected $casts = [
        'is_default' => 'boolean',
    ];

    /**
     * Replace placeholders with dynamic student data.
     */
    public function render(Student $student, array $extra = []): string
    {
        $bankName = $extra['bank_name'] ?? 'Vietcombank';
        $accountNo = $extra['account_no'] ?? '1029384756 (HO VIET DUNG)';
        $courseName = $student->course ? $student->course->name : 'Khóa học';
        $price = $student->course ? number_format($student->course->price, 0, ',', '.').'đ' : 'Liên hệ';

        $replacements = [
            '{ten_hoc_vien}' => $student->name,
            '{ten_phu_huynh}' => $student->parent_name,
            '{so_buoi_con_lai}' => (string) $student->remaining_sessions,
            '{khoa_hoc}' => $courseName,
            '{so_tien}' => $price,
            '{so_tai_khoan}' => $accountNo,
            '{ngan_hang}' => $bankName,
            '{sdt_phu_huynh}' => $student->parent_phone,
        ];

        return str_replace(array_keys($replacements), array_values($replacements), $this->template_content);
    }
}
