<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessUazapiWebhookJob;
use App\Models\UazapiInstance;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UazapiWebhookController extends Controller
{
    public function handle(Request $request, string $secret): JsonResponse
    {
        $instance = UazapiInstance::query()->where('webhook_secret', $secret)->first();
        if (! $instance) {
            return response()->json(['received' => true]);
        }

        $payload = $request->all();
        if (! is_array($payload) || $payload === []) {
            return response()->json(['received' => true]);
        }

        ProcessUazapiWebhookJob::dispatch((int) $instance->id, $payload);

        return response()->json(['received' => true]);
    }
}
