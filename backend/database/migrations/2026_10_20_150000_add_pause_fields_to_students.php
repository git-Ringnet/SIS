<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Hạn bảo lưu ("Đến ngày") và lý do tạm dừng/nghỉ tách riêng khỏi end_date (ngày kết thúc khoá) và notes (ghi chú học viên).
     */
    public function up(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->date('paused_until')->nullable()->after('status');
            $table->text('pause_note')->nullable()->after('paused_until');
        });
    }

    public function down(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->dropColumn(['paused_until', 'pause_note']);
        });
    }
};
