<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Hóa đơn = ghi nhận thanh toán: mỗi hóa đơn mới có các dòng (khóa học / học cụ) với số tiền thu lần này và giảm giá.
 * Phiếu thu cũ (lines = null: nhập học tự động, gia hạn cộng buổi) giữ nguyên ý nghĩa; gắn course_item_id theo khóa cùng course_id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->unsignedInteger('course_item_id')->nullable()->after('course_id');
            $table->json('lines')->nullable()->after('amount');
            $table->date('due_date')->nullable()->after('paid_at');
        });

        $students = DB::table('students')->get(['id', 'course_id', 'registered_courses'])->keyBy('id');
        foreach (DB::table('invoices')->whereNull('course_item_id')->get(['id', 'student_id', 'course_id']) as $inv) {
            $student = $students->get($inv->student_id);
            if (! $student) {
                continue;
            }
            $items = json_decode((string) $student->registered_courses, true) ?: [];
            $itemId = null;
            foreach ($items as $item) {
                if ((int) ($item['course_id'] ?? 0) === (int) $inv->course_id) {
                    $itemId = (int) $item['id'];
                    break;
                }
            }
            // Học viên chưa có danh sách khóa (dữ liệu cũ): khóa duy nhất mặc định id 1
            $itemId ??= $items === [] ? 1 : (int) ($items[0]['id'] ?? 1);
            DB::table('invoices')->where('id', $inv->id)->update(['course_item_id' => $itemId]);
        }
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn(['course_item_id', 'lines', 'due_date']);
        });
    }
};
