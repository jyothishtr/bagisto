<?php

namespace Webkul\BagistoApi\Models;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\GraphQl\QueryCollection;
use Webkul\BagistoApi\Dto\CustomerOrderOutput;
use Webkul\BagistoApi\State\CustomerOrdersProvider;
use Webkul\Sales\Models\Order as OrderModel;

/**
 * API resource for authenticated customer order history.
 */
#[ApiResource(
    routePrefix: '/api/shop',
    shortName: 'CustomerOrder',
    uriTemplate: '/customer-orders',
    normalizationContext: [
        'groups' => ['query'],
    ],
    operations: [
        new GetCollection(
            provider: CustomerOrdersProvider::class,
            output: CustomerOrderOutput::class,
        ),
    ],
    graphQlOperations: [
        new QueryCollection(
            provider: CustomerOrdersProvider::class,
            output: CustomerOrderOutput::class,
            args: [
                'token' => [
                    'type'        => 'String',
                    'description' => 'Customer auth token (optional when Authorization header is provided).',
                ],
                'status' => [
                    'type'        => 'String',
                    'description' => 'Optional order status filter.',
                ],
                'first' => [
                    'type'        => 'Int',
                    'description' => 'Number of items to return from the start.',
                ],
                'after' => [
                    'type'        => 'String',
                    'description' => 'Cursor to start pagination after.',
                ],
                'last' => [
                    'type'        => 'Int',
                    'description' => 'Number of items to return from the end.',
                ],
                'before' => [
                    'type'        => 'String',
                    'description' => 'Cursor to start pagination before.',
                ],
            ],
            paginationEnabled: true,
            paginationType: 'cursor',
            paginationPartial: false,
            normalizationContext: [
                'groups' => ['query'],
            ],
            description: 'Get authenticated customer order history.',
        ),
    ]
)]
class CustomerOrder extends OrderModel
{
    /**
     * Reuse core order table instead of inferring "customer_orders".
     */
    protected $table = 'orders';

    // Shape is controlled by CustomerOrderOutput DTO.
}
