<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Lớp học trong một khóa (khóa nhóm): tên lớp, phòng học (tuỳ chọn), sĩ số tối đa.
     * Học viên được gán vào lớp qua cột students.assigned_class (tên lớp, duy nhất toàn hệ thống).
     */
    public function up(): void
    {
        Schema::create('course_classes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_id')->constrained()->cascadeOnDelete();
            $table->string('name')->unique();
            $table->string('center')->nullable();
            $table->string('room')->nullable();
            $table->unsignedSmallInteger('capacity')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('course_classes');
    }
};
