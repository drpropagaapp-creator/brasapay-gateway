<?php

namespace App\Http\Controllers;

use App\Models\Subscription;
use App\Services\Versell\VersellPixAutoLifecycleService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Brasa Subscriptions — autoatendimento do assinante: ver, cancelar a renovação (acesso segue
 * até o fim do período pago) e reativar. Só as assinaturas do próprio usuário.
 */
class CustomerSubscriptionsController extends Controller
{
    public const REASONS = [
        'price' => 'Ficou caro para mim',
        'not_using' => 'Não estou usando',
        'content' => 'O conteúdo não atendeu',
        'payment_issue' => 'Problema com pagamento',
        'other' => 'Outro motivo',
    ];

    public function index(Request $request): Response
    {
        $subs = Subscription::query()->where('user_id', $request->user()->id)
            ->with(['product:id,name', 'subscriptionPlan:id,name,price,interval'])
            ->latest('id')->get()
            ->map(fn (Subscription $s) => [
                'id' => $s->id,
                'product' => $s->product?->name,
                'plan' => $s->subscriptionPlan?->name,
                'price' => (float) ($s->subscriptionPlan?->price ?? 0),
                'interval' => $s->subscriptionPlan?->interval,
                'status' => $s->status,
                'current_period_end' => $s->current_period_end?->toDateString(),
                'cancel_at_period_end' => (bool) $s->cancel_at_period_end,
                'pix_auto' => trim((string) $s->gateway_subscription_id) !== '',
                'renewal_url' => $s->renewal_token ? url('/renovar/'.$s->renewal_token) : null,
            ]);

        return Inertia::render('Cliente/Assinaturas', ['subscriptions' => $subs, 'reasons' => self::REASONS]);
    }

    public function cancel(Request $request, Subscription $subscription): RedirectResponse
    {
        $this->authorizeOwner($request, $subscription);
        $v = $request->validate([
            'reason' => ['required', Rule::in(array_keys(self::REASONS))],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);
        if (! in_array($subscription->status, [Subscription::STATUS_ACTIVE, Subscription::STATUS_PAST_DUE], true) || $subscription->cancel_at_period_end) {
            return back()->with('error', 'Esta assinatura já está cancelada.');
        }

        $subscription->update([
            'cancel_at_period_end' => true,
            'cancellation_requested_at' => now(),
            'cancellation_reason' => $v['reason'],
            'cancellation_note' => $v['note'] ?? null,
        ]);

        $message = 'Renovação cancelada. Seu acesso continua até '.($subscription->current_period_end?->format('d/m/Y') ?? 'o fim do período').'.';
        if (trim((string) $subscription->gateway_subscription_id) !== '') {
            // PIX automático: revoga a autorização na Versell já (o débito poderia sair antes do fim do período).
            $versell = app(VersellPixAutoLifecycleService::class);
            if (VersellPixAutoLifecycleService::isVersellSubscription($subscription)) {
                $versell->cancelRemote($subscription);
            } else {
                $message .= ' Como você usa PIX automático, cancele também a autorização no app do seu banco.';
            }
        }
        Log::info('Subscription: cancelamento pedido pelo assinante', ['subscription_id' => $subscription->id, 'reason' => $v['reason']]);

        return back()->with('success', $message);
    }

    public function reactivate(Request $request, Subscription $subscription): RedirectResponse
    {
        $this->authorizeOwner($request, $subscription);
        if (! $subscription->cancel_at_period_end || $subscription->status !== Subscription::STATUS_ACTIVE) {
            return back()->with('error', 'Não há cancelamento para desfazer.');
        }
        if (trim((string) $subscription->gateway_subscription_id) !== '') {
            // A autorização do PIX automático já foi revogada: precisa renovar pelo link (nova autorização).
            return back()->with('error', 'Para voltar, renove pelo link de renovação (o PIX automático precisa de nova autorização).');
        }
        $subscription->update(['cancel_at_period_end' => false, 'cancellation_requested_at' => null]);

        return back()->with('success', 'Assinatura reativada. A renovação volta a acontecer normalmente.');
    }

    private function authorizeOwner(Request $request, Subscription $subscription): void
    {
        abort_unless((int) $subscription->user_id === (int) $request->user()->id, 404);
    }
}
