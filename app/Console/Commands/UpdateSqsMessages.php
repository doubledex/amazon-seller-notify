<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;


class UpdateSqsMessages extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:update-sqs-messages';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        //
        $messages = DB::table('sqs_messages')
            ->select('id', 'body')
            ->where('body', 'like', '%"NotificationType"%')
            ->orwhere('body', 'like', '%"notificationType"%')
            ->get();

        foreach ($messages as $message) {
            $body = json_decode($message->body, true);

            if (
                (isset($body['NotificationType']) || isset($body['notificationType']))
                && (isset($body['EventTime']) || isset($body['eventTime']))
            )
            {
                $notificationType = $body['NotificationType'] ?? $body['notificationType'];
                $eventTime = $body['EventTime'] ?? $body['eventTime'];

                DB::table('sqs_messages')
                    ->where('id', $message->id)
                    ->update([
                        'NotificationType' => $notificationType,
                        'EventTime' => $eventTime,
                    ]);

                $this->info("Updated message ID: {$message->id}");
            }
        }

        $this->info('Update completed.');
    }
}
