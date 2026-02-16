@php
    $messageBody = json_decode($message->body);
@endphp

<table> {{-- Local Message Queue --}}
    <thead>
        <tr>
            <th>Local Message Queue</th>
        </tr>
    </thead>
    <tr>
        <td>Message ID:</td>
        <td>{{ $message->id }}</td>
    </tr>
    <tr>
        <td>Created At:</td>
        <td>{{ $message->created_at }}</td>
    </tr>
    <tr>
        <td>Message ID:</td>
        <td>{{ $message->message_id }}</td> 
    </tr>
    <tr>
        <td>Flagged:</td>
        <td>{{ $message->flagged }}</td>
    </tr>
</table>

<table> {{-- Notification Body --}}
    <thead>
        <tr>
            <th>Notification Body</th>
        </tr>
    </thead>
    <tr>
        <td>notificationVersion</td>
        @if (isset($messageBody->notificationVersion) || isset($messageBody->NotificationVersion))
            <td>{{ $messageBody->notificationVersion ?? $messageBody->NotificationVersion }}</td>
        @endif
    </tr>
    <tr>
        <td>notificationType</td>
        @if (isset($messageBody->notificationType) || isset($messageBody->NotificationType))
            <td>{{ $messageBody->notificationType ?? $messageBody->NotificationType }}</td>
        @endif
    </tr>
    <tr>
        <td>payloadVersion</td>
        @if (isset($messageBody->payloadVersion) || isset($messageBody->PayloadVersion))
            <td>{{ $messageBody->payloadVersion ?? $messageBody->PayloadVersion }}</td>
        @endif
    </tr>
    <tr>
        <td>eventTime</td>
        @if (isset($messageBody->eventTime) || isset($messageBody->EventTime))
            <td>{{ $messageBody->eventTime ?? $messageBody->EventTime }}</td>
        @endif
    </tr>
</table>

<table> {{-- Notification Metadata --}}
    <thead>
        <tr>
            <th>Notification Metadata</th>
        </tr>
    </thead>
    <tr>
        <td>applicationId</td>
        @if (isset($messageBody->notificationMetadata->applicationId) || isset($messageBody->NotificationMetadata->ApplicationId))
            <td>{{ $messageBody->notificationMetadata->applicationId ?? $messageBody->NotificationMetadata->ApplicationId }}</td>
        @endif
    </tr>
    <tr>
        <td>subscriptionId</td>
        @if (isset($messageBody->notificationMetadata->subscriptionId) || isset($messageBody->NotificationMetadata->SubscriptionId))
            <td>{{ $messageBody->notificationMetadata->subscriptionId ?? $messageBody->NotificationMetadata->SubscriptionId }}</td>
        @endif
    </tr>
    <tr>
        <td>publishTime</td>
        @if (isset($messageBody->notificationMetadata->publishTime) || isset($messageBody->NotificationMetadata->PublishTime))
            <td>{{ $messageBody->notificationMetadata->publishTime ?? $messageBody->NotificationMetadata->PublishTime }}</td>
        @endif
    </tr>
    <tr>
        <td>notificationId</td>
        @if (isset($messageBody->notificationMetadata->notificationId) || isset($messageBody->NotificationMetadata->NotificationId))
            <td>{{ $messageBody->notificationMetadata->notificationId ?? $messageBody->NotificationMetadata->NotificationId }}</td>
        @endif
    </tr>
</table>

@php
    // Normalize the variable name for notificationType so it can be compared in the if statement
    $notificationType = $messageBody->notificationType ?? $messageBody->NotificationType ?? null;
@endphp

@if ($notificationType == "REPORT_PROCESSING_FINISHED")
    <table> {{-- Report Processing Finished Notification --}}
        <thead>
            <tr>
                <th>Report Processing Finished Notification</th>
            </tr>
        </thead>
        <tr>
            <td>sellerId:</td>
            <td>{{ $messageBody->payload->reportProcessingFinishedNotification->sellerId ?? 'n/a' }}</td>
        </tr>
        <tr>
            <td>accountId:</td>
            <td>{{ $messageBody->payload->reportProcessingFinishedNotification->accountId ?? 'n/a' }}</td>
        </tr>
        <tr>
            <td>reportId:</td>
            <td>{{ $messageBody->payload->reportProcessingFinishedNotification->reportId ?? 'n/a' }}</td>
        </tr>
        <tr>
            <td>reportType:</td>
            <td>{{ $messageBody->payload->reportProcessingFinishedNotification->reportType ?? 'n/a' }}</td>
        </tr>
        <tr>
            <td>processingStatus:</td>
            <td>{{ $messageBody->payload->reportProcessingFinishedNotification->processingStatus ?? 'n/a' }}</td>
        </tr>
        <tr>
            <td>reportDocumentId:</td>
            <td>{{ $messageBody->payload->reportProcessingFinishedNotification->reportDocumentId ?? 'n/a' }}</td>
        </tr>
        @if(!empty($messageBody->payload->reportProcessingFinishedNotification->reportDocumentId) && !empty($messageBody->payload->reportProcessingFinishedNotification->reportType))
            <tr>
                <td>Download:</td>
                <td><a href="{{ route('sqs_messages.report_download', $message->id) }}">Download report document</a></td>
            </tr>
        @endif
    </table>
