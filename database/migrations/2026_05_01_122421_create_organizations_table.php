<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organizations', function (Blueprint $table) {
            $table->id();

            $table->string('name');
            $table->string('type')->default('ngo');
            $table->string('district')->nullable();
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->string('status')->default('active');

            $table->timestamps();

            $table->index(['type', 'status']);
            $table->index('district');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organizations');
    }
};