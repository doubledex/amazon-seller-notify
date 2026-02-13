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
        Schema::create('sqs_messages', function (Blueprint $table) {
            $table->id(); // Auto-incrementing primary key
            $table->string('message_id')->unique(); // Unique ID from SQS
            $table->text('body');       // The message payload (can be large)
            $table->string('receipt_handle'); // For deleting from SQS
            $table->boolean('processed')->default(false); // Flag to prevent reprocessing
            $table->timestamps(); // Optional: Add timestamps if needed
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sqs_messages');
    }
};