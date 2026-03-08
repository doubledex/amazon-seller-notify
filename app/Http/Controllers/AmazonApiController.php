<?php

namespace App\Http\Controllers;

use App\Services\Amazon\Inbound\InboundShipmentSyncService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class AmazonApiController extends Controller
{
    public function syncInbound(Request $request, InboundShipmentSyncService $service): RedirectResponse
    {
        $validated = $request->validate([
            'days' => ['nullable', 'integer', 'min:1', 'max:365'],
            'region' => ['nullable', 'string', 'in:EU,NA,FE'],
            'marketplace_id' => ['nullable', 'string', 'max:32'],
            'run_detection' => ['nullable', 'boolean'],
        ]);

        $result = $service->sync(
            days: (int) ($validated['days'] ?? 120),
            region: isset($validated['region']) ? (string) $validated['region'] : null,
            marketplaceId: isset($validated['marketplace_id']) ? (string) $validated['marketplace_id'] : null,
            runDetection: (bool) ($validated['run_detection'] ?? true),
        );

        return back()->with(
            'status',
            ($result['ok'] ?? false)
                ? 'Inbound sync completed. ' . (string) ($result['message'] ?? '')
                : 'Inbound sync completed with errors. ' . (string) ($result['message'] ?? '')
        );
    }
}
