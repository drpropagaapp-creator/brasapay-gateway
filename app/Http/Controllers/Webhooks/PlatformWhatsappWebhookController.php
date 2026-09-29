<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessPlatformWhatsappWebhookJob;
use App\Models\PlatformWhatsappChannel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PlatformWhatsappWebhookController extends Controller
{
    public function handle(Request $request, string $secret): JsonResponse
    {
        $channel = PlatformWhatsappChannel::query()->where('webhook_secret', $secret)->first();
        if (! $channel) {
            return response()->json(['received' => true]);
        }

        $payload = $request->all();
        if (! is_array($payload) || $payload === []) {
            return response()->json(['received' => true]);
        }

        unset($payload['apikey'], $payload['token'], $payload['instanceToken']);

        ProcessPlatformWhatsappWebhookJob::dispatch((int) $channel->id, $payload);

        return response()->json(['received' => true]);
    }
}
