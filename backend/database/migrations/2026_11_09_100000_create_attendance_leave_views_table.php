<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Đơn xin nghỉ từ Cổng phụ huynh mà từng tài khoản đã mở xem (chấm nhấp nháy ở trang Điểm danh).
     */
    public function up(): void
    {
        Schema::create('attendance_leave_views', function (Blueprint $table) {
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('attendance_id')->constrained()->cascadeOnDelete();
            $table->timestamp('seen_at')->useCurrent();
            $table->primary(['user_id', 'attendance_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_leave_views');
    }
};