@endif

@if ($notificationType == "ANY_OFFER_CHANGED")
    <table> {{-- Any Offer Changed Notification --}}
        <thead>
            <tr>
                <th>Any Offer Changed Notification</th>
            </tr>
        </thead>
        <tr>
            <td>SellerId:</td>
            <td>{{ $messageBody->Payload->AnyOfferChangedNotification->SellerId ?? 'n/a' }}</td>
        </tr>
        <tr>
            <td>MarketplaceId:</td>
            <td>{{ $messageBody->Payload->AnyOfferChangedNotification->OfferChangeTrigger->MarketplaceId ?? 'n/a' }}</td>
        </tr>
        <tr>
            <td>ASIN:</td>
            <td>{{ $messageBody->Payload->AnyOfferChangedNotification->OfferChangeTrigger->ASIN ?? 'n/a' }}</td>
        </tr>
        <tr>
            <td>ItemCondition:</td>
            <td>{{ $messageBody->Payload->AnyOfferChangedNotification->OfferChangeTrigger->ItemCondition ?? 'n/a' }}</td>
        </tr>
        <tr>
            <td>TimeOfOfferChange:</td>
            <td>{{ $messageBody->Payload->AnyOfferChangedNotification->OfferChangeTrigger->TimeOfOfferChange ?? 'n/a' }}</td>
        </tr>
        <tr>
            <td>OfferChangeType:</td>
            <td>{{ $messageBody->Payload->AnyOfferChangedNotification->OfferChangeTrigger->OfferChangeType ?? 'n/a' }}</td>
        </tr>

    </table>

@endif

@if (!empty($messageBody->Payload->FulfillmentInventoryByMarketplace))
    @php
        $fulfillmentInventory = $messageBody->Payload->FulfillmentInventoryByMarketplace ?? [];
        // Sort the array by MarketplaceId
        usort($fulfillmentInventory, function ($a, $b) {
            return strcmp($a->MarketplaceId, $b->MarketplaceId);
        });
    @endphp

    <h2>Fulfillment Inventory By Marketplace</h2>

    <p><strong>ASIN:</strong> {{ $messageBody->Payload->ASIN ?? 'n/a' }}</p>
    <p><strong>SKU:</strong> {{ $messageBody->Payload->SKU ?? 'n/a' }}</p>
    
    <table border="1" cellpadding="5" cellspacing="0"> {{-- Fulfillment Inventory By Marketplace --}}
        <thead>
            <tr>
                <th>Marketplace ID</th>
                <th>MktPlc</th>
                <th>Inbound Working</th>
                <th>Inbound Shipped</th>
                <th>Inbound Receiving</th>
                <th>Fulfillable</th>
                <th>Unfulfillable</th>
                <th>Researching</th>
                <th>FutureSupplyBuyable</th>
                <th>PendingCustomerOrderInTransit</th>
                <th>WarehouseProcessing</th>
                <th>WarehouseTransfer</th>
                <th>PendingCustomerOrder</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($fulfillmentInventory as $inventory)
                <tr>
                    <td>{{ $inventory->MarketplaceId ?? 'n/a' }}</td>
                    <td>{{ ($marketplaceMap ?? [])[$inventory->MarketplaceId] ?? 'n/a' }}</td>
                    <td>{{ $inventory->FulfillmentInventory->InboundQuantityBreakdown->Working ?? 'n/a' }}</td>
                    <td>{{ $inventory->FulfillmentInventory->InboundQuantityBreakdown->Shipped ?? 'n/a' }}</td>
                    <td>{{ $inventory->FulfillmentInventory->InboundQuantityBreakdown->Receiving ?? 'n/a' }}</td>
                    <td>{{ $inventory->FulfillmentInventory->Fulfillable ?? 'n/a' }}</td>
                    <td>{{ $inventory->FulfillmentInventory->Unfulfillable ?? 'n/a' }}</td>
                    <td>{{ $inventory->FulfillmentInventory->Researching ?? 'n/a' }}</td>
                    <td>{{ $inventory->FulfillmentInventory->FutureSupplyBuyable ?? 'n/a' }}</td>
                    <td>{{ $inventory->FulfillmentInventory->PendingCustomerOrderInTransit ?? 'n/a' }}</td>
                    <td>{{ $inventory->FulfillmentInventory->ReservedQuantityBreakdown->WarehouseProcessing ?? 'n/a' }}</td>
                    <td>{{ $inventory->FulfillmentInventory->ReservedQuantityBreakdown->WarehouseTransfer ?? 'n/a' }}</td>
                    <td>{{ $inventory->FulfillmentInventory->ReservedQuantityBreakdown->PendingCustomerOrder ?? 'n/a' }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
@endif

<p><strong>Body:</strong> {{ $message->body }}</p>

<a href="{{ route('sqs_messages.index') }}">Back to List</a>
