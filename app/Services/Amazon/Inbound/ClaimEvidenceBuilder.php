<?php

namespace App\Services\Amazon\Inbound;

use App\Models\InboundClaimCase;
use App\Models\InboundClaimCaseEvidence;
use App\Models\InboundDiscrepancy;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

class ClaimEvidenceBuilder
{
    public function build(
        InboundDiscrepancy $discrepancy,
        array $artifacts,
        array $submissionContext = [],
        string $checklist = 'default'
    ): InboundClaimCase {
        $discrepancy->loadMissing(['shipment', 'shipment.cartons']);

        $claimCase = InboundClaimCase::query()->firstOrCreate(
            ['discrepancy_id' => $discrepancy->id],
            ['challenge_deadline_at' => $discrepancy->challenge_deadline_at]
        );

        $stored = $this->storeEvidenceRecords($claimCase, $discrepancy, $artifacts);
        $virtualArtifacts = $this->collectVirtualArtifacts($discrepancy);
        $validation = $this->validateCompleteness($stored, $virtualArtifacts, $checklist);

        $dossierPayload = $this->buildDossierPayload($discrepancy, $claimCase, $stored, $virtualArtifacts, $validation);

        $references = $this->captureSubmissionReferences($claimCase, $submissionContext);

        $claimCase->fill([
            'evidence_validation' => $validation,
            'dossier_payload' => $dossierPayload,
            'dossier_summary' => $this->buildSummary($dossierPayload, $validation),
            'submission_references' => $references,
        ]);
        $claimCase->save();

        return $claimCase->fresh(['evidences']);
    }

    private function storeEvidenceRecords(InboundClaimCase $claimCase, InboundDiscrepancy $discrepancy, array $artifacts): array
    {
        $stored = [];

        foreach ($artifacts as $artifact) {
            $artifactType = trim((string) Arr::get($artifact, 'artifact_type', ''));
            $path = trim((string) Arr::get($artifact, 'path', ''));
            if ($artifactType === '' || $path === '') {
                continue;
            }

            $disk = (string) Arr::get($artifact, 'disk', config('amazon_inbound_claims.evidence.default_disk', 'local'));
            $uploadedAt = Carbon::parse(Arr::get($artifact, 'uploaded_at', now()));
            $checksum = (string) Arr::get($artifact, 'checksum', $this->checksumFor($disk, $path));

            $record = InboundClaimCaseEvidence::query()->firstOrCreate([
                'claim_case_id' => $claimCase->id,
                'artifact_type' => $artifactType,
                'path' => $path,
                'checksum' => $checksum,
            ], [
                'discrepancy_id' => $discrepancy->id,
                'disk' => $disk,
                'uploaded_at' => $uploadedAt,
                'metadata' => Arr::get($artifact, 'metadata', []),
            ]);

            $stored[] = [
                'id' => $record->id,
                'artifact_type' => $record->artifact_type,
                'disk' => $record->disk,
                'path' => $record->path,
                'checksum' => $record->checksum,
                'uploaded_at' => $record->uploaded_at?->toIso8601String(),
                'metadata' => $record->metadata ?? [],
            ];
        }

        return $stored;
    }

    private function collectVirtualArtifacts(InboundDiscrepancy $discrepancy): array
    {
        $cartons = $discrepancy->shipment?->cartons
            ? $discrepancy->shipment->cartons->where('sku', $discrepancy->sku)->where('fnsku', $discrepancy->fnsku)->values()
            : collect();

        return [
            'shipment_ids' => [
                'shipment_id' => $discrepancy->shipment_id,
                'carrier_name' => $discrepancy->shipment?->carrier_name,
                'pro_tracking_number' => $discrepancy->shipment?->pro_tracking_number,
            ],
            'sku_fnsku_mapping' => [
                'sku' => $discrepancy->sku,
                'fnsku' => $discrepancy->fnsku,
                'carton_ids' => $cartons->pluck('carton_id')->values()->all(),
            ],
        ];
    }

