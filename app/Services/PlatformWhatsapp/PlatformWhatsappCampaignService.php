<?php

namespace App\Services\PlatformWhatsapp;

use App\Models\PlatformWhatsappCampaign;
use App\Models\PlatformWhatsappChannel;
use App\Models\PlatformWhatsappOptOut;
use App\Models\PlatformWhatsappTemplate;
use App\Models\User;
use InvalidArgumentException;

class PlatformWhatsappCampaignService
{
    public function __construct(
        private PlatformWhatsappDispatcher $dispatcher,
        private PlatformWhatsappMessageVars $vars,
    ) {}

    /**
     * @param  array{account_status?: string|null, kyc_status?: string|null}  $filters
     * @return list<User>
     */
    public function recipients(array $filters = [], ?int $limit = null): array
    {
        $max = $limit ?? (int) config('platform_whatsapp.campaign.max_recipients', 500);
        $query = User::query()
            ->where('role', User::ROLE_INFOPRODUTOR)
            ->whereNotNull('phone')
            ->where('phone', '!=', '');

        $accountStatus = trim((string) ($filters['account_status'] ?? ''));
        if ($accountStatus !== '') {
            $query->where('account_status', $accountStatus);
        } else {
            $query->where(function ($inner) {
                $inner->whereNull('account_status')
                    ->orWhereNotIn('account_status', ['blocked', 'suspended']);
            });
        }

        $kycStatus = trim((string) ($filters['kyc_status'] ?? ''));
        if ($kycStatus !== '') {
            $query->where('kyc_status', $kycStatus);
        }

        return $query->orderBy('id')->limit($max)->get()->all();
    }

    /**
     * @param  array{account_status?: string|null, kyc_status?: string|null}  $filters
     */
    public function previewCount(array $filters = []): int
    {
        return count($this->eligible($this->recipients($filters)));
    }

    /**
     * @param  array{account_status?: string|null, kyc_status?: string|null}  $filters
     */
    public function launch(User $actor, string $message, int $delaySeconds, array $filters = []): PlatformWhatsappCampaign
    {
        $channel = PlatformWhatsappChannel::current();
        if (! $channel->canSend()) {
            throw new InvalidArgumentException('Conecte o canal WhatsApp da plataforma para disparar.');
        }

        $template = trim($message);
        if ($template === '') {
            throw new InvalidArgumentException('Escreva a mensagem da campanha.');
        }

        $maxLen = (int) config('platform_whatsapp.max_message_length', 1000);
        if (mb_strlen($template) > $maxLen) {
            throw new InvalidArgumentException('Mensagem excede '.$maxLen.' caracteres.');
        }

        $delaySeconds = max(5, min(120, $delaySeconds));
        $sellers = $this->eligible($this->recipients($filters));

        $campaign = PlatformWhatsappCampaign::query()->create([
            'created_by' => $actor->id,
            'status' => PlatformWhatsappCampaign::STATUS_QUEUED,
            'message' => $template,
            'delay_seconds' => $delaySeconds,
            'filters' => $filters,
            'queued_count' => 0,
            'skipped_count' => 0,
        ]);

        $queued = 0;
        foreach ($sellers as $index => $seller) {
            $phone = $this->vars->phoneOf($seller);
            if ($phone === null) {
                continue;
            }
            $rendered = $this->vars->render($template, $this->vars->forSeller($seller));
            if ($this->dispatcher->queue(
                PlatformWhatsappTemplate::EVENT_BROADCAST,
                $phone,
                $rendered,
                (int) $seller->id,
                (int) $campaign->id,
                $index * $delaySeconds
            )) {
                $queued++;
            }
        }

        $campaign->queued_count = $queued;
        $campaign->skipped_count = max(0, count($this->recipients($filters)) - $queued);
        $campaign->status = $queued > 0 ? PlatformWhatsappCampaign::STATUS_QUEUED : PlatformWhatsappCampaign::STATUS_FAILED;
        if ($queued === 0) {
            $campaign->error = 'Nenhum destinatário elegível (telefone válido e sem opt-out).';
        }
        $campaign->save();

        return $campaign;
    }

    /**
     * @param  list<User>  $sellers
     * @return list<User>
     */
    private function eligible(array $sellers): array
    {
        $out = [];
        foreach ($sellers as $seller) {
            $phone = $this->vars->phoneOf($seller);
            if ($phone === null || PlatformWhatsappOptOut::isOptedOut($phone)) {
                continue;
            }
            $out[] = $seller;
        }

        return $out;
    }
}
