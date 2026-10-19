<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activity_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('user_name')->nullable();
            $table->string('user_email')->nullable();
            $table->string('user_role', 100)->nullable();
            $table->string('action', 20); // created, updated, deleted
            $table->string('subject_type', 50); // student, invoice, course, lead, center, staff, settings...
            $table->string('subject_id', 64)->nullable();
            $table->string('subject_label')->nullable();
            $table->string('context')->nullable(); // Thông tin phụ, ví dụ tên học viên của hóa đơn
            $table->json('changes')->nullable(); // [{field, label, old, new}]
            $table->string('ip', 45)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index('created_at');
            $table->index(['subject_type', 'action']);
            $table->index('user_email');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_logs');
    }
};
