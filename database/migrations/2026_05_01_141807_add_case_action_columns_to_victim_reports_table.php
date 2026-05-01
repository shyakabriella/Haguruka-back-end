<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('victim_reports', function (Blueprint $table) {
            if (!Schema::hasColumn('victim_reports', 'withdraw_reason')) {
                $table->text('withdraw_reason')->nullable()->after('status');
            }

            if (!Schema::hasColumn('victim_reports', 'withdrawn_at')) {
                $table->timestamp('withdrawn_at')->nullable()->after('withdraw_reason');
            }

            if (!Schema::hasColumn('victim_reports', 'withdrawn_by')) {
                $table->foreignId('withdrawn_by')
                    ->nullable()
                    ->after('withdrawn_at')
                    ->constrained('users')
                    ->nullOnDelete();
            }

            if (!Schema::hasColumn('victim_reports', 'closed_reason')) {
                $table->text('closed_reason')->nullable()->after('withdrawn_by');
            }

            if (!Schema::hasColumn('victim_reports', 'closed_at')) {
                $table->timestamp('closed_at')->nullable()->after('closed_reason');
            }

            if (!Schema::hasColumn('victim_reports', 'closed_by')) {
                $table->foreignId('closed_by')
                    ->nullable()
                    ->after('closed_at')
                    ->constrained('users')
                    ->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('victim_reports', function (Blueprint $table) {
            if (Schema::hasColumn('victim_reports', 'closed_by')) {
                $table->dropConstrainedForeignId('closed_by');
            }

            if (Schema::hasColumn('victim_reports', 'withdrawn_by')) {
                $table->dropConstrainedForeignId('withdrawn_by');
            }

            if (Schema::hasColumn('victim_reports', 'closed_at')) {
                $table->dropColumn('closed_at');
            }

            if (Schema::hasColumn('victim_reports', 'closed_reason')) {
                $table->dropColumn('closed_reason');
            }

            if (Schema::hasColumn('victim_reports', 'withdrawn_at')) {
                $table->dropColumn('withdrawn_at');
            }

            if (Schema::hasColumn('victim_reports', 'withdraw_reason')) {
                $table->dropColumn('withdraw_reason');
            }
        });
    }
};