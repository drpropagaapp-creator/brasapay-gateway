<?php

namespace App\Jobs;

use App\Mail\PurchaseConfirmationMail;
use App\Models\Order;
use App\Models\Setting;
use App\Services\AccessEmailService;
use App\Services\BrandingEmailData;
use App\Services\PlatformTransactionalMailService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Confirmação de compra padrão da plataforma (todo pedido pago, qualquer tipo de produto),
 * além do e-mail de acesso que já existe. Sai pelo e-mail da plataforma com texto fixo.
 */
class SendPurchaseConfirmationMailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Desliga sem deploy: settings (tenant_id null) key=purchase_confirmation_email_enabled, value "0". */
    public const SETTING_ENABLED = 'purchase_confirmation_email_enabled';

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [60, 300];

    public function __construct(public int $orderId)
    {
        $this->onQueue('default');
    }

    public static function enabled(): bool
    {
        $raw = Setting::get(self::SETTING_ENABLED, '1', null);

        return ! in_array(strtolower(trim((string) $raw)), ['0', 'false', 'off', 'no'], true);
    }

    public function handle(PlatformTransactionalMailService $mail, AccessEmailService $access): void
    {
        if (! self::enabled()) {
            return;
        }

        $order = Order::query()->with(['product', 'user'])->find($this->orderId);
        if (! $order instanceof Order || $order->status !== 'completed') {
            return;
        }

        $email = trim((string) ($order->email ?: $order->user?->email ?: ''));
        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            Log::info('PurchaseConfirmation: pedido sem e-mail válido.', ['order_id' => $order->id]);

            return;
        }

        $meta = is_array($order->metadata) ? $order->metadata : [];
        if (! empty($meta['purchase_confirmation_sent_at'])) {
            return;
        }
        $seller = $access->resolveSellerSenderIdentity($order->tenant_id ? (int) $order->tenant_id : null, $order->product);
        $branding = BrandingEmailData::forTenant(null);

        $mailable = new PurchaseConfirmationMail([
            'app_name' => (string) ($branding['app_name'] ?? config('app.name', 'BrasaPay')),
            'logo_url' => $branding['logo_url'] ?? null,
            'customer_name' => trim((string) ($order->user?->name ?? '')) ?: null,
            'product_name' => trim((string) ($order->product?->name ?? '')) ?: 'Seu pedido',
            'amount' => 'R$ '.number_format((float) $order->amount, 2, ',', '.'),
            'payment_method' => self::paymentMethodLabel((string) $order->payment_method),
            'order_reference' => '#'.(trim((string) ($order->public_reference ?? '')) ?: (string) $order->id),
            'paid_at' => ($order->updated_at ?? now())->copy()->timezone('America/Sao_Paulo')->format('d/m/Y H:i'),
            'seller_name' => $seller['name'] ?? null,
            'seller_email' => $seller['email'] ?? null,
        ]);

        // OrderCompleted dispara em vários pontos (webhook, checkout, aprovação manual): um envio só.
        $lockKey = 'purchase_confirmation_sent.'.$order->id;
        if (! Cache::add($lockKey, 1, now()->addDays(30))) {
            return;
        }

        try {
            $sent = $mail->send($mailable, $email);
        } catch (\Throwable $e) {
            Cache::forget($lockKey);
            throw $e;
        }
        if (! $sent) {
            // Libera nova tentativa (retry do job) — o serviço já registrou o motivo no log.
            Cache::forget($lockKey);
            throw new \RuntimeException('Falha ao enviar confirmação de compra do pedido '.$order->id.'.');
        }

        $meta['purchase_confirmation_sent_at'] = now()->toIso8601String();
        $order->forceFill(['metadata' => $meta])->saveQuietly();
    }

    public static function paymentMethodLabel(string $method): string
    {
        return match (strtolower(trim($method))) {
            'pix' => 'PIX',
            'pix_auto' => 'PIX Automático',
            'card', 'credit_card' => 'Cartão de crédito',
            'boleto' => 'Boleto',
            'apple_pay' => 'Apple Pay',
            'google_pay' => 'Google Pay',
            'paypal' => 'PayPal',
            'open_finance' => 'Open Finance',
            'crypto' => 'Cripto',
            '' => '–',
            default => ucfirst(str_replace('_', ' ', $method)),
        };
    }
}
