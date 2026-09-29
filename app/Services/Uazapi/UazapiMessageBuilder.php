<?php

namespace App\Services\Uazapi;

use App\Models\CheckoutSession;
use App\Models\Order;
use App\Services\Integrax\IntegraxMessageBuilder;

class UazapiMessageBuilder
{
    public function __construct(private IntegraxMessageBuilder $integraxBuilder) {}

    /**
     * @return array<string, string>
     */
    public function fromCheckoutSession(CheckoutSession $session): array
    {
        $vars = $this->integraxBuilder->fromCheckoutSession($session);
        $vars['pix'] = '';

        return $vars;
    }

    /**
     * @param  array<string, mixed>  $pixData
     * @return array<string, string>
     */
    public function fromOrder(Order $order, array $pixData = []): array
    {
        $vars = $this->integraxBuilder->fromOrder($order);
        $vars['pix'] = $this->resolvePixCopy($order, $pixData);

        return $vars;
    }

    /**
     * @param  array<string, string>  $vars
     */
    public function render(string $template, array $vars): string
    {
        $replace = [];
        foreach ($vars as $key => $value) {
            $replace['{'.$key.'}'] = $value;
        }

        $message = str_replace(array_keys($replace), array_values($replace), $template);

        return trim(preg_replace("/[ \t]+\n/", "\n", $message) ?? $message);
    }

    /**
     * @param  array<string, mixed>  $pixData
     */
    public function resolvePixCopy(Order $order, array $pixData = []): string
    {
        $meta = is_array($order->metadata) ? $order->metadata : [];
        $candidates = [
            $pixData['copy_paste'] ?? null,
            $pixData['pix_copy_paste'] ?? null,
            $meta['copy_paste'] ?? null,
            $meta['pix_copy_paste'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return trim($candidate);
            }
        }

        return '';
    }
}
