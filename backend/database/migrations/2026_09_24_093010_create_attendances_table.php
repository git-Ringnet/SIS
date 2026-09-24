<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
            $table->foreignId('course_id')->nullable()->constrained('courses')->nullOnDelete();
            $table->integer('session_number')->default(1);
            $table->dateTime('attended_at');
            $table->string('status', 30)->default('present'); // present, absent_with_leave, absent_without_leave
            $table->text('note')->nullable();
            $table->string('marked_by')->default('Giáo viên / Admin');
            $table->timestamps();

            $table->index(['student_id', 'attended_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendances');
    }
};
