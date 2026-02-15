<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('city_geos', function (Blueprint $table) {
            $table->id();
            $table->string('country_code', 2);
            $table->string('city', 191);
            $table->string('region', 191)->nullable();
            $table->string('lookup_hash', 40)->unique();
            $table->decimal('lat', 10, 7);
            $table->decimal('lng', 10, 7);
            $table->string('source')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();

            $table->index(['country_code', 'city', 'region']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('city_geos');
    }
};
