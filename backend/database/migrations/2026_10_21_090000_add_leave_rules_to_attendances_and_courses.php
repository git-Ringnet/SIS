<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Quy tắc nghỉ của trung tâm:
     * - attendances.deducts_session: buổi vắng có bị trừ vào số buổi học hay không (vắng không phép / nghỉ có phép đã hết lượt).
     *   session_number để trống với buổi nghỉ không trừ buổi (trước đây buổi nghỉ bị gán số buổi → "nhảy buổi").
     * - courses.allowed_absences: số buổi được nghỉ có phép của khóa (1 tháng = 1 buổi, 3 tháng = 3 buổi...).
     * - students.absences_used (số buổi đã nghỉ có phép) trước đây mặc định 1 nên học viên mới đã bị tính 1 buổi nghỉ:
     *   đổi mặc định về 0 và trả về 0 cho học viên đang là 1 mà chưa có buổi nghỉ có phép nào.
     */
    public function up(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->integer('absences_used')->default(0)->change();
        });

        DB::table('students')
            ->where('absences_used', 1)
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('attendances')
                ->whereColumn('attendances.student_id', 'students.id')
                ->where('attendances.status', 'absent_with_leave'))
            ->update(['absences_used' => 0]);

        Schema::table('attendances', function (Blueprint $table) {
            $table->boolean('deducts_session')->default(false)->after('status');
            $table->integer('session_number')->nullable()->default(null)->change();
        });

        Schema::table('courses', function (Blueprint $table) {
            $table->unsignedInteger('allowed_absences')->nullable()->after('default_sessions');
        });
    }

    public function down(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            $table->dropColumn('deducts_session');
            $table->integer('session_number')->default(1)->change();
        });

        Schema::table('courses', function (Blueprint $table) {
            $table->dropColumn('allowed_absences');
        });

        Schema::table('students', function (Blueprint $table) {
            $table->integer('absences_used')->default(1)->change();
        });
    }
};