    private function validateCompleteness(array $storedArtifacts, array $virtualArtifacts, string $checklist): array
    {
        $checklistConfig = (array) config("amazon_inbound_claims.evidence.checklists.{$checklist}", []);
        $requiredArtifacts = (array) Arr::get($checklistConfig, 'required_artifacts', []);
        $requiredVirtual = (array) Arr::get($checklistConfig, 'required_virtual_artifacts', []);

        $presentTypes = collect($storedArtifacts)->pluck('artifact_type')->unique()->values()->all();

        $missingArtifacts = array_values(array_diff($requiredArtifacts, $presentTypes));
        $missingVirtual = array_values(array_filter($requiredVirtual, fn (string $key) => empty($virtualArtifacts[$key])));

        return [
            'checklist' => $checklist,
            'required_artifacts' => $requiredArtifacts,
            'required_virtual_artifacts' => $requiredVirtual,
            'present_artifacts' => $presentTypes,
            'missing_artifacts' => $missingArtifacts,
            'missing_virtual_artifacts' => $missingVirtual,
            'complete' => empty($missingArtifacts) && empty($missingVirtual),
            'validated_at' => now()->toIso8601String(),
        ];
    }

    private function buildDossierPayload(
        InboundDiscrepancy $discrepancy,
        InboundClaimCase $claimCase,
        array $storedArtifacts,
        array $virtualArtifacts,
        array $validation
    ): array {
        return [
            'claim_case_id' => $claimCase->id,
            'discrepancy_id' => $discrepancy->id,
            'claim_ready' => (bool) $validation['complete'],
            'generated_at' => now()->toIso8601String(),
            'discrepancy' => [
                'shipment_id' => $discrepancy->shipment_id,
                'sku' => $discrepancy->sku,
                'fnsku' => $discrepancy->fnsku,
                'expected_units' => $discrepancy->expected_units,
                'received_units' => $discrepancy->received_units,
                'delta' => $discrepancy->delta,
                'value_impact' => (float) $discrepancy->value_impact,
                'challenge_deadline_at' => $discrepancy->challenge_deadline_at?->toIso8601String(),
            ],
            'evidence' => [
                'artifacts' => $storedArtifacts,
                'virtual_artifacts' => $virtualArtifacts,
                'validation' => $validation,
            ],
        ];
    }

    private function buildSummary(array $dossierPayload, array $validation): string
    {
        $lineItems = [
            'Inbound Claim Dossier',
            sprintf('Claim case: #%d | Discrepancy: #%d', $dossierPayload['claim_case_id'], $dossierPayload['discrepancy_id']),
            sprintf(
                'Shipment %s | SKU %s | FNSKU %s | Delta %d units | Value Impact %.2f',
                $dossierPayload['discrepancy']['shipment_id'],
                $dossierPayload['discrepancy']['sku'],
                $dossierPayload['discrepancy']['fnsku'],
                $dossierPayload['discrepancy']['delta'],
                $dossierPayload['discrepancy']['value_impact']
            ),
            'Checklist: ' . $validation['checklist'],
            'Complete: ' . ($validation['complete'] ? 'YES' : 'NO'),
        ];

        if (!empty($validation['missing_artifacts'])) {
            $lineItems[] = 'Missing artifacts: ' . implode(', ', $validation['missing_artifacts']);
        }

        if (!empty($validation['missing_virtual_artifacts'])) {
            $lineItems[] = 'Missing virtual artifacts: ' . implode(', ', $validation['missing_virtual_artifacts']);
        }

        return implode("\n", $lineItems);
    }

    private function captureSubmissionReferences(InboundClaimCase $claimCase, array $submissionContext): array
    {
        $existing = (array) ($claimCase->submission_references ?? []);
        $entries = (array) Arr::get($existing, 'entries', []);

        $requestIds = array_values(array_filter(array_map('strval', (array) Arr::get($submissionContext, 'request_ids', []))));
        $submissionRefs = array_values(array_filter(array_map('strval', (array) Arr::get($submissionContext, 'submission_references', []))));

        if (!empty($requestIds) || !empty($submissionRefs)) {
            $entries[] = [
                'captured_at' => now()->toIso8601String(),
                'request_ids' => $requestIds,
                'submission_references' => $submissionRefs,
                'context' => Arr::get($submissionContext, 'context', []),
            ];
        }

        return [
            'entries' => $entries,
            'latest_request_ids' => $requestIds,
            'latest_submission_references' => $submissionRefs,
        ];
    }

    private function checksumFor(string $disk, string $path): string
    {
        $algo = (string) config('amazon_inbound_claims.evidence.checksum_algorithm', 'sha256');

        $storage = Storage::disk($disk);
        if ($storage->exists($path)) {
            $content = $storage->get($path);

            return hash($algo, $content);
        }

        return hash($algo, $disk . ':' . $path);
    }
}
