<?php

use App\Support\MessageTemplateDefaults;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Mẫu mặc định có default_key để "Khôi phục gốc" đúng mẫu (một loại có thể có nhiều mẫu mặc định).
 *
 * Sửa dữ liệu cũ: trang Điểm danh / Khách hàng tiềm năng / Học viên nghỉ / Vắng học / Sinh nhật từng dùng mẫu viết cứng
 * với id 1, 2, 3 nên "Sửa mẫu" ở đó ghi đè mẫu nhắc học phí... trong bảng (vd mẫu id 1 thành "Báo bài buổi học").
 * Mẫu nào mang tiêu đề mặc định của trang khác thì chuyển về đúng loại của trang đó (giữ nội dung đã sửa);
 * loại bị mất mẫu sẽ được tạo lại mẫu mặc định khi mở trang.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('message_templates', function (Blueprint $table) {
            $table->string('default_key', 50)->nullable()->after('id');
        });

        $byTitle = [];
        foreach (MessageTemplateDefaults::all() as $key => [$category, $title]) {
            $byTitle[$title] = [$key, $category];
        }
        $usedKeys = [];

        foreach (DB::table('message_templates')->orderBy('id')->get() as $row) {
            if (isset($byTitle[$row->title]) && ! isset($usedKeys[$byTitle[$row->title][0]])) {
                [$key, $category] = $byTitle[$row->title];
            } elseif (! isset($usedKeys[$row->category]) && isset(MessageTemplateDefaults::all()[$row->category])) {
                // Mẫu gốc của 4 loại tái đăng ký (default_key trùng tên loại)
                [$key, $category] = [$row->category, $row->category];
            } else {
                continue;
            }
            $usedKeys[$key] = true;
            DB::table('message_templates')->where('id', $row->id)->update(['default_key' => $key, 'category' => $category, 'is_default' => true]);
        }
    }

    public function down(): void
    {
        Schema::table('message_templates', function (Blueprint $table) {
            $table->dropColumn('default_key');
        });
    }
};
