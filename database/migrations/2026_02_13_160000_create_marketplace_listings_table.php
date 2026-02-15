<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marketplace_listings', function (Blueprint $table) {
            $table->id();
            $table->string('marketplace_id');
            $table->string('seller_sku');
            $table->string('asin')->nullable();
            $table->string('item_name')->nullable();
            $table->string('listing_status')->nullable();
            $table->integer('quantity')->nullable();
            $table->string('parentage')->nullable();
            $table->boolean('is_parent')->default(false);
            $table->string('source_report_id')->nullable();
            $table->dateTime('last_seen_at')->nullable();
            $table->json('raw_listing')->nullable();
            $table->timestamps();

            $table->unique(['marketplace_id', 'seller_sku']);
            $table->index(['marketplace_id', 'asin']);
            $table->index(['marketplace_id', 'listing_status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketplace_listings');
    }
};

