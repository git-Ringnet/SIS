<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Lưu nhiều trạng thái cùng lúc (vd: "following_up,renewed,invoice_sent") nên 30 ký tự không đủ
        Schema::table('students', function (Blueprint $table) {
            $table->string('retention_status', 100)->default('normal')->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->string('retention_status', 30)->default('normal')->change();
        });
    }
};
