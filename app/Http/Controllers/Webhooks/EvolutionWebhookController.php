<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessEvolutionWebhookJob;
use App\Models\EvolutionInstance;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EvolutionWebhookController extends Controller
{
    public function handle(Request $request, string $secret): JsonResponse
    {
        $instance = EvolutionInstance::query()->where('webhook_secret', $secret)->first();
        if (! $instance) {
            return response()->json(['received' => true]);
        }

        $payload = $request->all();
        if (! is_array($payload) || $payload === []) {
            return response()->json(['received' => true]);
        }

        unset($payload['apikey'], $payload['token'], $payload['instanceToken']);

        ProcessEvolutionWebhookJob::dispatch((int) $instance->id, $payload);

        return response()->json(['received' => true]);
    }
}
