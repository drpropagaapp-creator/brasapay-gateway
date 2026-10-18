<?php

namespace App\Services;

use App\Models\OutboundEmail;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

class OutboundEmailRecorder
{
    private const TOKEN_HEADER = 'X-Stacker-Email-Token';

    private static ?string $pendingMailable = null;

    private static ?string $lastToken = null;

    private static ?bool $tableReady = null;

    public static function rememberMailable(string $class): void
    {
        self::$pendingMailable = $class;
    }

    public static function forgetMailable(): void
    {
        self::$pendingMailable = null;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function recordSending(Email $message, array $data = []): void
    {
        try {
            $this->writeSending($message, $data);
        } catch (\Throwable $e) {
            Log::warning('OutboundEmailRecorder: falha ao registrar envio.', [
                'message' => $e->getMessage(),
            ]);
        }
    }

    public function markSent(Email $message): void
    {
        $token = $this->tokenFromMessage($message) ?: self::$lastToken;
        self::$lastToken = null;
        if ($token === null || $token === '') {
            return;
        }

        try {
            if (! $this->tableReady()) {
                return;
            }

            OutboundEmail::query()
                ->where('token', $token)
                ->where('status', OutboundEmail::STATUS_SENDING)
                ->update([
                    'status' => OutboundEmail::STATUS_SENT,
                    'sent_at' => now(),
                ]);
        } catch (\Throwable $e) {
            Log::warning('OutboundEmailRecorder: falha ao marcar envio.', [
                'message' => $e->getMessage(),
            ]);
        }
    }

    public function markLastFailed(\Throwable $e): void
    {
        $token = self::$lastToken;
        self::$lastToken = null;
        if ($token === null || $token === '') {
            return;
        }

        try {
            if (! $this->tableReady()) {
                return;
            }

            OutboundEmail::query()
                ->where('token', $token)
                ->where('status', OutboundEmail::STATUS_SENDING)
                ->update([
                    'status' => OutboundEmail::STATUS_FAILED,
                    'error_message' => mb_substr($e->getMessage(), 0, 2000),
                ]);
        } catch (\Throwable $ignored) {
            Log::warning('OutboundEmailRecorder: falha ao marcar erro de envio.', [
                'message' => $ignored->getMessage(),
            ]);
        }
    }

    public function recordOpen(string $token): void
    {
        if (! $this->tableReady()) {
            return;
        }

        $exists = OutboundEmail::query()->where('token', $token)->exists();
        if (! $exists) {
            return;
        }

        OutboundEmail::query()
            ->where('token', $token)
            ->whereNull('opened_at')
            ->update(['opened_at' => now()]);

        if (Cache::add('outbound-email-open:'.$token, 1, now()->addMinutes(10))) {
            OutboundEmail::query()->where('token', $token)->increment('open_count');
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function writeSending(Email $message, array $data): void
    {
        if (! $this->tableReady()) {
            return;
        }

        if ($this->tokenFromMessage($message) !== null) {
            return;
        }

        $addresses = $this->recipients($message);
        if ($addresses === []) {
            return;
        }

        $token = Str::random(40);
        $message->getHeaders()->addTextHeader(self::TOKEN_HEADER, $token);
        $this->injectOpenPixel($message, $token);
        self::$lastToken = $token;

        $users = $this->usersByEmail($addresses);
        $mailable = self::$pendingMailable;
        $label = $this->labelFor($mailable);
        $subject = mb_substr(trim((string) $message->getSubject()), 0, 255);
        $mailer = isset($data['mailer']) ? mb_substr((string) $data['mailer'], 0, 32) : null;

        foreach ($addresses as $address) {
            $email = trim($address->getAddress());
            $user = $users[strtolower($email)] ?? null;
            $name = trim($address->getName());
            if ($name === '' && $user) {
                $name = (string) $user->name;
            }

            OutboundEmail::query()->create([
                'token' => $token,
                'user_id' => $user?->id,
                'tenant_id' => $user?->tenant_id,
                'recipient_email' => mb_substr($email, 0, 255),
                'recipient_name' => $name !== '' ? mb_substr($name, 0, 255) : null,
                'recipient_role' => $user?->role,
                'subject' => $subject !== '' ? $subject : null,
                'mail_label' => $label,
                'mailable' => $mailable !== null ? mb_substr($mailable, 0, 160) : null,
                'mailer' => $mailer,
                'status' => OutboundEmail::STATUS_SENDING,
            ]);
        }
    }

    private function injectOpenPixel(Email $message, string $token): void
    {
        $html = $message->getHtmlBody();
        if (! is_string($html) || $html === '') {
            return;
        }

        $url = route('email.open', ['token' => $token]);
        $pixel = '<img src="'.e($url).'" width="1" height="1" alt="" style="display:none;max-height:0;overflow:hidden" />';
        if (stripos($html, '</body>') !== false) {
            $html = preg_replace('/<\/body>/i', $pixel.'</body>', $html, 1) ?? ($html.$pixel);
        } else {
            $html .= $pixel;
        }

        $message->html($html);
    }

    /**
     * @return list<Address>
     */
    private function recipients(Email $message): array
    {
        $unique = [];
        foreach ([$message->getTo(), $message->getCc(), $message->getBcc()] as $list) {
            foreach ($list as $address) {
                $email = strtolower(trim($address->getAddress()));
                if ($email === '' || isset($unique[$email])) {
                    continue;
                }
                $unique[$email] = $address;
            }
        }

        return array_values($unique);
    }

    /**
     * @param  list<Address>  $addresses
     * @return array<string, User>
     */
    private function usersByEmail(array $addresses): array
    {
        $emails = array_map(fn (Address $address) => strtolower(trim($address->getAddress())), $addresses);
        $users = User::query()
            ->whereIn(DB::raw('lower(email)'), $emails)
            ->get();

        $map = [];
        foreach ($users as $user) {
            $map[strtolower((string) $user->email)] = $user;
        }

        return $map;
    }

    private function tokenFromMessage(Email $message): ?string
    {
        if (! $message->getHeaders()->has(self::TOKEN_HEADER)) {
            return null;
        }

        $value = trim((string) $message->getHeaders()->get(self::TOKEN_HEADER)?->getBodyAsString());

        return $value !== '' ? $value : null;
    }

    private function labelFor(?string $mailable): string
    {
        $base = $mailable ? class_basename($mailable) : '';

        return match ($base) {
            'AccessGrantedMail' => 'Acesso à compra',
            'CampaignMail' => 'Campanha',
            'PasswordResetMail' => 'Redefinição de senha',
            'VerifyEmailMail' => 'Confirmação de e-mail',
            'TestEmail' => 'E-mail de teste',
            'WelcomeInfoprodutorMail' => 'Boas-vindas do infoprodutor',
            'TeamMemberAccessMail' => 'Acesso de equipe',
            'SubscriptionReminderMail' => 'Lembrete de assinatura',
            'ProductApprovedMail' => 'Produto aprovado',
            'ProductRejectedMail' => 'Produto rejeitado',
            'KycApprovedMail' => 'KYC aprovado',
            'KycRejectedMail' => 'KYC rejeitado',
            'KycSubmittedAdminMail' => 'KYC enviado',
            'RefundRequestSellerMail', 'RefundRequestAdminMail' => 'Pedido de reembolso',
            'RefundDecisionCustomerMail' => 'Decisão de reembolso',
            'WithdrawalFailedAdminMail', 'WithdrawalPayoutErrorAdminMail' => 'Falha de saque',
            'CoproductionInvitationMail' => 'Convite de coprodução',
            'AffiliateEnrollmentApprovedMail' => 'Afiliação aprovada',
            'MedOpenedSellerMail', 'MedOpenedAdminMail' => 'MED aberto',
            'MedResolvedSellerMail', 'MedResolvedAdminMail' => 'MED resolvido',
            'ManualApprovalPinResetAdminMail' => 'PIN de aprovação',
            default => $base !== '' ? trim(preg_replace('/(?<!^)[A-Z]/', ' $0', str_replace('Mail', '', $base)) ?? 'E-mail') : 'E-mail',
        };
    }

    private function tableReady(): bool
    {
        if (self::$tableReady === true) {
            return true;
        }

        if (Schema::hasTable('outbound_emails')) {
            self::$tableReady = true;

            return true;
        }

        return false;
    }
}
