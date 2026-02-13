<?php

namespace App\Console\Commands;

use App\Services\SqsMessageProcessor;
use Illuminate\Console\Command;

class ProcessSqsMessages extends Command
{
    protected $signature = 'sqs:process'; // The command name you'll use
    protected $description = 'Process messages from the SQS queue.';

    public function handle()
    {
        app(SqsMessageProcessor::class)->processMessages();

        $this->info('SQS messages processed.'); // Optional: Display a message
    }
}
