<?php

namespace App\Services\Meta;

class MetaEventContext
{
    /**
     * @param  array<int, string>  $externalIds
     * @param  array<int, string>  $contentIds
     * @param  array<int, array{id: string, quantity: int, item_price?: float}>  $contents
     */
    public function __construct(
        public readonly ?string $fbp = null,
        public readonly ?string $fbc = null,
        public readonly ?string $clientIp = null,
        public readonly ?string $clientUserAgent = null,
        public readonly ?string $eventSourceUrl = null,
        public readonly ?string $email = null,
        public readonly ?string $phone = null,
        public readonly ?string $firstName = null,
        public readonly ?string $lastName = null,
        public readonly ?string $city = null,
        public readonly ?string $state = null,
        public readonly ?string $country = null,
        public readonly ?string $zip = null,
        public readonly ?string $externalId = null,
        public readonly array $externalIds = [],
        public readonly ?string $cpf = null,
        public readonly ?string $subscriptionId = null,
        public readonly ?float $value = null,
        public readonly string $currency = 'BRL',
        public readonly array $contentIds = [],
        public readonly ?string $contentName = null,
        public readonly array $contents = [],
        public readonly ?string $orderId = null,
        public readonly ?string $customerSegmentation = null,
        public readonly ?int $originalPurchaseEventTime = null,
        public readonly int $numItems = 1,
        public readonly ?int $eventTime = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'fbp' => $this->fbp,
            'fbc' => $this->fbc,
            'client_ip' => $this->clientIp,
            'client_user_agent' => $this->clientUserAgent,
            'event_source_url' => $this->eventSourceUrl,
            'email' => $this->email,
            'phone' => $this->phone,
            'first_name' => $this->firstName,
            'last_name' => $this->lastName,
            'city' => $this->city,
            'state' => $this->state,
            'country' => $this->country,
            'zip' => $this->zip,
            'external_id' => $this->externalId,
            'external_ids' => $this->externalIds,
            'subscription_id' => $this->subscriptionId,
            'value' => $this->value,
            'currency' => $this->currency,
            'content_ids' => $this->contentIds,
            'content_name' => $this->contentName,
            'contents' => $this->contents,
            'order_id' => $this->orderId,
            'customer_segmentation' => $this->customerSegmentation,
            'num_items' => $this->numItems,
            'event_time' => $this->eventTime,
        ];
    }
}
