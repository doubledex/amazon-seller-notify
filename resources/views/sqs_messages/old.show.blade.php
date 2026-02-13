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
    
</table>
<table> {{-- Notificatiion Body --}}
    <thead>
        <tr>
            <th>Notificatiion Body</th>
        </tr>
    </thead>
    <tr>
        <td>notificationVersion</td>
        @if (isset(json_decode($message->body)->notificationVersion) || isset(json_decode($message->body)->NotificationVersion))
            <td>{{ json_decode($message->body)->notificationVersion ?? json_decode($message->body)->NotificationVersion}}</td>
        @endif
    </tr>
    <tr>
        <td>notificationType</td>
        @if (isset(json_decode($message->body)->notificationType) || isset(json_decode($message->body)->NotificationType))
            <td>{{ json_decode($message->body)->notificationType ?? json_decode($message->body)->NotificationType}}</td>
        @endif
    </tr>
    <tr>
        <td>payloadVersion</td>
        @if (isset(json_decode($message->body)->payloadVersion) || isset(json_decode($message->body)->PayloadVersion))
            <td>{{ json_decode($message->body)->payloadVersion ?? json_decode($message->body)->PayloadVersion}}</td>
        @endif
    </tr>
    <tr>
        <td>eventTime</td>
        @if (isset(json_decode($message->body)->eventTime) || isset(json_decode($message->body)->EventTime))
            <td>{{ json_decode($message->body)->eventTime ?? json_decode($message->body)->EventTime}}</td>
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
        @if (isset(json_decode($message->body)->notificationMetadata->applicationId) || isset(json_decode($message->body)->NotificationMetadata->ApplicationId))
            <td>{{ json_decode($message->body)->notificationMetadata->applicationId ?? json_decode($message->body)->NotificationMetadata->ApplicationId}}</td>
        @endif
    </tr>
    <tr>
        <td>subscriptionId</td>
        @if (isset(json_decode($message->body)->notificationMetadata->subscriptionId) || isset(json_decode($message->body)->NotificationMetadata->SubscriptionId))
            <td>{{ json_decode($message->body)->notificationMetadata->subscriptionId ?? json_decode($message->body)->NotificationMetadata->SubscriptionId}}</td>
        @endif
    </tr>
    <tr>
        <td>publishTime</td>
        @if (isset(json_decode($message->body)->notificationMetadata->publishTime) || isset(json_decode($message->body)->NotificationMetadata->PublishTime))
            <td>{{ json_decode($message->body)->notificationMetadata->publishTime ?? json_decode($message->body)->NotificationMetadata->PublishTime}}</td>
        @endif
    </tr>
    <tr>
        <td>notificationId</td>
        @if (isset(json_decode($message->body)->notificationMetadata->notificationId) || isset(json_decode($message->body)->NotificationMetadata->NotificationId))
            <td>{{ json_decode($message->body)->notificationMetadata->notificationId ?? json_decode($message->body)->NotificationMetadata->NotificationId}}</td>
        @endif
    </tr>
</table>

@php
    //normalise the variable name for notificationType so i can compare it in the if statement
    $notificationType = null;
    if (json_decode($message->body)->notificationType) {
        $notificationType = json_decode($message->body)->notificationType;
     } else { (json_decode($message->body)->NotificationType)
        $notificationType = json_decode($message->body)->NotificationType;
     }
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
    <td>{{json_decode($message->body)->payload->reportProcessingFinishedNotification->sellerId }}</td>
    </tr>
    <tr>
    <td>accountId:</td>
    <td>{{json_decode($message->body)->payload->reportProcessingFinishedNotification->accountId }}</td>
    </tr>
    <tr>
    <td>reportId:</td>
    <td>{{ json_decode($message->body)->payload->reportProcessingFinishedNotification->reportId }}</td>
    </tr>
    <tr>
    <td>reportType:</td>
    <td>{{ json_decode($message->body)->payload->reportProcessingFinishedNotification->reportType }}</td>
    </tr>
    <tr>
    <td>processingStatus:</td>
    <td>{{ json_decode($message->body)->payload->reportProcessingFinishedNotification->processingStatus }}</td>
    </tr>
    <tr>
    <td>reportDocumentId:</td>
    <td>{{ json_decode($message->body)->payload->reportProcessingFinishedNotification->reportDocumentId }}</td>
    </tr>
    </table>
@endif


@if (!empty(json_decode($message->body)->Payload->FulfillmentInventoryByMarketplace))

@php
    $fulfillmentInventory = json_decode($message->body)->Payload->FulfillmentInventoryByMarketplace ?? [];
    // Sort the array by MarketplaceId
    usort($fulfillmentInventory, function ($a, $b) {
        return strcmp($a->MarketplaceId, $b->MarketplaceId);
    });
@endphp


<h2>Fulfillment Inventory By Marketplace</h2>

<p><strong>ASIN:</strong> {{ json_decode($message->body)->Payload->ASIN ?? "n/a" }}</p>
<p><strong>SKU:</strong> {{ json_decode($message->body)->Payload->SKU ?? "n/a" }}</p>
    <table border="1" cellpadding="5" cellspacing="0"> {{-- Fulfillment Inventory By Marketplace --}}
        <thead>
            <tr>
                <th>Marketplace ID</th>
                <th>MktPlc</th>
                <th>Inbound Working</th>
                <th>Inbound Shipped</th>
                <th>Inbound Receiving</th>
                <th>Fulfillable</th> <!-- Replace with actual field names -->
                <th>Unfulfillable</th> <!-- Replace with actual field names -->
                <th>Researching</th> <!-- Replace with actual field names -->
                <th>FutureSupplyBuyable</th> <!-- Replace with actual field names -->
                <th>PendingCustomerOrderInTransit</th> <!-- Replace with actual field names -->
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
                    <td>{{ $inventory->FulfillmentInventory->Fulfillable ?? 'n/a' }}</td> <!-- Replace with actual field names -->
                    <td>{{ $inventory->FulfillmentInventory->Unfulfillable ?? 'n/a' }}</td> <!-- Replace with actual field names -->
                    <td>{{ $inventory->FulfillmentInventory->Researching ?? 'n/a' }}</td> <!-- Replace with actual field names -->
                    <td>{{ $inventory->FulfillmentInventory->FutureSupplyBuyable ?? 'n/a' }}</td> <!-- Replace with actual field names -->
                    <td>{{ $inventory->FulfillmentInventory->PendingCustomerOrderInTransit ?? 'n/a' }}</td> <!-- Replace with actual field names -->
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
