<?php

namespace App\Console\Commands;

use App\Services\SqsMessageProcessor;
use Illuminate\Console\Command;

class ProcessSqsMessages extends Command
{
    protected $signature = 'sqs:process {--detail : Print per-message details}';
    protected $description = 'Process messages from the SQS queue.';

    public function handle()
    {
        $start = microtime(true);
        $detail = (bool) $this->option('detail');

        $stats = app(SqsMessageProcessor::class)->processMessages(
            $detail ? fn (string $line) => $this->line($line) : null
        );

        $durationMs = (int) round((microtime(true) - $start) * 1000);
        $before = $stats['queue_before'] ?? null;
        $after = $stats['queue_after'] ?? null;
        $beforeText = is_array($before)
            ? "before(v={$before['visible']},inflight={$before['in_flight']},delayed={$before['delayed']})"
            : 'before(n/a)';
        $afterText = is_array($after)
            ? "after(v={$after['visible']},inflight={$after['in_flight']},delayed={$after['delayed']})"
            : 'after(n/a)';
        $queueText = " | queue={$beforeText}->{$afterText}";

        if (($stats['no_messages'] ?? false) === true) {
            $this->info("SQS messages processed in {$durationMs}ms. No messages received.{$queueText}");
            return self::SUCCESS;
        }

        $this->info(
            'SQS messages processed in ' . $durationMs . 'ms'
            . ' | received=' . (int) ($stats['received'] ?? 0)
            . ' stored=' . (int) ($stats['stored'] ?? 0)
            . ' updated=' . (int) ($stats['updated'] ?? 0)
            . ' duplicate_deleted=' . (int) ($stats['duplicates_deleted'] ?? 0)
            . ' deleted=' . (int) ($stats['deleted'] ?? 0)
            . ' invalid_json=' . (int) ($stats['invalid_json'] ?? 0)
            . ' errors=' . (int) ($stats['errors'] ?? 0)
            . $queueText
        );

        return self::SUCCESS;
    }
}
