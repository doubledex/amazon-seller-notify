<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('notifications_summaries', function (Blueprint $table) {
            $table->id();
            $table->string('notification_type');
            $table->string('notification_count');
            $table->string('last_update');
            $table->string('notification_rate');
            $table->string('subscription_id');
            $table->string('destination_id');
            $table->string('payload_version');
            $table->timestamps();
        });
    }
    
    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('notifications_summaries');
    }
};