<?php

namespace Tests\Feature;

use App\Events\OrderCompleted;
use App\Jobs\SendPurchaseConfirmationMailJob;
use App\Mail\PurchaseConfirmationMail;
use App\Models\Order;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use App\Services\AccessEmailService;
use App\Services\PlatformTransactionalMailService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class PurchaseConfirmationMailTest extends TestCase
{
    private function platformSmtp(): void
    {
        Setting::set('smtp_host', 'smtp.example.com', null);
        Setting::set('smtp_port', '587', null);
        Setting::set('smtp_username', 'global-user', null);
        Setting::set('smtp_password', encrypt('secret'), null);
        Setting::set('smtp_encryption', 'tls', null);
        Setting::set('email_provider', 'smtp', null);
    }

    /**
     * Produto tipo link de pagamento: antes não recebia e-mail nenhum.
     */
    private function paidOrder(string $type = Product::TYPE_LINK_PAGAMENTO): Order
    {
        $seller = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR, 'name' => 'Loja Teste', 'email' => 'seller@test.com']);
        $seller->forceFill(['tenant_id' => $seller->id])->save();
        $buyer = User::factory()->create(['tenant_id' => $seller->id, 'name' => 'Maria Souza', 'email' => 'buyer@test.com']);
        $product = $this->createTestProduct([
            'tenant_id' => $seller->id,
            'type' => $type,
            'name' => 'Checkup Financeiro',
            'checkout_slug' => 'checkup'.substr(md5((string) microtime(true)), 0, 6),
        ]);

        return Order::create([
            'tenant_id' => $seller->id,
            'user_id' => $buyer->id,
            'product_id' => $product->id,
            'status' => 'completed',
            'amount' => 97.5,
            'email' => 'buyer@test.com',
            'payment_method' => 'pix',
        ]);
    }

    public function test_order_completed_queues_confirmation(): void
    {
        Queue::fake();
        $order = $this->paidOrder();

        event(new OrderCompleted($order));

        Queue::assertPushed(SendPurchaseConfirmationMailJob::class, fn ($job) => $job->orderId === $order->id);
    }

    public function test_job_sends_confirmation_once_with_order_details(): void
    {
        Mail::fake();
        Cache::flush();
        $this->platformSmtp();
        $order = $this->paidOrder();

        $job = new SendPurchaseConfirmationMailJob($order->id);
        $job->handle(app(PlatformTransactionalMailService::class), app(AccessEmailService::class));
        $job->handle(app(PlatformTransactionalMailService::class), app(AccessEmailService::class));

        Mail::assertSent(PurchaseConfirmationMail::class, 1);
        Mail::assertSent(PurchaseConfirmationMail::class, function (PurchaseConfirmationMail $mail) {
            $html = $mail->renderHtml();

            return $mail->hasTo('buyer@test.com')
                && $mail->hasReplyTo('seller@test.com')
                && str_contains($html, 'Checkup Financeiro')
                && str_contains($html, 'R$ 97,50')
                && str_contains($html, 'PIX')
                && str_contains($html, 'Olá, Maria!');
        });
        $this->assertNotEmpty($order->fresh()->metadata['purchase_confirmation_sent_at'] ?? null);
    }

    public function test_job_respects_platform_switch(): void
    {
        Mail::fake();
        $this->platformSmtp();
        Setting::set(SendPurchaseConfirmationMailJob::SETTING_ENABLED, '0', null);
        $order = $this->paidOrder();

        (new SendPurchaseConfirmationMailJob($order->id))
            ->handle(app(PlatformTransactionalMailService::class), app(AccessEmailService::class));

        Mail::assertNothingSent();
    }

    public function test_payment_method_labels(): void
    {
        $this->assertSame('Cartão de crédito', SendPurchaseConfirmationMailJob::paymentMethodLabel('card'));
        $this->assertSame('PIX Automático', SendPurchaseConfirmationMailJob::paymentMethodLabel('pix_auto'));
        $this->assertSame('Google Pay', SendPurchaseConfirmationMailJob::paymentMethodLabel('google_pay'));
    }
}
