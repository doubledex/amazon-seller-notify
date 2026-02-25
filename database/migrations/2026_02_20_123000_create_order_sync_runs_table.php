<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_sync_runs', function (Blueprint $table) {
            $table->id();
            $table->string('source', 32)->nullable();
            $table->string('region', 8);
            $table->unsignedSmallInteger('days')->default(7);
            $table->unsignedSmallInteger('max_pages')->default(5);
            $table->unsignedSmallInteger('items_limit')->default(50);
            $table->unsignedSmallInteger('address_limit')->default(50);
            $table->dateTime('created_after_at')->nullable();
            $table->dateTime('created_before_at')->nullable();
            $table->dateTime('started_at');
            $table->dateTime('finished_at')->nullable();
            $table->string('status', 16)->default('running');
            $table->text('message')->nullable();
            $table->unsignedInteger('orders_synced')->default(0);
            $table->unsignedInteger('items_fetched')->default(0);
            $table->unsignedInteger('addresses_fetched')->default(0);
            $table->unsignedInteger('geocoded')->default(0);
            $table->timestamps();

            $table->index(['finished_at']);
            $table->index(['status']);
            $table->index(['region']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_sync_runs');
    }
};

