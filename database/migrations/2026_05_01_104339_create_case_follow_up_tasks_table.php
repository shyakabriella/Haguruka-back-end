<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('case_follow_up_tasks', function (Blueprint $table) {
            $table->id();

            $table->foreignId('victim_report_id')
                ->constrained('victim_reports')
                ->cascadeOnDelete();

            $table->foreignId('created_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->foreignId('assigned_to')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->string('title');
            $table->text('description')->nullable();

            $table->enum('priority', ['low', 'medium', 'high'])->default('medium');

            $table->enum('status', [
                'pending',
                'in_progress',
                'done',
                'cancelled'
            ])->default('pending');

            $table->date('due_date')->nullable();
            $table->timestamp('completed_at')->nullable();

            $table->timestamps();

            $table->index(['victim_report_id', 'status']);
            $table->index(['assigned_to', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('case_follow_up_tasks');
    }
};