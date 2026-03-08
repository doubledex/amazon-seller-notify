<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('inbound_claim_sla_transitions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('discrepancy_id')
                ->constrained('inbound_discrepancies')
                ->cascadeOnDelete();
            $table->string('from_state', 32)->nullable()->index();
            $table->string('to_state', 32)->index();
            $table->json('metadata')->nullable();
            $table->dateTime('transitioned_at')->index();
            $table->timestamps();

            $table->index(['discrepancy_id', 'to_state', 'transitioned_at'], 'inbound_claim_sla_transition_lookup_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inbound_claim_sla_transitions');
    }
};
