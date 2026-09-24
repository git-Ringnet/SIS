<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leads', function (Blueprint $table) {
            $table->id();
            $table->string('parent_name');
            $table->string('student_name')->nullable();
            $table->string('phone', 20);
            $table->string('email')->nullable();
            $table->foreignId('interested_course_id')->nullable()->constrained('courses')->nullOnDelete();
            $table->string('source', 50)->default('Facebook'); // Facebook, Zalo, Hotline, Giới thiệu, Website, Trực tiếp
            $table->string('status', 30)->default('new'); // new (Mới tiếp nhận), called (Đã gọi), quote_sent (Gửi báo giá), trial_done (Đã học thử), registered (Đã đăng ký), lost (Đã hủy)
            $table->dateTime('trial_date')->nullable();
            $table->decimal('expected_revenue', 12, 0)->default(0);
            $table->text('notes')->nullable();
            $table->string('assigned_to')->default('Tư vấn viên');
            $table->foreignId('converted_student_id')->nullable()->constrained('students')->nullOnDelete();
            $table->timestamps();

            $table->index('status');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leads');
    }
};
