<?php

namespace App\Services\Payments;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Registra cada tentativa de cobrança numa adquirente (sucesso, recusa, erro técnico, timeout)
 * para o monitoramento e o Smart Routing. Nunca interrompe o pagamento: qualquer falha ao
 * registrar é engolida e logada.
 */
class AcquirerAttemptRecorder
{
    public const SUCCESS = 'success';

    public const DECLINED = 'declined';

    public const TECHNICAL_ERROR = 'technical_error';

    public const TIMEOUT = 'timeout';

    private const DECLINED_STATUSES = ['declined', 'refused', 'failed', 'rejected', 'denied', 'canceled', 'cancelled', 'not_authorized', 'unauthorized'];

    private static ?bool $tableExists = null;

    public static function record(
        string $slug,
        string $method,
        ?int $tenantId,
        mixed $orderId,
        string $outcome,
        float $startedAt,
        int $position,
        ?string $strategy = null,
        ?\Throwable $error = null,
    ): void {
        try {
            if (self::$tableExists === null) {
                self::$tableExists = Schema::hasTable('acquirer_attempts');
            }
            if (! self::$tableExists) {
                return;
            }
            DB::table('acquirer_attempts')->insert([
                'gateway_slug' => mb_substr($slug, 0, 40),
                'method' => mb_substr($method, 0, 20),
                'tenant_id' => $tenantId,
                'order_id' => $orderId !== null ? mb_substr((string) $orderId, 0, 64) : null,
                'outcome' => $outcome,
                'latency_ms' => max(0, (int) round((microtime(true) - $startedAt) * 1000)),
                'position' => max(0, $position),
                'strategy' => $strategy,
                'error_class' => $error !== null ? mb_substr(class_basename($error), 0, 120) : null,
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::debug('AcquirerAttemptRecorder: falha ao registrar tentativa', ['message' => $e->getMessage()]);
        }
    }

    /**
     * Timeout/conexão interrompida não é falha definitiva: a cobrança pode ter sido criada
     * na adquirente. Tudo o que não for claramente timeout conta como erro técnico.
     */
    public static function classifyException(\Throwable $e): string
    {
        if ($e instanceof ConnectionException) {
            return self::TIMEOUT;
        }
        $message = strtolower($e->getMessage());
        foreach (['timed out', 'timeout', 'curl error 28', 'curl error 52', 'curl error 56', 'empty reply from server', 'connection reset'] as $needle) {
            if (str_contains($message, $needle)) {
                return self::TIMEOUT;
            }
        }

        return self::TECHNICAL_ERROR;
    }

    /** Resultado de cartão devolvido pelo driver (sem exceção). */
    public static function classifyCardStatus(?string $status): string
    {
        return in_array(strtolower((string) $status), self::DECLINED_STATUSES, true) ? self::DECLINED : self::SUCCESS;
    }

    public static function resetCache(): void
    {
        self::$tableExists = null;
    }
}
