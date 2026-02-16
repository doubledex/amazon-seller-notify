<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('us_fc_inventories', function (Blueprint $table) {
            $table->id();
            $table->string('marketplace_id', 32)->index();
            $table->string('fulfillment_center_id', 32)->index();
            $table->string('seller_sku', 128)->nullable()->index();
            $table->string('asin', 32)->nullable()->index();
            $table->string('fnsku', 64)->nullable()->index();
            $table->string('item_condition', 64)->nullable();
            $table->integer('quantity_available')->default(0);
            $table->json('raw_row')->nullable();
            $table->string('report_id', 64)->nullable()->index();
            $table->string('report_type', 128)->nullable();
            $table->date('report_date')->nullable()->index();
            $table->timestamp('last_seen_at')->nullable()->index();
            $table->timestamps();

            $table->unique(
                ['marketplace_id', 'fulfillment_center_id', 'seller_sku', 'fnsku', 'item_condition'],
                'us_fc_inventory_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('us_fc_inventories');
    }
};
