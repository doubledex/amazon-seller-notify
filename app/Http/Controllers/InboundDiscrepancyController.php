<?php

namespace App\Http\Controllers;

use App\Models\InboundDiscrepancy;
use App\Services\Amazon\Inbound\ClaimEvidenceBuilder;
use App\Services\Amazon\Inbound\InboundClaimSlaService;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

class InboundDiscrepancyController extends Controller
{
    public function index(Request $request)
    {
        $status = strtolower(trim((string) $request->query('status', 'open')));
        if (!in_array($status, ['open', 'resolved', 'all'], true)) {
            $status = 'open';
        }

        $severity = strtolower(trim((string) $request->query('severity', '')));
        $allowedSeverities = ['low', 'normal', 'medium', 'high', 'critical', 'urgent', 'missed'];
        if ($severity !== '' && !in_array($severity, $allowedSeverities, true)) {
            $severity = '';
        }

        $splitOnly = (string) $request->query('split_only', '0') === '1';

        if (!Schema::hasTable('inbound_discrepancies')) {
            $rows = new LengthAwarePaginator(
                new Collection(),
                0,
                50,
                1,
                [
                    'path' => $request->url(),
                    'query' => $request->query(),
                ]
            );

            return view('inbound.discrepancies.index', [
                'rows' => $rows,
                'status' => $status,
                'severity' => $severity,
                'splitOnly' => $splitOnly,
                'schemaMissing' => true,
            ]);
        }

        $query = InboundDiscrepancy::query()
            ->with('shipment:id,shipment_id,marketplace_id,region_code')
            ->withCount('claimCases')
            ->orderBy('challenge_deadline_at')
            ->orderByDesc('value_impact');

        if ($status !== 'all') {
            $query->where('status', $status);
        }

        if ($severity !== '') {
            $query->where('severity', $severity);
        }

        if ($splitOnly) {
            $query->where('split_carton', true);
        }

        $rows = $query->paginate(50)->appends($request->query());

        return view('inbound.discrepancies.index', [
            'rows' => $rows,
            'status' => $status,
            'severity' => $severity,
            'splitOnly' => $splitOnly,
            'schemaMissing' => false,
        ]);
    }

    public function show(int $id)
    {
        $discrepancy = InboundDiscrepancy::query()
            ->with([
                'shipment:id,shipment_id,region_code,marketplace_id,carrier_name,pro_tracking_number,shipment_created_at,shipment_closed_at',
                'shipment.cartons:id,shipment_id,carton_id,sku,fnsku,units_per_carton,carton_count,expected_units',
                'claimCases.evidences',
                'slaTransitions',
            ])
            ->findOrFail($id);

        return view('inbound.discrepancies.show', [
            'discrepancy' => $discrepancy,
        ]);
    }

    public function evaluateSla(InboundClaimSlaService $service): RedirectResponse
    {
        $summary = $service->evaluate(200);

        return back()->with('status', sprintf(
            'SLA evaluated: %d rows, %d queued, %d near-expiry alerts, %d missed alerts.',
            (int) ($summary['evaluated'] ?? 0),
            (int) ($summary['queued'] ?? 0),
            (int) ($summary['near_expiry_notified'] ?? 0),
            (int) ($summary['missed_notified'] ?? 0)
        ));
    }

    public function buildEvidence(int $id, ClaimEvidenceBuilder $builder): RedirectResponse
    {
        $discrepancy = InboundDiscrepancy::query()->findOrFail($id);

        $claimCase = $builder->build($discrepancy, [], [
            'context' => [
                'trigger' => 'ui_build_evidence',
                'triggered_at' => now()->toIso8601String(),
            ],
        ]);

        return back()->with('status', sprintf(
            'Claim dossier refreshed for discrepancy #%d (case #%d).',
            $discrepancy->id,
            $claimCase->id
        ));
    }
}
