<?php

use App\Models\InboundClaimCase;
use App\Models\InboundClaimCaseEvidence;
use App\Models\InboundDiscrepancy;
use App\Models\InboundShipment;
use App\Models\InboundShipmentCarton;
use App\Services\Amazon\Inbound\ClaimEvidenceBuilder;
use Illuminate\Support\Facades\Storage;

it('builds, validates, and stores immutable evidence dossier for discrepancy claims', function () {
    Storage::fake('local');

    config()->set('amazon_inbound_claims.evidence.default_disk', 'local');
    config()->set('amazon_inbound_claims.evidence.checklists.default.required_artifacts', [
        'invoice',
        'bill_of_lading',
        'proof_of_delivery',
        'carton_labels',
        'carton_manifest',
    ]);

    $shipment = InboundShipment::query()->create([
        'shipment_id' => 'SHIP-CLAIM-1',
        'region_code' => 'NA',
        'marketplace_id' => 'ATVPDKIKX0DER',
        'carrier_name' => 'UPS',
        'pro_tracking_number' => '1Z999AA10123456784',
    ]);

    InboundShipmentCarton::query()->create([
        'shipment_id' => $shipment->shipment_id,
        'carton_id' => 'BOX-1',
        'sku' => 'SKU-1',
        'fnsku' => 'FNSKU-1',
        'expected_units' => 10,
        'units_per_carton' => 5,
        'carton_count' => 2,
    ]);

    $discrepancy = InboundDiscrepancy::query()->create([
        'shipment_id' => $shipment->shipment_id,
        'sku' => 'SKU-1',
        'fnsku' => 'FNSKU-1',
        'expected_units' => 10,
        'received_units' => 8,
        'delta' => -2,
        'value_impact' => 40,
        'status' => 'open',
        'challenge_deadline_at' => now()->addDays(10),
    ]);

    Storage::disk('local')->put('claims/invoice.pdf', 'invoice-content');
    Storage::disk('local')->put('claims/bol.pdf', 'bol-content');
    Storage::disk('local')->put('claims/pod.pdf', 'pod-content');
    Storage::disk('local')->put('claims/labels.zip', 'labels-content');

    $builder = app(ClaimEvidenceBuilder::class);

    $claim = $builder->build($discrepancy, [
        ['artifact_type' => 'invoice', 'path' => 'claims/invoice.pdf'],
        ['artifact_type' => 'bill_of_lading', 'path' => 'claims/bol.pdf'],
        ['artifact_type' => 'proof_of_delivery', 'path' => 'claims/pod.pdf'],
        ['artifact_type' => 'carton_labels', 'path' => 'claims/labels.zip'],
    ], [
        'request_ids' => ['REQ-123'],
        'submission_references' => ['SUB-001'],
        'context' => ['operator' => 'qa-user'],
    ]);

    expect($claim)->toBeInstanceOf(InboundClaimCase::class)
        ->and($claim->evidences)->toHaveCount(4)
        ->and($claim->evidence_validation['complete'])->toBeFalse()
        ->and($claim->evidence_validation['missing_artifacts'])->toContain('carton_manifest')
        ->and($claim->dossier_payload['claim_ready'])->toBeFalse()
        ->and($claim->submission_references['latest_request_ids'])->toBe(['REQ-123'])
        ->and($claim->submission_references['latest_submission_references'])->toBe(['SUB-001'])
        ->and($claim->dossier_summary)->toContain('Missing artifacts: carton_manifest');

    Storage::disk('local')->put('claims/manifest.csv', 'manifest-content');

    $claim = $builder->build($discrepancy, [
        ['artifact_type' => 'invoice', 'path' => 'claims/invoice.pdf'],
        ['artifact_type' => 'bill_of_lading', 'path' => 'claims/bol.pdf'],
        ['artifact_type' => 'proof_of_delivery', 'path' => 'claims/pod.pdf'],
        ['artifact_type' => 'carton_labels', 'path' => 'claims/labels.zip'],
        ['artifact_type' => 'carton_manifest', 'path' => 'claims/manifest.csv'],
    ], [
        'request_ids' => ['REQ-456'],
        'submission_references' => ['SUB-002'],
    ]);

    expect($claim->evidence_validation['complete'])->toBeTrue()
        ->and($claim->dossier_payload['claim_ready'])->toBeTrue()
        ->and($claim->dossier_payload['evidence']['virtual_artifacts']['shipment_ids']['shipment_id'])->toBe('SHIP-CLAIM-1')
        ->and($claim->dossier_payload['evidence']['virtual_artifacts']['sku_fnsku_mapping']['carton_ids'])->toBe(['BOX-1'])
        ->and($claim->submission_references['entries'])->toHaveCount(2);

    expect(InboundClaimCaseEvidence::query()->count())->toBe(5);
});
