<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Student extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'dob' => 'date:Y-m-d',
        'join_date' => 'date:Y-m-d',
        'end_date' => 'date:Y-m-d',
        'last_retention_contact_at' => 'datetime',
        'total_sessions' => 'integer',
        'remaining_sessions' => 'integer',
        'consecutive_absences' => 'integer',
        'allowed_absences' => 'integer',
        'absences_used' => 'integer',
        'supplies' => 'array',
    ];

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function attendances(): HasMany
    {
        return $this->hasMany(Attendance::class)->orderBy('attended_at', 'desc');
    }

    public function latestAttendance(): HasOne
    {
        return $this->hasOne(Attendance::class)->latestOfMany('attended_at');
    }

    public function retentionLogs(): HasMany
    {
        return $this->hasMany(RetentionLog::class)->orderBy('contact_date', 'desc');
    }

    public function latestRetentionLog(): HasOne
    {
        return $this->hasOne(RetentionLog::class)->latestOfMany('contact_date');
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class)->orderBy('created_at', 'desc');
    }

    // Scopes for high performance queries
    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeExpiring($query, $threshold = 5)
    {
        return $query->where('status', 'active')
            ->where('remaining_sessions', '<=', $threshold);
    }

    public function scopeConsecutiveAbsences($query, $threshold = 2)
    {
        return $query->where('status', 'active')
            ->where('consecutive_absences', '>=', $threshold);
    }

    // Progress percentage helper
    public function getProgressPercentAttribute(): int
    {
        if ($this->total_sessions <= 0) {
            return 0;
        }
        $attended = max(0, $this->total_sessions - $this->remaining_sessions);

        return min(100, (int) round(($attended / $this->total_sessions) * 100));
    }
}
