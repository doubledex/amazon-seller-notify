<?php

namespace App\Console\Commands;

use App\Services\AmazonAdsSpendSyncService;
use Illuminate\Console\Command;

class TestAmazonAdsConnection extends Command
{
    protected $signature = 'ads:test-connection {--limit=10 : Number of profiles to print (0 = all)}';
    protected $description = 'Test Amazon Ads API token and profile access.';

    public function handle(AmazonAdsSpendSyncService $service): int
    {
        $result = $service->testConnection();
        if (!$result['ok']) {
            $this->error((string) $result['message']);
            return self::FAILURE;
        }

        $profiles = $result['profiles'] ?? [];
        $limit = max(0, (int) $this->option('limit'));
        $rows = $limit === 0 ? $profiles : array_slice($profiles, 0, $limit);
        $this->info((string) $result['message']);
        $this->line('Profiles found: ' . count($profiles));

        foreach ($rows as $profile) {
            $this->line(sprintf(
                '%s | api_region=%s | country=%s | currency=%s | type=%s',
                (string) ($profile['profileId'] ?? 'n/a'),
                (string) ($profile['_ads_api_region'] ?? 'n/a'),
                (string) ($profile['countryCode'] ?? 'n/a'),
                (string) ($profile['currencyCode'] ?? 'n/a'),
                (string) ($profile['type'] ?? 'n/a')
            ));
        }

        return self::SUCCESS;
    }
}
