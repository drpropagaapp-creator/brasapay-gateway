<?php

namespace App\Gateways\Contracts;

/**
 * Adquirente que sabe guardar o cartão para cobrar de novo sem o cliente presente.
 *
 * Como o driver recebe o pedido no array $card de createCardPayment():
 * - 'save' => true: primeira cobrança; o driver prepara o cartão para reuso e devolve
 *   'saved_card' => [payment_method_id, customer_id, brand, last_four, exp_month, exp_year].
 * - 'customer_id' + 'off_session' => true: cobrança seguinte com o cartão guardado
 *   ('payment_token' é o payment_method_id salvo).
 */
interface SavesCards
{
    /**
     * Desvincula o cartão guardado na adquirente (o cliente pediu para remover).
     *
     * @param  array<string, string>  $credentials
     */
    public function forgetSavedCard(array $credentials, string $paymentMethodId): void;
}
