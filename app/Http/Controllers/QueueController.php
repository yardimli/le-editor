<?php

namespace App\Http\Controllers;

use App\Services\BrowserQueue;
use Illuminate\Http\Request;

class QueueController extends Controller
{
    public function state(Request $request, BrowserQueue $queue)
    {
        return response()->json($queue->state($request->user()->id));
    }

    public function step(Request $request, BrowserQueue $queue)
    {
        // A single browser request runs at most one completion, never a server-side chain.
        set_time_limit(240);
        return response()->json($queue->step($request->user()->id));
    }

    public function cancel(Request $request, BrowserQueue $queue)
    {
        $queue->pause($request->user()->id);
        return response()->json($queue->state($request->user()->id));
    }

    public function resume(Request $request, BrowserQueue $queue)
    {
        $queue->resume($request->user()->id);
        return response()->json($queue->state($request->user()->id));
    }
}
