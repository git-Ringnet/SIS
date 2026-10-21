<?php

namespace App\Models;

use App\Models\Concerns\LogsActivity;
use App\Support\CenterStore;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Invoice extends Model
{
    use HasFactory, LogsActivity;

    public const ACTIVITY_SUBJECT = 'invoice';

    public function activityLabel(): ?string
    {
        return $this->invoice_code;
    }

    public function activityContext(): ?string
    {
        return $this->student?->name;
    }

    protected $guarded = [];

    protected $casts = [
        'amount' => 'decimal:0',
        'sessions_added' => 'integer',
        'sessions_reverted' => 'integer',
        'paid_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    /**
     * Trả về mã hoá đơn chưa tồn tại: dùng $base, nếu trùng thì thêm hậu tố -2, -3...
     */
    public static function uniqueCode(string $base): string
    {
        $code = $base;
        $suffix = 1;
        while (static::where('invoice_code', $code)->exists()) {
            $code = $base.'-'.(++$suffix);
        }

        return $code;
    }

    /**
     * Tạo mã hoá đơn theo tiền tố của trung tâm (ví dụ INV0001 hoặc 4CS0001).
     * Để trống dùng INV. Tự động đánh số theo số thứ tự tăng dần.
     */
    public static function nextInvoiceCodeForCenter(?string $centerName = null): string
    {
        $prefix = CenterStore::prefixForCenter($centerName);

        $existingCodes = static::where('invoice_code', 'like', "{$prefix}%")->pluck('invoice_code');
        $maxNumber = 0;
        $prefixLen = strlen($prefix);

        foreach ($existingCodes as $code) {
            $suffix = substr($code, $prefixLen);
            if (is_numeric($suffix)) {
                $num = (int) $suffix;
                if ($num > $maxNumber) {
                    $maxNumber = $num;
                }
            }
        }

        $nextNumber = $maxNumber + 1;
        $code = $prefix.str_pad((string) $nextNumber, 4, '0', STR_PAD_LEFT);
        while (static::where('invoice_code', $code)->exists()) {
            $nextNumber++;
            $code = $prefix.str_pad((string) $nextNumber, 4, '0', STR_PAD_LEFT);
        }

        return $code;
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }
}
