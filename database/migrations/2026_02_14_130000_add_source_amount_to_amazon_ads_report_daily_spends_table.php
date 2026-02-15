<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('amazon_ads_report_daily_spends', function (Blueprint $table) {
            $table->string('source_currency', 3)->nullable()->after('currency');
            $table->decimal('source_amount', 14, 2)->nullable()->after('source_currency');
            $table->index(['metric_date', 'source_currency'], 'ads_report_daily_source_currency_idx');
        });
    }

    public function down(): void
    {
        Schema::table('amazon_ads_report_daily_spends', function (Blueprint $table) {
            $table->dropIndex('ads_report_daily_source_currency_idx');
            $table->dropColumn(['source_currency', 'source_amount']);
        });
    }
};
