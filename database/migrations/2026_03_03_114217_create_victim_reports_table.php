<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('victim_reports', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            $table->string('language', 10)->nullable();
            $table->enum('reporter_role', ['victim', 'someone_else', 'community_leader'])->nullable();
            $table->enum('urgency', ['urgent', 'support'])->nullable();
            $table->enum('case_type', ['physical', 'sexual', 'emotional', 'economic', 'child', 'other'])->nullable();
            $table->enum('input_mode', ['text', 'media', 'audio'])->nullable();

            $table->longText('details')->nullable();

            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();

            $table->string('status')->default('submitted');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('victim_reports');
    }
};