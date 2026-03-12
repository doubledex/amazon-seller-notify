<?php

namespace App\Http\Controllers;

use App\Models\InboundDiscrepancy;
use App\Services\Amazon\Inbound\ClaimEvidenceBuilder;
use App\Services\Amazon\Inbound\InboundClaimSlaService;
use App\Services\Amazon\Inbound\InboundShipmentSyncService;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
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

        $fcLookup = DB::table('us_fc_inventories')
            ->selectRaw("marketplace_id, COALESCE(seller_sku, '') as sku, COALESCE(fnsku, '') as fnsku")
            ->selectRaw("MAX(COALESCE(fulfillment_center_id, 'n/a')) as fulfillment_center_id")
            ->groupBy('marketplace_id', 'sku', 'fnsku');

        $query = InboundDiscrepancy::query()
            ->leftJoin('inbound_shipments as s', 's.shipment_id', '=', 'inbound_discrepancies.shipment_id')
            ->leftJoinSub($fcLookup, 'fc', function ($join) {
                $join->on('fc.marketplace_id', '=', 's.marketplace_id')
                    ->on('fc.sku', '=', 'inbound_discrepancies.sku')
                    ->on('fc.fnsku', '=', 'inbound_discrepancies.fnsku');
            })
            ->select('inbound_discrepancies.*')
            ->selectRaw("COALESCE(fc.fulfillment_center_id, 'n/a') as destination_fulfillment_center_id")
            ->with('shipment:id,shipment_id,marketplace_id,region_code,api_shipment_payload')
            ->withCount('claimCases')
            ->orderBy('inbound_discrepancies.challenge_deadline_at')
            ->orderByDesc('inbound_discrepancies.value_impact');

        if ($status !== 'all') {
            $query->where('inbound_discrepancies.status', $status);
        }

        if ($severity !== '') {
            $query->where('inbound_discrepancies.severity', $severity);
        }

        if ($splitOnly) {
            $query->where('inbound_discrepancies.split_carton', true);
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
        $discrepancy = $this->loadDiscrepancy($id);

        return view('inbound.discrepancies.show', [
            'discrepancy' => $discrepancy,
            'debugApiPayload' => session()->get($this->debugApiPayloadSessionKey($id)),
            'debugApiStatus' => session()->get($this->debugApiStatusSessionKey($id)),
        ]);
    }

    public function debugFetch(int $id, InboundShipmentSyncService $service)
    {
        $discrepancy = $this->loadDiscrepancy($id);
        $shipment = $discrepancy->shipment;

        if ($shipment === null) {
            return back()->with('status', 'No shipment record is attached to this discrepancy.');
        }

        $regionCode = strtoupper(trim((string) ($shipment->region_code ?? '')));
        $marketplaceId = trim((string) ($shipment->marketplace_id ?? ''));
        $shipmentId = trim((string) ($shipment->shipment_id ?? ''));

        try {
            $debugApiPayload = $service->fetchDebugShipmentPayloads($regionCode, $marketplaceId, $shipmentId);
            session()->put($this->debugApiPayloadSessionKey($id), $debugApiPayload);
            session()->put($this->debugApiStatusSessionKey($id), 'Live inbound API payload fetched successfully.');

            return redirect()->route('inbound.discrepancies.show', $id);
        } catch (\Throwable $e) {
            session()->put($this->debugApiPayloadSessionKey($id), [
                'error' => $e->getMessage(),
                'shipment_id' => $shipmentId,
                'marketplace_id' => $marketplaceId,
                'region_code' => $regionCode,
                'fetched_at_utc' => now()->utc()->toIso8601String(),
            ]);
            session()->put($this->debugApiStatusSessionKey($id), 'Live inbound API fetch failed.');

            return redirect()->route('inbound.discrepancies.show', $id);
        }
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

    private function loadDiscrepancy(int $id): InboundDiscrepancy
    {
        return InboundDiscrepancy::query()
            ->with([
                'shipment:id,shipment_id,region_code,marketplace_id,carrier_name,pro_tracking_number,shipment_created_at,shipment_closed_at,api_source_version,api_shipment_payload,api_items_payload',
                'shipment.cartons:id,shipment_id,carton_id,sku,fnsku,units_per_carton,carton_count,expected_units',
                'claimCases.evidences',
                'slaTransitions',
            ])
            ->findOrFail($id);
    }

    private function debugApiPayloadSessionKey(int $id): string
    {
        return 'inbound_discrepancy_debug_payload.' . $id;
    }

    private function debugApiStatusSessionKey(int $id): string
    {
        return 'inbound_discrepancy_debug_status.' . $id;
    }
}
