<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('courses', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('code')->unique();
            $table->string('category')->default('Âm nhạc'); // Âm nhạc, Mỹ thuật, Ngoại ngữ, Kỹ năng
            $table->integer('default_sessions')->default(12);
            $table->decimal('price', 12, 0)->default(0);
            $table->string('color', 20)->default('#4f46e5');
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('courses');
    }
};
