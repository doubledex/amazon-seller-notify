<?php

namespace App\Http\Controllers;

use App\Services\Amazon\OfficialSpApiService;
use App\Services\RegionConfigService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use SpApi\Api\notifications\v1\NotificationsApi;
use SpApi\Model\notifications\v1\CreateDestinationRequest;
use SpApi\Model\notifications\v1\CreateSubscriptionRequest;
use SpApi\Model\notifications\v1\DestinationResourceSpecification;
use SpApi\Model\notifications\v1\SqsResource;

class NotificationSubscriptions extends Controller
{
    private readonly NotificationsApi $notificationsApi;

    public function __construct(
        RegionConfigService $regionService,
        OfficialSpApiService $officialSpApiService
    )
    {
        $defaultRegion = $regionService->defaultSpApiRegion();
        $notificationsApi = $officialSpApiService->makeNotificationsV1Api($defaultRegion);
        if ($notificationsApi === null) {
            throw new \RuntimeException("Unable to create Notifications API client for region {$defaultRegion}.");
        }

        $this->notificationsApi = $notificationsApi;
    }

    public function index(Request $request)
    {
        $notificationTypes = [
            'ACCOUNT_STATUS_CHANGED', 'ANY_OFFER_CHANGED', 'B2B_ANY_OFFER_CHANGED', 'BRANDED_ITEM_CONTENT_CHANGE',
            'FBA_INVENTORY_AVAILABILITY_CHANGES', 'FBA_OUTBOUND_SHIPMENT_STATUS',
            'FEE_PROMOTION', 'FEED_PROCESSING_FINISHED', 'FULFILLMENT_ORDER_STATUS',
            'ITEM_PRODUCT_TYPE_CHANGE', 'LISTINGS_ITEM_STATUS_CHANGE', 'LISTINGS_ITEM_ISSUES_CHANGE',
            'LISTINGS_ITEM_MFN_QUANTITY_CHANGE', 'ORDER_CHANGE', 'PRICING_HEALTH', 'PRODUCT_TYPE_DEFINITIONS_CHANGE',
            'REPORT_PROCESSING_FINISHED'
        ];

        try {
            $responses = $this->fetchSubscriptions($this->notificationsApi, $notificationTypes, $request);
            $destinationsResponse = $this->notificationsApi->getDestinations();
            return view('notifications.index', [
                'responses' => $responses,
                'notifications' => $responses['notificationData']['payload'] ?? [],
                'destinations' => $this->modelToArray($destinationsResponse->getPayload() ?? []),
                'responseHeaders' => $responses['responseHeaders'],
                'notificationTypes' => $notificationTypes,
            ]);
        } catch (\Exception $e) {
            return $this->handleException($e, 'Error fetching notifications. Please try again later.');
        }
    }

    private function fetchSubscriptions($notificationsApi, $notificationTypes, $request)
    {
        $responses = [];
        $responseHeaders = [];
        foreach ($notificationTypes as $notificationType) {
            try {
                [$response, $statusCode, $headers] = $notificationsApi->getSubscriptionWithHttpInfo($notificationType);
                $responses[$notificationType] = $this->modelToArray($response->getPayload());
                $responseHeaders = $headers;

                if ($statusCode >= 400) {
                    throw new \Exception("Amazon SP-API Error (HTTP {$statusCode}) while fetching subscription.");
                }

                $nextToken = $response->getPayload() && method_exists($response->getPayload(), 'getNextToken')
                    ? $response->getPayload()->getNextToken()
                    : null;
                if ($nextToken) {
                    $request->session()->put('next_token', $nextToken);
                }

            } catch (\Exception $e) {
                $this->handleSubscriptionException($e, $notificationType);
            }
            $this->applyRateLimit($responseHeaders);
        }
        // echo json_encode($responses);
        // dd($responses);

        return ['responses' => $responses, 'responseHeaders' => $responseHeaders];

    }

