<?php

namespace App\Console\Commands;

use App\Services\RegionConfigService;
use Illuminate\Console\Command;
use SellingPartnerApi\SellingPartnerApi;
use Throwable;

class CheckSellerWalletAccess extends Command
{
    protected $signature = 'wallet:check-access {--region= : Optional SP-API region (EU|NA|FE)}';
    protected $description = 'Check Seller Wallet API access for configured SP-API credentials.';

    public function handle(RegionConfigService $regions): int
    {
        $requestedRegion = $this->option('region') ? strtoupper(trim((string) $this->option('region'))) : null;
        $targets = $requestedRegion ? [$requestedRegion] : $regions->spApiRegions();

        $hasFailure = false;

        foreach ($targets as $region) {
            $config = $regions->spApiConfig((string) $region);
            $marketplaceId = $config['marketplace_ids'][0] ?? null;

            $this->line("Region: {$region}");
            $this->line('  client_id: ' . ($config['client_id'] !== '' ? 'set' : 'missing'));
            $this->line('  client_secret: ' . ($config['client_secret'] !== '' ? 'set' : 'missing'));
            $this->line('  refresh_token: ' . ($config['refresh_token'] !== '' ? 'set' : 'missing'));
            $this->line('  marketplace_id: ' . ($marketplaceId ?: 'missing'));

            if ($config['client_id'] === '' || $config['client_secret'] === '' || $config['refresh_token'] === '' || !$marketplaceId) {
                $hasFailure = true;
                $this->warn('  wallet_access_probe: skipped (missing config)');
                $this->newLine();
                continue;
            }

            try {
                $connector = SellingPartnerApi::seller(
                    clientId: $config['client_id'],
                    clientSecret: $config['client_secret'],
                    refreshToken: $config['refresh_token'],
                    endpoint: $regions->spApiEndpointEnum((string) $region),
                );

                $walletApi = $connector->sellerWalletV20240320();
                $accountsResponse = $walletApi->listAccounts((string) $marketplaceId);
                $accounts = $accountsResponse->json('payload') ?? [];

                if (!is_array($accounts) || count($accounts) === 0) {
                    $this->info('  wallet_list_accounts: success (no accounts returned)');
                    $this->newLine();
                    continue;
                }

                $firstAccount = $accounts[0] ?? [];
                $accountId = is_array($firstAccount) ? ($firstAccount['accountId'] ?? null) : null;

                if (!$accountId) {
                    $this->warn('  wallet_list_accounts: success but accountId not found in payload');
                    $this->newLine();
                    continue;
                }

                $balancesResponse = $walletApi->listAccountBalances((string) $accountId, (string) $marketplaceId);
                $balances = $balancesResponse->json('payload') ?? [];
                $balanceCount = is_array($balances) ? count($balances) : 0;

                $this->info('  wallet_access_probe: SUCCESS');
                $this->line("  account_id: {$accountId}");
                $this->line("  balances_returned: {$balanceCount}");
            } catch (Throwable $e) {
                $hasFailure = true;

                $status = null;
                $responseBody = null;

                if (method_exists($e, 'getResponse')) {
                    try {
                        $response = $e->getResponse();
                        if ($response) {
                            if (method_exists($response, 'status')) {
                                $status = $response->status();
                            }
                            if (method_exists($response, 'body')) {
                                $responseBody = (string) $response->body();
                            }
                        }
                    } catch (Throwable) {
                    }
                }

                $statusText = $status ? " (HTTP {$status})" : '';
                $this->error("  wallet_access_probe: FAILED{$statusText}");
                $this->line('  error: ' . $e->getMessage());

                if ($responseBody) {
                    $this->line('  response: ' . substr($responseBody, 0, 400));
                }
            }

            $this->newLine();
        }

        return $hasFailure ? self::FAILURE : self::SUCCESS;
    }
}

