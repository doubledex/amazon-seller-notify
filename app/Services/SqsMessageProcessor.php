<?php

namespace App\Services;

use App\Models\SqsMessage;
use Aws\Sqs\SqsClient;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SqsMessageProcessor
{
    private SqsClient $sqsClient;
    private string $queueUrl;

    public function __construct()
    {
        $this->sqsClient = new SqsClient([
            'region' => config('services.sqs.region'),
            'version' => 'latest',
            'credentials' => [
                'key' => config('services.sqs.key'),
                'secret' => config('services.sqs.secret'),
            ],
        ]);

        $this->queueUrl = (string) config('services.sqs.queue_url');
    }

    public function processMessages(): void
    {
        try {
            $result = $this->sqsClient->receiveMessage([
                'QueueUrl' => $this->queueUrl,
                'MaxNumberOfMessages' => 10,
                'WaitTimeSeconds' => 20,
            ]);

            if (!isset($result['Messages'])) {
                Log::info('No messages received from SQS queue.');
                return;
            }

            foreach ($result['Messages'] as $message) {
                $messageId = $message['MessageId'];
                $receiptHandle = $message['ReceiptHandle'];
                $body = $message['Body'];

                $existingMessage = SqsMessage::where('message_id', $messageId)->first();
                if ($existingMessage) {
                    $this->sqsClient->deleteMessage([
                        'QueueUrl' => $this->queueUrl,
                        'ReceiptHandle' => $receiptHandle,
                    ]);
                    Log::info("Duplicate message found and deleted: {$messageId}");
                    continue;
                }

                try {
                    Log::info("Attempting to store message in database: {$messageId}");
                    SqsMessage::create([
                        'message_id' => $messageId,
                        'body' => $body,
                        'receipt_handle' => $receiptHandle,
                        'processed' => true,
                    ]);
                    Log::info("Message stored in database: {$messageId}");

                    $this->handleMessage($messageId, $body);

                    $this->sqsClient->deleteMessage([
                        'QueueUrl' => $this->queueUrl,
                        'ReceiptHandle' => $receiptHandle,
                    ]);
                } catch (\Exception $e) {
                    Log::error("Error processing or storing message: {$e->getMessage()}");
                    Log::error("Error details: {$e->getTraceAsString()}");
                }
            }
        } catch (\Exception $e) {
            Log::error("Error receiving messages from SQS: {$e->getMessage()}");
        }
    }

    private function handleMessage(string $messageId, string $body): void
    {
        $data = json_decode($body, true);
        if ($data === null) {
            Log::warning("Message body is not valid JSON: {$body}");
            return;
        }

        if ((isset($data['NotificationType']) || isset($data['notificationType']))
            && (isset($data['EventTime']) || isset($data['eventTime']))) {
            $notificationType = $data['NotificationType'] ?? $data['notificationType'];
            $eventTime = $data['EventTime'] ?? $data['eventTime'];

            DB::table('sqs_messages')
                ->where('message_id', $messageId)
                ->update([
                    'NotificationType' => $notificationType,
                    'EventTime' => $eventTime,
                ]);

            Log::info("Updated message ID: {$messageId}");
        }
    }
}
