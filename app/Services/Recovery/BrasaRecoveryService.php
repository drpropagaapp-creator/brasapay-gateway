<?php

namespace App\Services\Recovery;

use App\Mail\SubscriptionReminderMail;
use App\Models\CheckoutSession;
use App\Models\Order;
use App\Models\ProductOffer;
use App\Models\RecoveryMessage;
use App\Models\RecoveryOptOut;
use App\Models\Setting;
use App\Services\TenantMailConfigService;
use App\Support\RecoveryEmailSettings;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;

/**
 * Brasa Recovery — e-mail de recuperação (carrinho abandonado, PIX e boleto pendentes).
 *
 * Regras: só seller com o recurso ligado; nunca para quem se descadastrou ou já comprou o
 * produto; respeita horário silencioso e limite diário por destinatário; cada passo sai uma
 * única vez (índice único); passos vencidos há mais de 2 h são descartados (ligar o recurso não
 * dispara uma rajada de mensagens antigas). Recuperação só é atribuída com pagamento confirmado.
 */
class BrasaRecoveryService
{
    private const LATE_TOLERANCE_MINUTES = 120;

    public function __construct(private TenantMailConfigService $mail) {}

    /** @return array{sent:int, skipped:int, failed:int, recovered:int} */
    public function run(): array
    {
        $totals = ['sent' => 0, 'skipped' => 0, 'failed' => 0, 'recovered' => 0];
        $tenantIds = Setting::query()->where('key', RecoveryEmailSettings::KEY)->whereNotNull('tenant_id')->pluck('tenant_id');
        foreach ($tenantIds as $tenantId) {
            $cfg = RecoveryEmailSettings::forTenant((int) $tenantId);
            if (! $cfg['enabled']) {
                continue;
            }
            foreach ($this->candidates((int) $tenantId, $cfg) as $c) {
                $r = $this->processCandidate((int) $tenantId, $cfg, $c);
                if ($r !== null) {
                    $totals[$r]++;
                }
            }
        }
        $totals['recovered'] = $this->attributeRecoveries();

        return $totals;
    }

    /** @return list<array<string, mixed>> */
    private function candidates(int $tenantId, array $cfg): array
    {
        $out = [];
        $events = $cfg['events'];
        $window = fn (string $event) => now()->subMinutes((int) (end($events[$event]['steps'])['delay_minutes'] ?? 0) + self::LATE_TOLERANCE_MINUTES);

        if ($events['cart_abandoned']['enabled'] && $events['cart_abandoned']['steps'] !== []) {
            CheckoutSession::query()
                ->where('tenant_id', $tenantId)
                ->whereIn('step', [CheckoutSession::STEP_FORM_STARTED, CheckoutSession::STEP_FORM_FILLED])
                ->whereNull('order_id')
                ->whereNotNull('email')->where('email', '!=', '')
                ->where('updated_at', '>=', $window('cart_abandoned'))
                ->with('product:id,name,checkout_slug')
                ->limit(1000)->get()
                ->each(function (CheckoutSession $s) use (&$out) {
                    $at = $s->form_filled_at ?? $s->form_started_at ?? $s->updated_at ?? $s->created_at;
                    $out[] = [
                        'event' => 'cart_abandoned', 'key' => 'session:'.$s->id, 'at' => $at,
                        'email' => (string) $s->email, 'name' => (string) ($s->name ?? ''),
                        'product_id' => $s->product_id, 'product_name' => (string) ($s->product?->name ?? ''),
                        'amount' => null, 'link' => $this->checkoutLink($s->product?->checkout_slug, null),
                        'session_id' => $s->id, 'order_id' => null,
                    ];
                });
        }

        foreach (['pix_pending' => 'pix', 'boleto_pending' => 'boleto'] as $event => $method) {
            if (! $events[$event]['enabled'] || $events[$event]['steps'] === []) {
                continue;
            }
            Order::query()
                ->where('tenant_id', $tenantId)->where('status', 'pending')->where('payment_method', $method)
                ->whereNotNull('email')->where('email', '!=', '')
                ->where('created_at', '>=', $window($event))
                ->with('product:id,name,checkout_slug')
                ->limit(1000)->get()
                ->each(function (Order $o) use (&$out, $event) {
                    $out[] = [
                        'event' => $event, 'key' => 'order:'.$o->id, 'at' => $o->created_at,
                        'email' => (string) $o->email, 'name' => (string) ($o->user?->name ?? ''),
                        'product_id' => $o->product_id, 'product_name' => (string) ($o->product?->name ?? ''),
                        'amount' => (float) $o->amount, 'link' => $this->checkoutLink($o->product?->checkout_slug, $o->product_offer_id),
                        'session_id' => null, 'order_id' => (string) $o->id,
                    ];
                });
        }

        return $out;
    }

