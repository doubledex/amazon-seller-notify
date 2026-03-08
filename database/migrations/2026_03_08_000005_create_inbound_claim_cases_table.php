<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('inbound_claim_cases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('discrepancy_id')
                ->constrained('inbound_discrepancies')
                ->cascadeOnDelete();
            $table->dateTime('challenge_deadline_at')->nullable()->index();
            $table->dateTime('submitted_at')->nullable()->index();
            $table->string('outcome', 64)->nullable()->index();
            $table->unsignedInteger('reimbursed_units')->default(0);
            $table->decimal('reimbursed_amount', 12, 2)->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inbound_claim_cases');
    }
};
