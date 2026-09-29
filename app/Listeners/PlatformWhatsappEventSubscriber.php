<?php

namespace App\Listeners;

use App\Events\KycApproved;
use App\Events\KycRejected;
use App\Events\SellerRegistered;
use App\Events\SellerRejected;
use App\Models\PlatformWhatsappTemplate;
use App\Services\PlatformWhatsapp\PlatformWhatsappDispatcher;
use Illuminate\Contracts\Events\Dispatcher;

class PlatformWhatsappEventSubscriber
{
    public function __construct(private PlatformWhatsappDispatcher $dispatcher) {}

    /**
     * @return array<string, string>
     */
    public function subscribe(Dispatcher $events): array
    {
        return [
            SellerRegistered::class => 'handleSellerRegistered',
            SellerRejected::class => 'handleSellerRejected',
            KycApproved::class => 'handleKycApproved',
            KycRejected::class => 'handleKycRejected',
        ];
    }

    public function handleSellerRegistered(SellerRegistered $event): void
    {
        $this->dispatcher->notify(
            PlatformWhatsappTemplate::EVENT_SELLER_REGISTERED,
            $event->seller->fresh() ?? $event->seller
        );
    }

    public function handleSellerRejected(SellerRejected $event): void
    {
        $this->dispatcher->notify(
            PlatformWhatsappTemplate::EVENT_SELLER_REJECTED,
            $event->seller->fresh() ?? $event->seller,
            ['motivo' => trim((string) ($event->reason ?? ''))]
        );
    }

    public function handleKycApproved(KycApproved $event): void
    {
        $this->dispatcher->notify(
            PlatformWhatsappTemplate::EVENT_KYC_APPROVED,
            $event->seller->fresh() ?? $event->seller
        );
    }

    public function handleKycRejected(KycRejected $event): void
    {
        $this->dispatcher->notify(
            PlatformWhatsappTemplate::EVENT_KYC_REJECTED,
            $event->seller->fresh() ?? $event->seller,
            ['motivo' => $event->reason]
        );
    }
}
