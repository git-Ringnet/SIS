<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;

class ActivityLog extends Model
{
    use Prunable;

    // Nhật ký chỉ lưu trong số ngày này, cũ hơn sẽ bị xoá bởi lệnh model:prune
    public const RETENTION_DAYS = 7;

    public const UPDATED_AT = null;

    protected $guarded = [];

    protected $casts = [
        'changes' => 'array',
        'created_at' => 'datetime',
    ];

    public function prunable(): Builder
    {
        return static::where('created_at', '<', now()->subDays(self::RETENTION_DAYS));
    }
}
