<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->string('center')->nullable()->default('Khoa Anh Music Hóc Môn')->after('assigned_to');
            $table->string('image_url')->nullable()->after('center');
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropColumn(['center', 'image_url']);
        });
    }
};
