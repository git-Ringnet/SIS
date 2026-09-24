<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('retention_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
            $table->string('action_type', 40)->default('note_added'); // invoice_sent, call_care, sms_zalo_sent, renewed_payment, note_added
            $table->string('status', 30)->default('following_up'); // invoice_sent, following_up, renewed, note
            $table->dateTime('contact_date');
            $table->text('content');
            $table->date('next_followup_date')->nullable();
            $table->string('created_by')->default('Admin');
            $table->timestamps();

            $table->index(['student_id', 'contact_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('retention_logs');
    }
};
