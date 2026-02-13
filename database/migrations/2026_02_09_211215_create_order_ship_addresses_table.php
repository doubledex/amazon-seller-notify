<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_ship_addresses', function (Blueprint $table) {
            $table->id();
            $table->string('order_id')->unique();
            $table->string('country_code', 2)->nullable();
            $table->string('postal_code', 64)->nullable();
            $table->string('city')->nullable();
            $table->string('region')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_ship_addresses');
    }
};
