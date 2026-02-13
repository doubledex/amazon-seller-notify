<?php

namespace App\Http\Controllers;

use App\Models\SqsMessage;
use Illuminate\Http\Request;
use Illuminate\Support\Str; // Add this line
use App\Services\MarketplaceService;
use Illuminate\Support\Facades\Log;
use App\Services\SqsMessageProcessor;

class SqsMessagesController extends Controller
{
    public function index(Request $request)
    {
        // $messages = SqsMessage::paginate(10);
        $messages = SqsMessage::orderBy('EventTime', 'desc')->paginate(60);
        return view('sqs_messages.index', [ 'messages' => $messages  ]); // removed compact()
    }

    public function show($id)
    {
        $message = SqsMessage::findOrFail($id);
        $marketplaceService = new MarketplaceService();
        $marketplaceMap = $marketplaceService->getMarketplaceMap();

        return view('sqs_messages.show', [
            'message' => $message,
            'marketplaceMap' => $marketplaceMap,
        ]); // removed compact('message'));
    }

    public function flag($id)
    {
        $message = SqsMessage::findOrFail($id);
        $message->flagged = true;
        $message->save();
        return redirect()->route('sqs_messages.index')->with('success', 'Message flagged.');
    }

    public function destroy($id)
    {
        $message = SqsMessage::findOrFail($id);
        $message->delete();
        return redirect()->route('sqs_messages.index')->with('success', 'Message deleted.');
    }

    public function fetchLatest(SqsMessageProcessor $processor)
    {
        try {
            $processor->processMessages();
            return redirect()->route('sqs_messages.index')->with('success', 'Fetched latest SQS messages.');
        } catch (\Exception $e) {
            Log::error('SQS fetch failed', ['error' => $e->getMessage()]);
            return redirect()->route('sqs_messages.index')->with('error', 'Fetch failed: ' . $e->getMessage());
        }
    }
}
