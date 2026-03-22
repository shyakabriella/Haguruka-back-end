<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE victim_reports MODIFY reporter_role VARCHAR(255) NULL");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE victim_reports MODIFY reporter_role ENUM('victim','someone_else','community_leader') NULL");
    }
};