    private function applyRateLimit($responseHeaders)
    {
        $rateLimitHeader = $responseHeaders['x-amzn-RateLimit-Limit'][0]
            ?? $responseHeaders['x-amzn-ratelimit-limit'][0]
            ?? null;
        $rateLimit = is_numeric($rateLimitHeader) ? (float) $rateLimitHeader : 1.0;
        $rateLimit = $rateLimit > 0 ? $rateLimit : 1.0;
        $delay = (int) ((1 / $rateLimit) * 1000000);
        usleep($delay);
    }

    private function handleSubscriptionException($e, $notificationType)
    {
        $code = $e->getCode();
        if ($code == 0 && $e->getPrevious()) {
            $code = $e->getPrevious()->getCode();
        }

        if ($code == 404) {
            Log::info("No subscription found for notification type: " . $notificationType);
        } elseif ($code == 429) {
            Log::error("Amazon SP-API Quota Exceeded: " . $e->getMessage());
            throw new \Exception('You have exceeded your request quota. Please try again later.');
        } else {
            Log::error("Exception: " . $e->getMessage());
            throw new \Exception('Error fetching notifications. Please try again later.');
        }
    }

    public function storeDestination(Request $request)
    {
        $request->validate([
            'sqsArn' => 'required|string',
            'name' => 'required|string',
        ]);

        try {
            $sqsResource = new SqsResource(['arn' => (string) $request->input('sqsArn')]);
            $resourceSpecification = new DestinationResourceSpecification(['sqs' => $sqsResource]);
            $destinationRequest = new CreateDestinationRequest([
                'resource_specification' => $resourceSpecification,
                'name' => (string) $request->input('name'),
            ]);

            $this->notificationsApi->createDestination($destinationRequest);

            return back()->with('success', 'Destination created successfully.');
        } catch (\Exception $e) {
            return $this->handleException($e, 'An error occurred while creating the destination.');
        }
    }

    public function deleteDestination(Request $request)
    {
        $request->validate([
            'destinationId' => 'required|string',
            
        ]);
        
        try {
            $destinationId = $request->input('destinationId');
            $this->notificationsApi->deleteDestination((string) $destinationId);

            return back()->with('success', 'Destination deleted successfully.');
        } catch (\Exception $e) {
            return $this->handleException($e, 'An error occurred while deleting the destination.');
        }
    }

    public function deleteSubscription(Request $request)
    {
        $request->validate([
            'subscriptionId' => 'required|string',
        ]);
        try {
            $subscriptionId = $request->input('subscriptionId');
            $notificationType = $request->input('notificationType');
            $this->notificationsApi->deleteSubscriptionById((string) $subscriptionId, (string) $notificationType);

            return back()->with('success', 'Subscription deleted successfully.');
        } catch (\Exception $e) {
            return $this->handleException($e, 'An error occurred while deleting the subscription.');
        }
    }

    public function createSubscription(Request $request)
    {
        $request->validate([
            'notificationType' => 'required|string',
            'destinationId' => 'required|string',
            'payloadVersion' => 'required|string',
        ]);

        try {
            $notificationType = $request->input('notificationType');
            $destinationId = $request->input('destinationId');
            $payloadVersion = $request->input('payloadVersion');

            $createSubscriptionRequest = new CreateSubscriptionRequest([
                'payload_version' => (string) $payloadVersion,
                'destination_id' => (string) $destinationId,
            ]);
            $this->notificationsApi->createSubscription((string) $notificationType, $createSubscriptionRequest);

            return back()->with('success', 'Subscription created successfully.');
        } catch (\Exception $e) {
            return $this->handleException($e, 'An error occurred while creating the subscription.');
        }
    }

    private function handleException(\Exception $e, $defaultMessage)
    {
        Log::error("Exception: " . $e->getMessage());
        return back()->with('error', $defaultMessage . ' ' . $e->getMessage());
    }

    private function modelToArray(mixed $value): array
    {
        if ($value === null) {
            return [];
        }

        $json = json_encode($value);
        if ($json === false) {
            return [];
        }

        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : [];
    }
}
