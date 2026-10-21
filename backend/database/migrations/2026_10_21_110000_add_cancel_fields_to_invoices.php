<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Hủy phiếu thu (thu / gia hạn nhầm) thay vì xóa hẳn, để khôi phục được:
     * lưu trạng thái trước khi hủy và số buổi thực sự đã trừ lại (buổi đã học thì không trừ được).
     */
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->string('status_before_cancel', 20)->nullable()->after('status');
            $table->unsignedInteger('sessions_reverted')->nullable()->after('sessions_added');
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancelled_reason')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn(['status_before_cancel', 'sessions_reverted', 'cancelled_at', 'cancelled_reason']);
        });
    }
};
