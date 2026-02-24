<?php

namespace App\Console\Commands;

use App\Services\ReportJobOrchestrator;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class QueueReportJobs extends Command
{
    protected $signature = 'reports:queue
        {--provider=sp_api_seller}
        {--processor= : us_fc_inventory|marketplace_listings}
        {--region=NA}
        {--marketplace=* : One or more marketplace IDs}
        {--report-type= : SP-API report type}
        {--report-option=* : report option key=value}
        {--start-date= : Start date (YYYY-MM-DD)}
        {--end-date= : End date (YYYY-MM-DD)}
        {--external-report-id= : Existing upstream report ID}
        {--external-document-id= : Existing upstream report document ID}
        {--poll-after-seconds=0}';

    protected $description = 'Queue normalized report jobs for provider polling/processing.';

    public function handle(ReportJobOrchestrator $orchestrator): int
    {
        $provider = strtolower(trim((string) $this->option('provider')));
        if ($provider !== ReportJobOrchestrator::PROVIDER_SP_API_SELLER) {
            $this->error("Unsupported provider '{$provider}'.");
            return self::FAILURE;
        }

        $processor = trim((string) ($this->option('processor') ?? ''));
        $reportType = strtoupper(trim((string) ($this->option('report-type') ?? '')));
        if ($reportType === '') {
            $this->error('Missing --report-type.');
            return self::FAILURE;
        }

        $region = strtoupper(trim((string) ($this->option('region') ?? 'NA')));
        $marketplaces = array_values(array_filter(array_map('strval', (array) $this->option('marketplace'))));
        $reportOptions = $this->parseReportOptions((array) $this->option('report-option'));
        $startDate = $this->parseDateOption((string) ($this->option('start-date') ?? ''), true);
        $endDate = $this->parseDateOption((string) ($this->option('end-date') ?? ''), false);

        $result = $orchestrator->queueSpApiSellerJobs(
            $reportType,
            $marketplaces,
            $region,
            $reportOptions,
            $processor !== '' ? $processor : null,
            $startDate,
            $endDate,
            trim((string) ($this->option('external-report-id') ?? '')) ?: null,
            trim((string) ($this->option('external-document-id') ?? '')) ?: null,
            null,
            max(0, (int) $this->option('poll-after-seconds'))
        );

        $this->info('Report jobs queued.');
        $this->line('Created: ' . (int) ($result['created'] ?? 0));

        foreach (($result['jobs'] ?? []) as $job) {
            $this->line(sprintf(
                ' - job=%d marketplace=%s report_type=%s status=%s',
                (int) $job->id,
                (string) ($job->marketplace_id ?? 'n/a'),
                (string) $job->report_type,
                (string) $job->status
            ));
        }

        return self::SUCCESS;
    }

    private function parseReportOptions(array $options): ?array
    {
        $parsed = [];
        foreach ($options as $item) {
            $item = trim((string) $item);
            if ($item === '' || !str_contains($item, '=')) {
                continue;
            }
            [$k, $v] = explode('=', $item, 2);
            $k = trim($k);
            if ($k === '') {
                continue;
            }
            $parsed[$k] = trim($v);
        }

        return !empty($parsed) ? $parsed : null;
    }

    private function parseDateOption(string $value, bool $start): ?\DateTimeInterface
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        $tz = config('app.timezone', 'UTC');
        try {
            $date = Carbon::parse($value, $tz);
            return $start ? $date->startOfDay() : $date->endOfDay();
        } catch (\Throwable) {
            return null;
        }
    }
}
