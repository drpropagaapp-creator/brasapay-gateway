<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureInstalled;
use App\Mail\LoggingMailer;
use App\Mail\TestEmail;
use App\Models\OutboundEmail;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\RawMessage;
use Tests\TestCase;

class OutboundEmailHistoryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([
            EnsureInstalled::class,
        ]);
    }

    public function test_sent_email_is_logged_and_open_pixel_marks_it_opened(): void
    {
        $buyer = User::factory()->create([
            'role' => User::ROLE_CLIENTE,
            'tenant_id' => 1,
            'name' => 'Maria Compradora',
            'email' => 'maria.compradora@example.com',
        ]);

        Mail::to($buyer->email)->send(new TestEmail('Acesso liberado', '<p>Olá</p>'));

        $log = OutboundEmail::query()->where('recipient_email', $buyer->email)->first();
        $this->assertNotNull($log);
        $this->assertSame(OutboundEmail::STATUS_SENT, $log->status);
        $this->assertSame('Maria Compradora', $log->recipient_name);
        $this->assertSame(User::ROLE_CLIENTE, $log->recipient_role);
        $this->assertSame('E-mail de teste', $log->mail_label);
        $this->assertSame('Acesso liberado', $log->subject);
        $this->assertNull($log->opened_at);

        $transport = Mail::mailer()->getSymfonyTransport();
        $html = (string) $transport->messages()->last()->getOriginalMessage()->getHtmlBody();
        $this->assertStringContainsString('/email/abertura/'.$log->token, $html);

        $this->get('/email/abertura/'.$log->token)
            ->assertOk()
            ->assertHeader('content-type', 'image/gif');

        $log->refresh();
        $this->assertNotNull($log->opened_at);
        $this->assertSame(1, $log->open_count);
    }

    public function test_failed_send_is_stored_as_failure(): void
    {
        Mail::extend('failing', function () {
            return new class implements TransportInterface
            {
                public function send(RawMessage $message, ?\Symfony\Component\Mailer\Envelope $envelope = null): ?SentMessage
                {
                    throw new TransportException('smtp recusou a mensagem');
                }

                public function __toString(): string
                {
                    return 'failing';
                }
            };
        });

        config(['mail.mailers.failing' => ['transport' => 'failing']]);

        try {
            Mail::mailer('failing')->to('falha@example.com')->send(new TestEmail('Falhou', '<p>x</p>'));
            $this->fail('O envio deveria ter falhado.');
        } catch (TransportException $e) {
            $this->assertStringContainsString('smtp recusou', $e->getMessage());
        }

        $this->assertDatabaseHas('outbound_emails', [
            'recipient_email' => 'falha@example.com',
            'status' => OutboundEmail::STATUS_FAILED,
        ]);
    }

    public function test_platform_admin_sees_sent_emails_inside_email_center(): void
    {
        $this->assertInstanceOf(LoggingMailer::class, Mail::mailer());

        $admin = User::factory()->create([
            'role' => User::ROLE_PLATFORM_ADMIN,
            'tenant_id' => null,
        ]);

        OutboundEmail::query()->create([
            'token' => str_repeat('a', 40),
            'recipient_email' => 'seller@example.com',
            'recipient_name' => 'João Seller',
            'recipient_role' => User::ROLE_INFOPRODUTOR,
            'subject' => 'Produto aprovado',
            'mail_label' => 'Produto aprovado',
            'status' => OutboundEmail::STATUS_SENT,
            'sent_at' => now(),
        ]);

        $this->actingAs($admin)
            ->get('/plataforma/email-marketing?aba=enviados')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('EmailMarketing/Index')
                ->where('section', 'enviados')
                ->where('sent_emails.data.0.recipient_email', 'seller@example.com')
                ->where('sent_emails.data.0.recipient_role', 'Infoprodutor')
                ->where('sent_emails.data.0.status', 'sent')
            );
    }

    public function test_seller_cannot_open_email_center(): void
    {
        $seller = User::factory()->create([
            'role' => User::ROLE_INFOPRODUTOR,
            'tenant_id' => 1,
        ]);

        $this->actingAs($seller)
            ->get('/plataforma/email-marketing')
            ->assertForbidden();
    }
}
