<?php

namespace Webkul\BagistoApi\Dto;

use ApiPlatform\Metadata\ApiProperty;
use Symfony\Component\Serializer\Annotation\Groups;

/**
 * CustomerOrderOutput - GraphQL output DTO for customer order listing.
 */
class CustomerOrderOutput
{
    #[Groups(['query'])]
    #[ApiProperty(identifier: true, readable: true, writable: false)]
    public ?string $id = null;

    #[Groups(['query'])]
    #[ApiProperty(readable: true, writable: false)]
    public ?string $_id = null;

    #[Groups(['query'])]
    #[ApiProperty(readable: true, writable: false)]
    public ?string $incrementId = null;

    #[Groups(['query'])]
    #[ApiProperty(readable: true, writable: false)]
    public ?string $status = null;

    #[Groups(['query'])]
    #[ApiProperty(readable: true, writable: false)]
    public ?string $channelName = null;

    #[Groups(['query'])]
    #[ApiProperty(readable: true, writable: false)]
    public ?string $customerEmail = null;

    #[Groups(['query'])]
    #[ApiProperty(readable: true, writable: false)]
    public ?string $customerFirstName = null;

    #[Groups(['query'])]
    #[ApiProperty(readable: true, writable: false)]
    public ?string $customerLastName = null;

    #[Groups(['query'])]
    #[ApiProperty(readable: true, writable: false)]
    public ?int $totalItemCount = null;

    #[Groups(['query'])]
    #[ApiProperty(readable: true, writable: false)]
    public ?float $totalQtyOrdered = null;

    #[Groups(['query'])]
    #[ApiProperty(readable: true, writable: false)]
    public ?float $grandTotal = null;

    #[Groups(['query'])]
    #[ApiProperty(readable: true, writable: false)]
    public ?float $baseGrandTotal = null;

    #[Groups(['query'])]
    #[ApiProperty(readable: true, writable: false)]
    public ?float $subTotal = null;

    #[Groups(['query'])]
    #[ApiProperty(readable: true, writable: false)]
    public ?float $taxAmount = null;

    #[Groups(['query'])]
    #[ApiProperty(readable: true, writable: false)]
    public ?float $discountAmount = null;

    #[Groups(['query'])]
    #[ApiProperty(readable: true, writable: false)]
    public ?float $shippingAmount = null;

    #[Groups(['query'])]
    #[ApiProperty(readable: true, writable: false)]
    public ?string $shippingTitle = null;

    #[Groups(['query'])]
    #[ApiProperty(readable: true, writable: false)]
    public ?string $couponCode = null;

    #[Groups(['query'])]
    #[ApiProperty(readable: true, writable: false)]
    public ?string $orderCurrencyCode = null;

    #[Groups(['query'])]
    #[ApiProperty(readable: true, writable: false)]
    public ?string $baseCurrencyCode = null;

    #[Groups(['query'])]
    #[ApiProperty(readable: true, writable: false)]
    public ?string $createdAt = null;

    #[Groups(['query'])]
    #[ApiProperty(readable: true, writable: false)]
    public ?string $updatedAt = null;
}
