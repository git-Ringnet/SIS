<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('students', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->date('dob')->nullable();
            $table->string('gender', 10)->default('Nam');
            $table->string('parent_name');
            $table->string('parent_phone', 20);
            $table->string('parent_zalo', 20)->nullable();
            $table->string('parent_email')->nullable();
            $table->foreignId('course_id')->constrained('courses')->cascadeOnDelete();
            $table->integer('total_sessions')->default(12);
            $table->integer('remaining_sessions')->default(12);
            $table->string('status', 20)->default('active'); // active, paused, dropped, completed
            $table->string('retention_status', 100)->default('normal'); // normal, invoice_sent, following_up, renewed
            $table->timestamp('last_retention_contact_at')->nullable();
            $table->integer('consecutive_absences')->default(0);
            $table->date('join_date')->nullable();
            $table->date('end_date')->nullable();
            $table->string('center')->nullable()->default('Khoa Anh Music Hóc Môn');
            $table->string('schedule_info')->nullable()->default('1 buổi/tuần');
            $table->string('assigned_class')->nullable();
            $table->integer('allowed_absences')->default(3);
            $table->integer('absences_used')->default(1);
            $table->json('supplies')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            // Indexing for rapid queries and retention filtering
            $table->index(['remaining_sessions', 'status']);
            $table->index(['retention_status', 'status']);
            $table->index('consecutive_absences');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('students');
    }
};
