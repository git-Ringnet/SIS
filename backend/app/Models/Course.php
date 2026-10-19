<?php

namespace App\Models;

use App\Models\Concerns\LogsActivity;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Course extends Model
{
    use HasFactory, LogsActivity;

    public const ACTIVITY_SUBJECT = 'course';

    protected $guarded = [];

    protected $casts = [
        'price' => 'decimal:0',
        'default_sessions' => 'integer',
        'is_active' => 'boolean',
    ];

    public function students(): HasMany
    {
        return $this->hasMany(Student::class);
    }

    public function activeStudents(): HasMany
    {
        return $this->hasMany(Student::class)->where('status', 'active');
    }

    public function leads(): HasMany
    {
        return $this->hasMany(Lead::class, 'interested_course_id');
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }
}
