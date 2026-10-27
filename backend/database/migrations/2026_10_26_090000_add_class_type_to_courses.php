<?php

use App\Models\Course;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Hình thức học của khóa: group (lớp nhóm) / individual (cá nhân 1:1), null = theo số học viên trong ca.
     * Ca dạy của giáo viên là ca nhóm hay ca 1:1 lấy theo hình thức này (tính lương ca nhóm / ca 1:1).
     * Khóa đang có tự nhận theo tên do trung tâm đặt, vd "[HSSV-NHÓM] ..." / "[HSSV-CÁ NHÂN] ...".
     */
    public function up(): void
    {
        Schema::table('courses', function (Blueprint $table) {
            $table->string('class_type', 20)->nullable()->after('allowed_absences');
        });

        foreach (DB::table('courses')->get(['id', 'name']) as $course) {
            if ($type = Course::guessClassType($course->name)) {
                DB::table('courses')->where('id', $course->id)->update(['class_type' => $type]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('courses', function (Blueprint $table) {
            $table->dropColumn('class_type');
        });
    }
};
