<?php

namespace App\Http\Middleware;

use App\Models\ApiApplication;
use App\Models\ApiKey;
use App\Models\ApiRequestLog;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Brasa Developers: registra cada chamada autenticada da API (rota, status, tempo, erro resumido).
 * Nunca guarda corpo, cabeçalhos ou credenciais. Grava depois da resposta e nunca derruba a chamada.
 */
class LogApiRequest
{
    public function handle(Request $request, Closure $next): Response
    {
        $request->attributes->set('api_log_started', microtime(true));

        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        try {
            $app = $request->attributes->get('api_application');
            if (! $app instanceof ApiApplication) {
                return;
            }
            $key = $request->attributes->get('api_key');
            $started = (float) $request->attributes->get('api_log_started', microtime(true));
            $status = $response->getStatusCode();

            $error = null;
            if ($status >= 400) {
                $decoded = json_decode((string) $response->getContent(), true);
                $error = is_array($decoded) && is_string($decoded['message'] ?? null) ? Str::limit($decoded['message'], 250, '') : null;
            }

            ApiRequestLog::query()->create([
                'tenant_id' => (int) $app->tenant_id,
                'api_application_id' => $app->id,
                'api_key_id' => $key instanceof ApiKey ? $key->id : null,
                'environment' => (string) $request->attributes->get('api_environment', ApiKey::ENV_LIVE),
                'method' => $request->method(),
                'route' => Str::limit((string) ($request->route()?->uri() ?? $request->path()), 120, ''),
                'status' => $status,
                'duration_ms' => (int) max(0, round((microtime(true) - $started) * 1000)),
                'request_id' => Str::limit((string) $request->attributes->get('api_request_id', ''), 64, '') ?: null,
                'error_message' => $error,
                'ip' => $request->ip(),
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::debug('LogApiRequest: falha ao registrar chamada', ['message' => $e->getMessage()]);
        }
    }
}
