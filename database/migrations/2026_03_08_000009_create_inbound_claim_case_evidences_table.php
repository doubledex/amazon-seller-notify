<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('inbound_claim_case_evidences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('claim_case_id')
                ->constrained('inbound_claim_cases')
                ->cascadeOnDelete();
            $table->foreignId('discrepancy_id')
                ->constrained('inbound_discrepancies')
                ->cascadeOnDelete();
            $table->string('artifact_type', 64)->index();
            $table->string('disk', 64)->default('local');
            $table->string('path', 2048);
            $table->string('checksum', 128);
            $table->dateTime('uploaded_at')->index();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['claim_case_id', 'artifact_type', 'path', 'checksum'], 'inbound_claim_case_evidence_uniq');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inbound_claim_case_evidences');
    }
};
