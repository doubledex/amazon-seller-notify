<?php

namespace App\Services\Amazon\Inbound;

use App\Jobs\ProcessInboundClaimHighPriorityJob;
use App\Models\InboundClaimSlaTransition;
use App\Models\InboundDiscrepancy;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

class InboundClaimSlaService
{
    public function evaluate(?int $limit = null): array
    {
        $query = InboundDiscrepancy::query()
            ->with('shipment:id,shipment_id,marketplace_id')
            ->where('status', 'open')
            ->orderBy('challenge_deadline_at');

        if ($limit !== null && $limit > 0) {
            $query->limit($limit);
        }

        $rows = $query->get();

        $summary = [
            'evaluated' => $rows->count(),
            'queued' => 0,
            'near_expiry_notified' => 0,
            'missed_notified' => 0,
            'transitions_logged' => 0,
        ];

        foreach ($rows as $discrepancy) {
            $evaluation = $this->evaluateDiscrepancy($discrepancy);

            if ($evaluation['queued']) {
                $summary['queued']++;
            }
            if ($evaluation['near_expiry_notified']) {
                $summary['near_expiry_notified']++;
            }
            if ($evaluation['missed_notified']) {
                $summary['missed_notified']++;
            }
            $summary['transitions_logged'] += $evaluation['transitions_logged'];
        }

        return $summary;
    }

    private function evaluateDiscrepancy(InboundDiscrepancy $discrepancy): array
    {
        $marketplaceId = (string) ($discrepancy->shipment?->marketplace_id ?? 'default');
        $program = $this->programForMarketplace($marketplaceId);
        $deadline = $this->resolveDeadline($discrepancy, $marketplaceId, $program);

        $hoursRemaining = $deadline === null
            ? null
            : now()->diffInHours($deadline, false);

        $tier = $this->priorityTierFor($hoursRemaining);

        $dirty = false;
        if ($deadline?->toDateTimeString() !== $discrepancy->challenge_deadline_at?->toDateTimeString()) {
            $discrepancy->challenge_deadline_at = $deadline;
            $dirty = true;
        }

        if ($discrepancy->severity !== $tier) {
            $this->recordTransition($discrepancy, $discrepancy->severity, $tier, [
                'hours_remaining' => $hoursRemaining,
                'deadline_at' => $deadline?->toIso8601String(),
                'marketplace_id' => $marketplaceId,
                'program' => $program,
            ]);
            $discrepancy->severity = $tier;
            $dirty = true;
        }

        if ($dirty) {
            $discrepancy->save();
        }

        $queued = false;
        if (in_array($tier, $this->highPriorityTiers(), true) && $this->isNewTransition($discrepancy->id, $tier)) {
            ProcessInboundClaimHighPriorityJob::dispatch($discrepancy->id, $tier, $hoursRemaining);
            $queued = true;
        }

        $nearExpiryNotified = false;
        $missedNotified = false;
        if ($hoursRemaining !== null && $hoursRemaining <= $this->nearExpiryHours() && $hoursRemaining >= 0) {
            $nearExpiryNotified = $this->notifyOnce($discrepancy, 'near_expiry', [
                'hours_remaining' => $hoursRemaining,
                'tier' => $tier,
            ]);
        }

        if ($hoursRemaining !== null && $hoursRemaining < 0) {
            $missedNotified = $this->notifyOnce($discrepancy, 'missed', [
                'hours_overdue' => abs($hoursRemaining),
                'tier' => $tier,
            ]);
        }

        return [
            'queued' => $queued,
            'near_expiry_notified' => $nearExpiryNotified,
            'missed_notified' => $missedNotified,
            'transitions_logged' => ($dirty ? 1 : 0) + ($nearExpiryNotified ? 1 : 0) + ($missedNotified ? 1 : 0),
        ];
    }

    private function resolveDeadline(InboundDiscrepancy $discrepancy, string $marketplaceId, string $program): ?Carbon
    {
        $baseDate = $discrepancy->shipment?->shipment_closed_at
            ?? $discrepancy->discrepancy_detected_at
            ?? $discrepancy->created_at;

        if ($baseDate === null) {
            return null;
        }

        return Carbon::parse($baseDate)->addDays($this->claimWindowDays($marketplaceId, $program));
    }

