<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_points', function (Blueprint $table) {
            $table->id();

            $table->foreignId('organization_id')
                ->constrained('organizations')
                ->cascadeOnDelete();

            $table->string('name');
            $table->string('district')->nullable();
            $table->string('sector')->nullable();
            $table->string('phone')->nullable();
            $table->string('email')->nullable();

            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();

            $table->string('status')->default('active');

            $table->timestamps();

            $table->index(['organization_id', 'status']);
            $table->index('district');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_points');
    }
};