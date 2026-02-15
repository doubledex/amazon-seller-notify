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

    public function processMessages(?callable $detailLogger = null): array
    {
        $stats = [
            'received' => 0,
            'stored' => 0,
            'duplicates_deleted' => 0,
            'deleted' => 0,
            'updated' => 0,
            'invalid_json' => 0,
            'errors' => 0,
            'no_messages' => false,
            'queue_before' => null,
            'queue_after' => null,
        ];

        try {
            $stats['queue_before'] = $this->getQueueDepth();

            $result = $this->sqsClient->receiveMessage([
                'QueueUrl' => $this->queueUrl,
                'MaxNumberOfMessages' => 10,
                'WaitTimeSeconds' => 20,
            ]);

            if (!isset($result['Messages'])) {
                Log::info('No messages received from SQS queue.');
                $stats['no_messages'] = true;
                return $stats;
            }

            foreach ($result['Messages'] as $message) {
                $messageId = $message['MessageId'];
                $receiptHandle = $message['ReceiptHandle'];
                $body = $message['Body'];
                $stats['received']++;

                $existingMessage = SqsMessage::where('message_id', $messageId)->first();
                if ($existingMessage) {
                    $this->sqsClient->deleteMessage([
                        'QueueUrl' => $this->queueUrl,
                        'ReceiptHandle' => $receiptHandle,
                    ]);
                    Log::info("Duplicate message found and deleted: {$messageId}");
                    $stats['duplicates_deleted']++;
                    $stats['deleted']++;
                    if ($detailLogger) {
                        $detailLogger("duplicate_deleted {$messageId}");
                    }
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
                    $stats['stored']++;
                    if ($detailLogger) {
                        $detailLogger("stored {$messageId}");
                    }

                    $handled = $this->handleMessage($messageId, $body);
                    if ($handled['updated']) {
                        $stats['updated']++;
                    }
                    if ($handled['invalid_json']) {
                        $stats['invalid_json']++;
                    }

                    $this->sqsClient->deleteMessage([
                        'QueueUrl' => $this->queueUrl,
                        'ReceiptHandle' => $receiptHandle,
                    ]);
                    $stats['deleted']++;
                } catch (\Exception $e) {
                    Log::error("Error processing or storing message: {$e->getMessage()}");
                    Log::error("Error details: {$e->getTraceAsString()}");
                    $stats['errors']++;
                    if ($detailLogger) {
                        $detailLogger("error {$messageId}: {$e->getMessage()}");
                    }
                }
            }

            $stats['queue_after'] = $this->getQueueDepth();
        } catch (\Exception $e) {
            Log::error("Error receiving messages from SQS: {$e->getMessage()}");
            $stats['errors']++;
            if ($detailLogger) {
                $detailLogger("receive_error {$e->getMessage()}");
            }
        }

        return $stats;
    }

    private function getQueueDepth(): ?array
    {
        try {
            $result = $this->sqsClient->getQueueAttributes([
                'QueueUrl' => $this->queueUrl,
                'AttributeNames' => [
                    'ApproximateNumberOfMessages',
                    'ApproximateNumberOfMessagesNotVisible',
                    'ApproximateNumberOfMessagesDelayed',
                ],
            ]);

            $attrs = $result->get('Attributes') ?? [];
            return [
                'visible' => (int) ($attrs['ApproximateNumberOfMessages'] ?? 0),
                'in_flight' => (int) ($attrs['ApproximateNumberOfMessagesNotVisible'] ?? 0),
                'delayed' => (int) ($attrs['ApproximateNumberOfMessagesDelayed'] ?? 0),
            ];
        } catch (\Exception $e) {
            Log::warning("Unable to fetch SQS queue attributes: {$e->getMessage()}");
            return null;
        }
    }

    private function handleMessage(string $messageId, string $body): array
    {
        $result = ['updated' => false, 'invalid_json' => false];
        $data = json_decode($body, true);
        if ($data === null) {
            Log::warning("Message body is not valid JSON: {$body}");
            $result['invalid_json'] = true;
            return $result;
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
            $result['updated'] = true;
        }

        return $result;
    }
}
