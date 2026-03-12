@php
    $messageBody = json_decode($message->body);
    $messageBodyArray = json_decode((string) $message->body, true);
    $fullPayload = is_array($messageBodyArray) ? $messageBodyArray : null;
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
                <td>
                    <a href="{{ route('sqs_messages.report_download', ['id' => $message->id, 'format' => 'excel']) }}">Excel</a>
                    |
                    <a href="{{ route('sqs_messages.report_download', ['id' => $message->id, 'format' => 'csv']) }}">CSV</a>
                    |
                    <a href="{{ route('sqs_messages.report_download', ['id' => $message->id, 'format' => 'xml']) }}">XML</a>
                    |
                    <a href="{{ route('sqs_messages.report_download', ['id' => $message->id, 'format' => 'raw']) }}">Raw</a>
                </td>
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

<h2>Message Payload JSON</h2>
@if (is_array($fullPayload))
    <style>
        .payload-json-tree {
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace;
            font-size: 14px;
            line-height: 1.65;
            background: #111827;
            color: #f3f4f6;
            border: 1px solid #374151;
            border-radius: 8px;
            padding: 12px;
            margin-top: 8px;
            overflow-x: auto;
        }
        .payload-json-tree details > div {
            border-left: 1px solid #374151;
            margin-left: 6px;
        }
        .payload-json-key { color: #93c5fd; }
        .payload-json-string { color: #86efac; }
        .payload-json-number { color: #fca5a5; }
        .payload-json-boolean { color: #fcd34d; }
        .payload-json-null { color: #d1d5db; font-style: italic; }
    </style>

    <div id="payload-json-tree" class="payload-json-tree"></div>
    <script type="application/json" id="payload-json-data">@json($fullPayload, JSON_UNESCAPED_SLASHES)</script>

    <details>
        <summary>Raw Message Payload JSON</summary>
        <pre id="payload-raw-json">{{ json_encode($fullPayload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
    </details>

    <script>
        (function () {
            const dataScript = document.getElementById('payload-json-data');
            const container = document.getElementById('payload-json-tree');
            if (!dataScript || !container) {
                return;
            }

            function primitiveSpan(value) {
                const span = document.createElement('span');
                if (value === null) {
                    span.className = 'payload-json-null';
                    span.textContent = 'null';
                    return span;
                }

                if (typeof value === 'string') {
                    span.className = 'payload-json-string';
                    span.textContent = '"' + value + '"';
                    return span;
                }

                if (typeof value === 'number') {
                    span.className = 'payload-json-number';
                    span.textContent = String(value);
                    return span;
                }

                if (typeof value === 'boolean') {
                    span.className = 'payload-json-boolean';
                    span.textContent = value ? 'true' : 'false';
                    return span;
                }

                span.textContent = String(value);
                return span;
            }

            function renderNode(value, key) {
                if (value === null || typeof value !== 'object') {
                    const row = document.createElement('div');
                    if (key !== undefined) {
                        const keySpan = document.createElement('span');
                        keySpan.className = 'payload-json-key';
                        keySpan.textContent = '"' + key + '": ';
                        row.appendChild(keySpan);
                    }
                    row.appendChild(primitiveSpan(value));
                    return row;
                }

                const details = document.createElement('details');
                details.open = key === undefined;

                const summary = document.createElement('summary');
                if (key !== undefined) {
                    const keySpan = document.createElement('span');
                    keySpan.className = 'payload-json-key';
                    keySpan.textContent = '"' + key + '"';
                    summary.appendChild(keySpan);
                    summary.appendChild(document.createTextNode(': '));
                }

                if (Array.isArray(value)) {
                    summary.appendChild(document.createTextNode('[' + value.length + ']'));
                } else {
                    const keys = Object.keys(value);
                    summary.appendChild(document.createTextNode('{' + keys.length + '}'));
                }
                details.appendChild(summary);

                const body = document.createElement('div');
                body.style.paddingLeft = '1rem';

                if (Array.isArray(value)) {
                    value.forEach(function (item, index) {
                        body.appendChild(renderNode(item, String(index)));
                    });
                } else {
                    Object.keys(value).forEach(function (childKey) {
                        body.appendChild(renderNode(value[childKey], childKey));
                    });
                }

                details.appendChild(body);
                return details;
            }

            try {
                const payload = JSON.parse(dataScript.textContent || 'null');
                container.appendChild(renderNode(payload));
            } catch (e) {
                container.textContent = 'Failed to parse payload JSON.';
            }
        })();
    </script>
@else
    <p>No JSON payload found in this message body.</p>
@endif

<details>
    <summary>Raw Message Body</summary>
    <pre>{{ $message->body }}</pre>
</details>

<a href="{{ route('sqs_messages.index') }}">Back to List</a>
