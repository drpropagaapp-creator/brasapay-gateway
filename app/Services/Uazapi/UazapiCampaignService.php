<?php

namespace App\Services\Uazapi;

use App\Models\UazapiCampaign;
use App\Models\UazapiInstance;
use InvalidArgumentException;

class UazapiCampaignService
{
    public function __construct(
        private UazapiCampaignAudience $audience,
        private UazapiDispatcher $dispatcher,
        private UazapiMessageBuilder $messageBuilder,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function launch(
        UazapiInstance $instance,
        string $audience,
        string $message,
        bool $includeImage
    ): UazapiCampaign {
        if (! $instance->canSendRecovery()) {
            throw new InvalidArgumentException('Conecte o WhatsApp e mantenha a integração ativa para disparar.');
        }

        if (! in_array($audience, UazapiCampaign::audiences(), true)) {
            throw new InvalidArgumentException('Público da campanha inválido.');
        }

        $template = trim($message);
        if ($template === '') {
            throw new InvalidArgumentException('Escreva a mensagem da campanha.');
        }

        $maxLen = (int) config('uazapi.max_message_length', 1000);
        if (mb_strlen($template) > $maxLen) {
            throw new InvalidArgumentException('Mensagem excede '.$maxLen.' caracteres.');
        }

        $recipients = $this->audience->recipients((int) $instance->tenant_id, $audience, $instance);
        $campaign = UazapiCampaign::query()->create([
            'tenant_id' => $instance->tenant_id,
            'uazapi_instance_id' => $instance->id,
            'audience' => $audience,
            'message' => $template,
            'include_image' => $includeImage,
            'status' => UazapiCampaign::STATUS_QUEUED,
            'queued_count' => 0,
            'skipped_count' => 0,
        ]);

        $delayStep = (int) config('uazapi.campaign.delay_seconds', 8);
        $queued = 0;
        $label = $audience === UazapiCampaign::AUDIENCE_BUYERS
            ? null
            : UazapiLabelService::ABANDONED;

        foreach ($recipients as $index => $recipient) {
            $rendered = $this->messageBuilder->render($template, $recipient['vars']);
            $extra = [
                'button_url' => $recipient['vars']['link'] ?? '',
                'button_label' => $audience === UazapiCampaign::AUDIENCE_BUYERS ? 'Acessar' : 'Finalizar compra',
            ];
            if ($includeImage && ! empty($recipient['image_url'])) {
                $extra['image_url'] = $recipient['image_url'];
            }
            if ($label) {
                $extra['label'] = $label;
            }

            if ($this->dispatcher->dispatchCampaignMessage(
                $instance,
                $campaign,
                $recipient['phone'],
                $rendered,
                $recipient['checkout_session_id'],
                $recipient['order_id'],
                $extra,
                $index * max(1, $delayStep)
            )) {
                $queued++;
            }
        }

        $campaign->queued_count = $queued;
        $campaign->skipped_count = max(0, count($recipients) - $queued);
        $campaign->status = $queued > 0 ? UazapiCampaign::STATUS_QUEUED : UazapiCampaign::STATUS_FAILED;
        if ($queued === 0) {
            $campaign->error = 'Nenhum destinatário elegível (telefone válido e sem opt-out).';
        }
        $campaign->save();

        return $campaign;
    }
}
