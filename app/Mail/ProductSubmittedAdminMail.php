<?php

namespace App\Mail;

use App\Models\Product;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class ProductSubmittedAdminMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  array{app_name: string, theme_primary: string, logo_url: ?string}  $branding
     */
    public function __construct(
        public Product $product,
        public ?User $seller,
        public array $branding,
        public string $reviewUrl,
        public bool $resubmitted = false,
    ) {
        $this->subject(
            ($this->resubmitted ? 'Produto reenviado para análise' : 'Novo produto para análise')
            .' — '.$this->branding['app_name']
        );
    }

    public function build(): self
    {
        return $this->view('emails.product-submitted-admin', [
            'branding' => $this->branding,
            'productName' => $this->product->name,
            'sellerName' => $this->seller?->name ?: 'Infoprodutor',
            'sellerEmail' => $this->seller?->email,
            'reviewUrl' => $this->reviewUrl,
            'resubmitted' => $this->resubmitted,
        ]);
    }
}