    private function processCandidate(int $tenantId, array $cfg, array $c): ?string
    {
        if (! $c['at'] instanceof Carbon || $c['link'] === null) {
            return null;
        }
        $email = mb_strtolower(trim($c['email']));
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return null;
        }

        foreach ($cfg['events'][$c['event']]['steps'] as $index => $step) {
            $due = $c['at']->copy()->addMinutes((int) $step['delay_minutes']);
            if (now()->lt($due) || now()->gt($due->copy()->addMinutes(self::LATE_TOLERANCE_MINUTES))) {
                continue;
            }
            if (RecoveryMessage::query()->where('channel', 'email')->where('event', $c['event'])->where('subject_key', $c['key'])->where('step', $index)->exists()) {
                continue;
            }

            $skip = match (true) {
                RecoveryOptOut::isOptedOut($tenantId, $email) => 'opted_out',
                $this->alreadyBought($tenantId, $email, $c['product_id'], $c['at']) => 'already_bought',
                default => null,
            };
            if ($skip === null && ($this->inQuietHours($cfg) || $this->dailyCapReached($tenantId, $email, $cfg))) {
                return null; // adia: tenta de novo na próxima execução (dentro da tolerância)
            }

            $row = $this->claim($tenantId, $c, $index, $email, $skip);
            if ($row === null) {
                continue; // outro processo pegou este passo
            }
            if ($skip !== null) {
                return 'skipped';
            }

            try {
                $this->send($tenantId, $email, $this->render($step['subject'], $c, false), $this->html($step['body'], $c, $tenantId, $email));
                $row->update(['status' => 'sent', 'sent_at' => now()]);

                return 'sent';
            } catch (\Throwable $e) {
                $row->update(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 500)]);
                Log::warning('BrasaRecovery: falha no envio', ['tenant_id' => $tenantId, 'event' => $c['event'], 'message' => $e->getMessage()]);

