<?php

namespace App\Http\Controllers;

use App\Jobs\SyncInboundShipmentsJob;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class AmazonApiController extends Controller
{
    public function syncInbound(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'days' => ['nullable', 'integer', 'min:1', 'max:365'],
            'region' => ['nullable', 'string', 'in:EU,NA,FE'],
            'marketplace_id' => ['nullable', 'string', 'max:32'],
            'run_detection' => ['nullable', 'boolean'],
            'debug' => ['nullable', 'boolean'],
        ]);

        SyncInboundShipmentsJob::dispatch(
            days: (int) ($validated['days'] ?? 120),
            region: isset($validated['region']) ? (string) $validated['region'] : null,
            marketplaceId: isset($validated['marketplace_id']) ? (string) $validated['marketplace_id'] : null,
            runDetection: (bool) ($validated['run_detection'] ?? true),
            debug: (bool) ($validated['debug'] ?? false),
        )->onQueue('inbound');

        return back()->with(
            'status',
            'Inbound sync queued. It will run in the background and write results to the log.'
        );
    }
}
