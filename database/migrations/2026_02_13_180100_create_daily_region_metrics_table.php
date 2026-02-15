<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('daily_region_metrics', function (Blueprint $table) {
            $table->id();
            $table->date('metric_date');
            $table->string('region', 8); // UK or EU
            $table->string('local_currency', 3); // GBP for UK, EUR for EU
            $table->decimal('sales_local', 14, 2)->default(0);
            $table->decimal('sales_gbp', 14, 2)->default(0);
            $table->decimal('ad_spend_local', 14, 2)->default(0);
            $table->decimal('ad_spend_gbp', 14, 2)->default(0);
            $table->decimal('acos_percent', 8, 2)->nullable();
            $table->unsignedInteger('order_count')->default(0);
            $table->timestamps();

            $table->unique(['metric_date', 'region'], 'daily_region_metrics_unique');
            $table->index(['metric_date', 'region']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_region_metrics');
    }
};

