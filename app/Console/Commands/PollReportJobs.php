<?php

namespace App\Console\Commands;

use App\Services\ReportJobOrchestrator;
use Illuminate\Console\Command;

class PollReportJobs extends Command
{
    protected $signature = 'reports:poll
        {--provider=sp_api_seller}
        {--limit=100}
        {--processor= : Optional processor filter}
        {--region= : Optional region filter}';

    protected $description = 'Poll queued report jobs and process completed report documents.';

    public function handle(ReportJobOrchestrator $orchestrator): int
    {
        $provider = strtolower(trim((string) $this->option('provider')));
        if ($provider !== ReportJobOrchestrator::PROVIDER_SP_API_SELLER) {
            $this->error("Unsupported provider '{$provider}'.");
            return self::FAILURE;
        }

        $result = $orchestrator->pollSpApiSellerJobs(
            max(1, (int) $this->option('limit')),
            trim((string) ($this->option('processor') ?? '')) ?: null,
            trim((string) ($this->option('region') ?? '')) ?: null
        );

        $this->info('Report job polling complete.');
        $this->line('Checked: ' . (int) ($result['checked'] ?? 0));
        $this->line('Processed: ' . (int) ($result['processed'] ?? 0));
        $this->line('Failed: ' . (int) ($result['failed'] ?? 0));
        $this->line('Outstanding: ' . (int) ($result['outstanding'] ?? 0));

        return self::SUCCESS;
    }
}
