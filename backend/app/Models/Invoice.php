<?php

namespace App\Models;

use App\Models\Concerns\LogsActivity;
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
        'paid_at' => 'datetime',
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

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }
}
