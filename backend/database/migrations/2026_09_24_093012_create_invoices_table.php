<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->string('invoice_code')->unique();
            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
            $table->foreignId('course_id')->nullable()->constrained('courses')->nullOnDelete();
            $table->decimal('amount', 14, 0)->default(0);
            $table->integer('sessions_added')->default(12);
            $table->string('payment_method', 30)->default('bank_transfer'); // bank_transfer, cash, pos, momo
            $table->string('status', 20)->default('paid'); // paid, pending, cancelled
            $table->dateTime('paid_at')->nullable();
            $table->text('note')->nullable();
            $table->string('recorded_by')->default('Admin');
            $table->timestamps();

            $table->index(['paid_at', 'status']);
            $table->index('student_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};
