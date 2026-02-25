<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('us_fc_locations', function (Blueprint $table) {
            $table->id();
            $table->string('fulfillment_center_id', 32)->unique();
            $table->string('city', 120)->nullable();
            $table->string('state', 64)->nullable();
            $table->string('country_code', 2)->default('US');
            $table->string('label', 190)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('us_fc_locations');
    }
};
