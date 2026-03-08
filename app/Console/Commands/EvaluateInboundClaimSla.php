<?php

namespace App\Console\Commands;

use App\Services\Amazon\Inbound\InboundClaimSlaService;
use Illuminate\Console\Command;

class EvaluateInboundClaimSla extends Command
{
    protected $signature = 'claims:inbound-evaluate {--limit= : Limit evaluated discrepancies}';

    protected $description = 'Evaluate inbound claim SLA windows, priorities, escalation queueing, and notifications.';

    public function handle(InboundClaimSlaService $service): int
    {
        $limit = $this->option('limit');
        $result = $service->evaluate($limit !== null ? (int) $limit : null);

        $this->info('Inbound claim SLA evaluation complete.');
        $this->line('Evaluated: ' . $result['evaluated']);
        $this->line('Queued high-priority jobs: ' . $result['queued']);
        $this->line('Near-expiry notifications: ' . $result['near_expiry_notified']);
        $this->line('Missed-SLA notifications: ' . $result['missed_notified']);
        $this->line('SLA transitions logged: ' . $result['transitions_logged']);

        return self::SUCCESS;
    }
}
