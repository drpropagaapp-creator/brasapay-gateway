<?php

namespace App\Mail;

use App\Support\EmailLogoHtml;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * Confirmação de compra padrão da plataforma, enviada ao comprador em todo pedido pago.
 * Texto fixo da plataforma (o seller não edita): por isso pode sair pelo e-mail da BrasaPay.
 */
class PurchaseConfirmationMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  array{
     *     app_name: string,
     *     logo_url: ?string,
     *     customer_name: ?string,
     *     product_name: string,
     *     amount: string,
     *     payment_method: string,
     *     order_reference: string,
     *     paid_at: string,
     *     seller_name: ?string,
     *     seller_email: ?string
     * }  $data
     */
    public function __construct(public array $data)
    {
        $this->subject('Compra confirmada: '.$data['product_name']);
        // Resposta do comprador vai para o seller, não para a caixa da plataforma.
        $sellerEmail = trim((string) ($data['seller_email'] ?? ''));
        if ($sellerEmail !== '' && filter_var($sellerEmail, FILTER_VALIDATE_EMAIL)) {
            $this->replyTo($sellerEmail, ($data['seller_name'] ?? null) ?: null);
        }
    }

    public function build()
    {
        return $this->html($this->renderHtml());
    }

    public function renderHtml(): string
    {
        $d = $this->data;
        $greeting = ($d['customer_name'] ?? '') !== ''
            ? 'Olá, '.e($this->firstName((string) $d['customer_name'])).'!'
            : 'Olá!';

        $rows = [
            'Produto' => $d['product_name'],
            'Valor' => $d['amount'],
            'Forma de pagamento' => $d['payment_method'],
            'Pedido' => $d['order_reference'],
            'Data' => $d['paid_at'],
        ];
        $table = '';
        foreach ($rows as $label => $value) {
            $table .= '<tr>'
                .'<td style="padding:10px 0;border-bottom:1px solid #e4e4e7;font-size:14px;color:#71717a;">'.e($label).'</td>'
                .'<td style="padding:10px 0;border-bottom:1px solid #e4e4e7;font-size:14px;color:#18181b;font-weight:600;text-align:right;">'.e($value).'</td>'
                .'</tr>';
        }

        $seller = trim((string) ($d['seller_name'] ?? ''));
        $sellerEmail = trim((string) ($d['seller_email'] ?? ''));
        $support = '';
        if ($sellerEmail !== '') {
            $who = $seller !== '' ? e($seller) : 'o vendedor';
            $support = '<p style="margin:24px 0 0;padding:16px 20px;background:#f4f4f5;border-radius:8px;font-size:13px;line-height:1.5;color:#52525b;">'
                .'Dúvidas sobre o produto ou o acesso? Fale com '.$who.': '
                .'<a href="mailto:'.e($sellerEmail).'" style="color:#0ea5e9;text-decoration:underline;">'.e($sellerEmail).'</a>'
                .'</p>';
        }

        $soldBy = $seller !== '' ? ' Vendido por '.e($seller).'.' : '';
        $logo = ($d['logo_url'] ?? null) ? EmailLogoHtml::wrap((string) $d['logo_url']) : '';

        $body = '<div style="max-width:560px;margin:0 auto;padding:32px 24px;font-family:Arial,Helvetica,sans-serif;color:#18181b;">'
            .$logo
            .'<h1 style="margin:0 0 8px;font-size:22px;line-height:1.3;color:#18181b;">Compra confirmada</h1>'
            .'<p style="margin:0 0 20px;font-size:15px;line-height:1.5;color:#3f3f46;">'.$greeting
            .' Seu pagamento foi aprovado. Guarde este e-mail como comprovante.</p>'
            .'<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;">'.$table.'</table>'
            .$support
            .'<p style="margin:28px 0 0;font-size:12px;line-height:1.5;color:#a1a1aa;">Pagamento processado por '.e($d['app_name']).'.'.$soldBy
            .' Se você não reconhece esta compra, responda este e-mail para falar com o vendedor.</p>'
            .'</div>';

        return '<!DOCTYPE html><html lang="pt-BR"><head><meta charset="utf-8">'
            .'<meta name="viewport" content="width=device-width">'
            .'<meta name="color-scheme" content="light only"><meta name="supported-color-schemes" content="light">'
            .'</head><body style="margin:0;padding:0;background-color:#ffffff !important;">'.$body.'</body></html>';
    }

    private function firstName(string $name): string
    {
        $first = preg_split('/\s+/', trim($name))[0] ?? '';

        return $first !== '' ? $first : trim($name);
    }
}
