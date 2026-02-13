<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
            {{ __('Notifications') }}
        </h2>
    </x-slot>
<div>
 <!-- Display success message -->
 @if (session('success'))
 <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded relative" role="alert">
     <span class="block sm:inline">{{ session('success') }}</span>
 </div>
@endif

<!-- Display error message -->
@if (session('error'))
 <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded relative" role="alert">
     <span class="block sm:inline">{{ session('error') }}</span>
 </div>
@endif

    <h1 class="text-2xl font-bold mb-4">Destinations</h1>

    <table class="min-w-full divide-y divide-gray-200 border border-gray-300">
        <thead class="bg-gray-50">
            <tr>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Destination Id</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Name</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">SQS ARN</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Event Bridge</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Private SQS</th>
            </tr>
        </thead>
        <tbody class="bg-white divide-y divide-gray-200">
            @foreach($destinations as $key => $destination)
                <tr>
                    <td class="px-6 py-4 whitespace-nowrap">{{ $destination['destinationId'] }}</td>
                    <td class="px-6 py-4 whitespace-nowrap">{{ $destination['name'] }}</td>
                    <td class="px-6 py-4 whitespace-nowrap">{{ $destination['resource']['sqs']['arn'] }}</td>
                    <td class="px-6 py-4 whitespace-nowrap">{{ $destination['resource']['eventBridge'] ?? "N/A" }}</td>
                    <td class="px-6 py-4 whitespace-nowrap">{{ $destination['resource']['privateSqs'] ?? "N/A" }}</td>
                    <td><form action="{{ route('notifications.deleteDestination') }}" method="POST">
                        @csrf
                        <input type="hidden" name="destinationId" value="{{ $destination['destinationId'] }}">
                        <button type="submit" class="mt-4 bg-red-500 hover:bg-red-700 text-white font-bold py-2 px-4 rounded">Delete Destination</button>
                    </form>
                    </td>
                </tr>
            @endforeach

            <td><form action=" {{ route('notifications.destinations-store') }}" method="POST">
                @csrf</td>
                <td><label for="name" class="block text-sm font-medium text-gray-700">Name</label>
                <input type="text" name="name" id="name" class="mt-1 block w-full py-2 px-3 border border-gray-300 bg-white rounded-md shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm"></td>
                <td><label for="sqsArn" class="block text-sm font-medium text-gray-700">SQS ARN</label>
                <input type="text" name="sqsArn" id="sqsArn" class="mt-1 block w-full py-2 px-3 border border-gray-300 bg-white rounded-md shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm"></td>
                <td><label for="eventBridge" class="block text-sm font-medium text-gray-700">Event Bridge</label>
                <input type="text" name="eventBridge" id="eventBridge" class="mt-1 block w-full py-2 px-3 border border-gray-300 bg-white rounded-md shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm"></td>
                <td><label for="privateSqs" class="block text-sm font-medium text-gray-700">Private SQS</label>
                <input type="text" name="privateSqs" id="privateSqs" class="mt-1 block w-full py-2 px-3 border border-gray-300 bg-white rounded-md shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm"></td>
                <td><button type="submit" class="mt-4 bg-blue-500 hover:bg-blue-700 text-white font-bold py-2 px-4 rounded">Create Destination</button>
            </form><td>

        </tbody>
    </table>
   

    <h2 class="text-2xl font-bold mt-8 mb-4">Current Subscriptions</h2>
    <table class="min-w-full divide-y divide-gray-200 border border-gray-300">
        <thead class="bg-gray-50">
            <tr>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Notification Type</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Subscription Id</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Destination Id</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Payload Version</th>
            </tr>
        </thead>
        <tbody class="bg-white divide-y divide-gray-200">
            
            @foreach($responses['responses'] as $notificationType => $subscriptionData)

                <tr>
                    <td class="px-6 py-4 whitespace-nowrap">
                        @isset($notificationType)
                            {{ $notificationType }}
                        @endisset
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap">
                        @isset($subscriptionData['subscriptionId'])
                            {{ $subscriptionData['subscriptionId'] }}
                        @endisset
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap">
                        @isset($subscriptionData['destinationId'])
                            {{ $subscriptionData['destinationId'] }}
                        @endisset
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap">
                        @isset($subscriptionData['payloadVersion'])
                            {{$subscriptionData['payloadVersion']}}
                        @endisset
                    <td>
                        @isset($subscriptionData['subscriptionId'])
                        <form action="{{ route('notifications.deleteSubscription') }}" method="POST">
                        @csrf
                        <input type="hidden" name="subscriptionId" value="{{$subscriptionData['subscriptionId']}}">
                        <input type="hidden" name="notificationType" value="{{$notificationType}}">
                        <button type="submit" class="mt-4 bg-red-500 hover:bg-red-700 text-white font-bold py-2 px-4 rounded">Delete Subscription</button>
                        </form>
                        @endisset                        
                    </td>
                </tr>
            @endforeach
            <td><form action="{{ route('notifications.createSubscription') }}" method="POST">
                @csrf
                <label for="notificationType" class="block text-sm font-medium text-gray-700">Notification Type</label>
                <select id="notificationType" name="notificationType" class="mt-1 block w-full py-2 px-3 border border-gray-300 bg-white rounded-md shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm">
                    @foreach($notificationTypes as $notificationType)
                    <option value="{{ $notificationType }}">{{ $notificationType }}</option>
                @endforeach
                </select>
            </td>
            <td></td>
            <td><label for="destinationId" class="block text-sm font-medium text-gray-700">Destination Id</label>
                <select id="destinationId" name="destinationId" class="mt-1 block w-full py-2 px-3 border border-gray-300 bg-white rounded-md shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm
                ">
                    @foreach($destinations as $key => $destination)
                        <option value="{{ $destination['destinationId'] }}">{{ $destination['destinationId'] }}</option>
                    @endforeach
                </select>
            </td>
            <td> <label for="payloadVersion" class="block text-sm font-medium text-gray-700">Payload Version</label>
                <select id="payloadVersion" name="payloadVersion" class="mt-1 block w-full py-2 px-3 border border-gray-300 bg-white rounded-md shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm">
                    <option value="1.0">1.0</option>
                    <option value="2.0">2.0</option>
                </select>
            </td>
            <td class="px-6 py-4 whitespace-nowrap"><button type="submit" class="mt-4 bg-blue-500 hover:bg-blue-700 text-white font-bold py-2 px-4 rounded">Create Subscription</button>
            </form>
            </td>

        </tbody>
    </table>
        
</div>
</x-app-layout>
