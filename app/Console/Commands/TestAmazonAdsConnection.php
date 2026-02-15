<?php

namespace App\Console\Commands;

use App\Services\AmazonAdsSpendSyncService;
use Illuminate\Console\Command;

class TestAmazonAdsConnection extends Command
{
    protected $signature = 'ads:test-connection';
    protected $description = 'Test Amazon Ads API token and profile access.';

    public function handle(AmazonAdsSpendSyncService $service): int
    {
        $result = $service->testConnection();
        if (!$result['ok']) {
            $this->error((string) $result['message']);
            return self::FAILURE;
        }

        $profiles = $result['profiles'] ?? [];
        $this->info((string) $result['message']);
        $this->line('Profiles found: ' . count($profiles));

        foreach (array_slice($profiles, 0, 10) as $profile) {
            $this->line(sprintf(
                '%s | country=%s | currency=%s | type=%s',
                (string) ($profile['profileId'] ?? 'n/a'),
                (string) ($profile['countryCode'] ?? 'n/a'),
                (string) ($profile['currencyCode'] ?? 'n/a'),
                (string) ($profile['type'] ?? 'n/a')
            ));
        }

        return self::SUCCESS;
    }
}

