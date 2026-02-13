<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('postal_code_geos', function (Blueprint $table) {
            $table->id();
            $table->string('country_code', 2);
            $table->string('postal_code', 64);
            $table->decimal('lat', 10, 7);
            $table->decimal('lng', 10, 7);
            $table->string('source')->nullable();
            $table->timestamps();

            $table->unique(['country_code', 'postal_code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('postal_code_geos');
    }
};
