<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        /*
        |--------------------------------------------------------------------------
        | Fix enum issue for quick emergency
        |--------------------------------------------------------------------------
        | MySQL enum is blocking values like:
        | case_type = emergency
        | input_mode = quick_emergency
        */

        // Temporarily reduce strict mode so MySQL warnings do not stop the ALTER.
        DB::statement("SET SESSION sql_mode = ''");

        // Convert ENUM columns to flexible VARCHAR columns.
        DB::statement("
            ALTER TABLE victim_reports
            MODIFY reporter_role VARCHAR(100) NULL
        ");

        DB::statement("
            ALTER TABLE victim_reports
            MODIFY urgency VARCHAR(50) NULL
        ");

        DB::statement("
            ALTER TABLE victim_reports
            MODIFY case_type VARCHAR(100) NULL
        ");

        DB::statement("
            ALTER TABLE victim_reports
            MODIFY input_mode VARCHAR(100) NULL
        ");

        // Clean empty enum values that may exist from previous failed inserts.
        DB::statement("
            UPDATE victim_reports
            SET reporter_role = NULL
            WHERE reporter_role = ''
        ");

        DB::statement("
            UPDATE victim_reports
            SET urgency = 'urgent'
            WHERE urgency IS NULL OR urgency = ''
        ");

        DB::statement("
            UPDATE victim_reports
            SET case_type = 'other'
            WHERE case_type IS NULL OR case_type = ''
        ");

        DB::statement("
            UPDATE victim_reports
            SET input_mode = 'text'
            WHERE input_mode IS NULL OR input_mode = ''
        ");
    }

    public function down(): void
    {
        DB::statement("SET SESSION sql_mode = ''");

        DB::statement("
            ALTER TABLE victim_reports
            MODIFY reporter_role ENUM('victim', 'someone_else', 'community_leader') NULL
        ");

        DB::statement("
            ALTER TABLE victim_reports
            MODIFY urgency ENUM('urgent', 'support') NULL
        ");

        DB::statement("
            ALTER TABLE victim_reports
            MODIFY case_type ENUM('physical', 'sexual', 'emotional', 'economic', 'child', 'other') NULL
        ");

        DB::statement("
            ALTER TABLE victim_reports
            MODIFY input_mode ENUM('text', 'media', 'audio') NULL
        ");
    }
};