                return 'failed';
            }
        }

        return null;
    }

    private function claim(int $tenantId, array $c, int $step, string $email, ?string $skip): ?RecoveryMessage
    {
        try {
            return RecoveryMessage::query()->create([
                'tenant_id' => $tenantId, 'channel' => 'email', 'event' => $c['event'], 'step' => $step,
                'subject_key' => $c['key'], 'checkout_session_id' => $c['session_id'], 'order_id' => $c['order_id'],
                'product_id' => $c['product_id'] !== null ? (string) $c['product_id'] : null,
                'recipient' => $email, 'status' => $skip !== null ? 'skipped' : 'sending', 'skip_reason' => $skip,
            ]);
        } catch (QueryException) {
            return null;
        }
    }

    private function alreadyBought(int $tenantId, string $email, mixed $productId, Carbon $since): bool
    {
        if ($productId === null || $productId === '') {
            return false;
        }

        return Order::query()->where('tenant_id', $tenantId)->whereRaw('LOWER(email) = ?', [$email])
            ->whereIn('status', ['completed', 'disputed'])
            ->where(fn ($q) => $q->where('product_id', $productId)->orWhereHas('orderItems', fn ($i) => $i->where('product_id', $productId)))
            ->exists();
    }

    private function inQuietHours(array $cfg): bool
    {
        $h = (int) now('America/Sao_Paulo')->format('G');
        $s = $cfg['quiet_start'];
        $e = $cfg['quiet_end'];

        return $s === $e ? false : ($s < $e ? ($h >= $s && $h < $e) : ($h >= $s || $h < $e));
    }

    private function dailyCapReached(int $tenantId, string $email, array $cfg): bool
    {
        return RecoveryMessage::query()->where('tenant_id', $tenantId)->where('recipient', $email)
            ->where('status', 'sent')->where('sent_at', '>=', now()->subDay())->count() >= $cfg['max_per_day'];
    }

    private function checkoutLink(?string $productSlug, mixed $offerId): ?string
    {
        $slug = $offerId ? ProductOffer::query()->whereKey($offerId)->value('checkout_slug') : null;
        $slug = $slug ?: $productSlug;

        return $slug ? url('/c/'.$slug) : null;
    }

    private function render(string $text, array $c, bool $escape): string
    {
        $firstName = trim(explode(' ', trim($c['name']))[0] ?? '');
        $vars = [
            '{nome}' => $firstName !== '' ? $firstName : 'tudo bem',
            '{produto}' => $c['product_name'] !== '' ? $c['product_name'] : 'seu pedido',
            '{valor}' => $c['amount'] !== null ? 'R$ '.number_format((float) $c['amount'], 2, ',', '.') : '',
            '{link}' => $c['link'],
        ];
        if ($escape) {
            $vars = array_map(fn ($v) => e($v), $vars);
            $vars['{link}'] = '<a href="'.e($c['link']).'">'.e($c['link']).'</a>';
        }

        return strtr($text, $vars);
    }

    private function html(string $body, array $c, int $tenantId, string $email): string
    {
        $optOut = URL::signedRoute('recovery.optout', ['tenant' => $tenantId, 'email' => rtrim(strtr(base64_encode($email), '+/', '-_'), '=')]);
        $content = nl2br($this->render(e($body), $c, true));

        return '<div style="font-family:Arial,sans-serif;font-size:15px;line-height:1.5;color:#18181b">'.$content
            .'<p style="margin-top:28px;font-size:12px;color:#71717a">Não quer mais receber estes lembretes? <a href="'.e($optOut).'">Descadastrar</a>.</p></div>';
    }

    protected function send(int $tenantId, string $email, string $subject, string $html): void
    {
        $attempts = [];
        if ($this->mail->isEmailConfigured($tenantId)) {
            $attempts[] = fn () => $this->mail->applyMailerConfigForTenant($tenantId, [], null);
        }
        if ($this->mail->isEmailConfigured(null)) {
            $attempts[] = fn () => $this->mail->applyPlatformGlobalMailerConfig();
        }
        if ($attempts === []) {
            throw new \RuntimeException('Nenhum SMTP configurado.');
        }
        $last = null;
        foreach ($attempts as $apply) {
            try {
                $apply();
                $this->mail->assertSmtpHostIsConfigured();
                Mail::purge('smtp');
                Mail::mailer('smtp')->to($email)->send(new SubscriptionReminderMail($subject, $html));

                return;
            } catch (\Throwable $e) {
                $last = $e;
            }
        }
        throw $last;
    }

    /**
     * Venda recuperada = pagamento confirmado DEPOIS do e-mail, do mesmo comprador e produto
     * (o próprio pedido do PIX/boleto pago, ou um pedido novo). Sem isso, nada é atribuído.
     */
    private function attributeRecoveries(): int
    {
        $count = 0;
        RecoveryMessage::query()->where('status', 'sent')->whereNull('recovered_order_id')
            ->where('sent_at', '>=', now()->subDays(7))
            ->orderBy('sent_at')->limit(2000)->get()
            ->groupBy(fn ($m) => $m->tenant_id.'|'.$m->recipient.'|'.$m->product_id)
            ->each(function ($msgs) use (&$count) {
                $first = $msgs->first();
                $order = null;
                $own = $msgs->pluck('order_id')->filter()->unique();
                if ($own->isNotEmpty()) {
                    $order = Order::query()->whereIn('id', $own->all())->where('status', 'completed')
                        ->where('updated_at', '>=', $first->sent_at)->first();
                }
                $order ??= Order::query()->where('tenant_id', $first->tenant_id)->whereRaw('LOWER(email) = ?', [$first->recipient])
                    ->where('status', 'completed')->where('created_at', '>=', $first->sent_at)
                    ->when($first->product_id, fn ($q) => $q->where(fn ($w) => $w->where('product_id', $first->product_id)
                        ->orWhereHas('orderItems', fn ($i) => $i->where('product_id', $first->product_id))))
                    ->orderBy('created_at')->first();
                if ($order === null) {
                    return;
                }
                RecoveryMessage::query()->whereIn('id', $msgs->pluck('id'))->update([
                    'recovered_order_id' => (string) $order->id,
                    'recovered_amount_cents' => (int) bcmul(sprintf('%.2F', (float) $order->amount), '100', 0),
                    'recovered_at' => now(),
                ]);
                $count++;
            });

        return $count;
    }
}
