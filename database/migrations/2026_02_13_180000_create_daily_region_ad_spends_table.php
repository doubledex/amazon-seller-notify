<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('daily_region_ad_spends', function (Blueprint $table) {
            $table->id();
            $table->date('metric_date');
            $table->string('region', 8); // UK or EU
            $table->string('currency', 3);
            $table->decimal('amount_local', 14, 2);
            $table->string('source', 64)->default('manual');
            $table->timestamps();

            $table->unique(['metric_date', 'region', 'currency', 'source'], 'daily_region_ad_spends_unique');
            $table->index(['metric_date', 'region']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_region_ad_spends');
    }
};

