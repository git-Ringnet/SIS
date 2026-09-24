<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Lead extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'trial_date' => 'datetime',
        'expected_revenue' => 'decimal:0',
    ];

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class, 'interested_course_id');
    }

    public function convertedStudent(): BelongsTo
    {
        return $this->belongsTo(Student::class, 'converted_student_id');
    }
}
