<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('inbound_claim_cases', function (Blueprint $table) {
            $table->json('evidence_validation')->nullable()->after('reimbursed_amount');
            $table->json('dossier_payload')->nullable()->after('evidence_validation');
            $table->text('dossier_summary')->nullable()->after('dossier_payload');
            $table->json('submission_references')->nullable()->after('dossier_summary');
        });
    }

    public function down(): void
    {
        Schema::table('inbound_claim_cases', function (Blueprint $table) {
            $table->dropColumn([
                'evidence_validation',
                'dossier_payload',
                'dossier_summary',
                'submission_references',
            ]);
        });
    }
};