    private function claimWindowDays(string $marketplaceId, string $program): int
    {
        $default = (int) config('amazon_inbound_claims.defaults.claim_window_days', 30);

        return (int) (
            config("amazon_inbound_claims.policies.{$marketplaceId}.{$program}.claim_window_days")
            ?? config("amazon_inbound_claims.policies.{$marketplaceId}.default.claim_window_days")
            ?? config("amazon_inbound_claims.policies.default.{$program}.claim_window_days")
            ?? config('amazon_inbound_claims.policies.default.default.claim_window_days')
            ?? $default
        );
    }

    private function priorityTierFor(?int $hoursRemaining): string
    {
        if ($hoursRemaining === null) {
            return 'low';
        }

        $urgent = (int) config('amazon_inbound_claims.defaults.priority_thresholds.urgent_hours', 24);
        $critical = (int) config('amazon_inbound_claims.defaults.priority_thresholds.critical_hours', 48);

        if ($hoursRemaining < 0) {
            return 'missed';
        }

        if ($hoursRemaining < $urgent) {
            return 'urgent';
        }

        if ($hoursRemaining < $critical) {
            return 'critical';
        }

        return 'normal';
    }

    private function programForMarketplace(string $marketplaceId): string
    {
        return (string) (
            config("amazon_inbound_claims.marketplace_program_overrides.{$marketplaceId}")
            ?? config('amazon_inbound_claims.defaults.program', 'default')
        );
    }

    private function highPriorityTiers(): array
    {
        return (array) config('amazon_inbound_claims.defaults.high_priority_tiers', ['critical', 'urgent', 'missed']);
    }

    private function nearExpiryHours(): int
    {
        return (int) config('amazon_inbound_claims.defaults.notification.near_expiry_hours', 48);
    }

    private function notifyOnce(InboundDiscrepancy $discrepancy, string $state, array $context): bool
    {
        if (!$this->isNewTransition($discrepancy->id, $state)) {
            return false;
        }

        $this->recordTransition($discrepancy, null, $state, $context);
        $this->emitNotifications($discrepancy, $state, $context);

        return true;
    }

    private function emitNotifications(InboundDiscrepancy $discrepancy, string $state, array $context): void
    {
        $subject = sprintf(
            '[Inbound Claim SLA] %s: discrepancy #%d (shipment %s)',
            strtoupper($state),
            $discrepancy->id,
            $discrepancy->shipment_id
        );

        $body = [
            $subject,
            'SKU: ' . $discrepancy->sku,
            'FNSKU: ' . $discrepancy->fnsku,
            'Deadline: ' . ($discrepancy->challenge_deadline_at?->toDateTimeString() ?? 'n/a'),
            'Context: ' . json_encode($context),
        ];

        foreach ((array) config('amazon_inbound_claims.defaults.notification.email_to', []) as $email) {
            Mail::raw(implode("\n", $body), static function ($message) use ($email, $subject): void {
                $message->to($email)->subject($subject);
            });
        }

        $webhook = (string) config('amazon_inbound_claims.defaults.notification.slack_webhook_url', '');
        if ($webhook !== '') {
            Http::timeout(5)->post($webhook, ['text' => implode("\n", $body)]);
        }
    }

    private function isNewTransition(int $discrepancyId, string $toState): bool
    {
        return !InboundClaimSlaTransition::query()
            ->where('discrepancy_id', $discrepancyId)
            ->where('to_state', $toState)
            ->exists();
    }

    private function recordTransition(InboundDiscrepancy $discrepancy, ?string $fromState, string $toState, array $metadata): void
    {
        InboundClaimSlaTransition::query()->create([
            'discrepancy_id' => $discrepancy->id,
            'from_state' => $fromState,
            'to_state' => $toState,
            'metadata' => $metadata,
            'transitioned_at' => now(),
        ]);
    }
}
