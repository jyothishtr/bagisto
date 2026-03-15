<?php

namespace Webkul\BagistoApi\State;

use ApiPlatform\Laravel\Eloquent\Paginator;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Request;
use Webkul\BagistoApi\Exception\AuthenticationException;
use Webkul\BagistoApi\Facades\TokenHeaderFacade;
use Webkul\BagistoApi\Models\CustomerOrder;
use Webkul\Customer\Models\Customer;

/**
 * Provides authenticated customer order history.
 */
class CustomerOrdersProvider implements ProviderInterface
{
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): object|array|null
    {
        $request = Request::instance() ?? ($context['request'] ?? null);
        $args = is_array($context['args'] ?? null) ? $context['args'] : [];

        // Prefer explicit GraphQL token argument; fallback to Authorization Bearer token.
        $tokenFromArgs = isset($args['token']) && is_string($args['token']) ? trim($args['token']) : null;
        $tokenFromArgs = $tokenFromArgs !== '' ? $tokenFromArgs : null;

        $token = $tokenFromArgs ?: ($request ? TokenHeaderFacade::getAuthorizationBearerToken($request) : null);

        if (! $token) {
            throw new AuthenticationException(__('bagistoapi::app.graphql.auth.no-token-provided'));
        }

        $authenticatedCustomerId = $this->getCustomerIdFromToken($token);

        if ($authenticatedCustomerId === null) {
            throw new AuthenticationException(__('bagistoapi::app.graphql.auth.invalid-or-expired-token'));
        }

        $status = isset($args['status']) && is_string($args['status']) ? trim($args['status']) : null;
        $status = $status !== '' ? $status : null;

        $first = isset($args['first']) && is_numeric($args['first']) ? (int) $args['first'] : null;
        $last = isset($args['last']) && is_numeric($args['last']) ? (int) $args['last'] : null;
        $after = isset($args['after']) && is_string($args['after']) ? trim($args['after']) : null;
        $after = $after !== '' ? $after : null;
        $before = isset($args['before']) && is_string($args['before']) ? trim($args['before']) : null;
        $before = $before !== '' ? $before : null;

        $query = CustomerOrder::query()
            ->where('customer_id', $authenticatedCustomerId)
            ->orderByDesc('id');

        if ($status) {
            $query->where('status', $status);
        }

        // Cursor support: numeric ID, or base64-encoded cursor containing a numeric ID.
        if ($after) {
            $cursorId = $this->extractCursorId($after);

            if ($cursorId !== null) {
                $query->where('id', '<', $cursorId);
            }
        }

        if ($before) {
            $cursorId = $this->extractCursorId($before);

            if ($cursorId !== null) {
                $query->where('id', '>', $cursorId);
            }
        }

        $perPage = $first ?? $last ?? 10;
        $perPage = max(1, min($perPage, 100));

        $paginator = $query->paginate($perPage);

        $mappedCollection = $paginator->getCollection()
            ->map(fn (CustomerOrder $order): array => $this->mapOrderToOutput($order));

        $paginator->setCollection($mappedCollection);

        return new Paginator($paginator);
    }

    /**
     * Validate Sanctum token and extract customer ID.
     */
    private function getCustomerIdFromToken(string $token): ?int
    {
        try {
            $tokenParts = explode('|', $token, 2);

            if (count($tokenParts) !== 2) {
                return null;
            }

            [$tokenId, $plainTextToken] = $tokenParts;

            $personalAccessToken = DB::table('personal_access_tokens')
                ->where('id', $tokenId)
                ->where('tokenable_type', Customer::class)
                ->where(function ($query) {
                    $query->whereNull('expires_at')
                        ->orWhere('expires_at', '>', now());
                })
                ->first();

            if (! $personalAccessToken) {
                return null;
            }

            if (! hash_equals((string) $personalAccessToken->token, hash('sha256', $plainTextToken))) {
                return null;
            }

            $customer = Customer::find($personalAccessToken->tokenable_id);

            if (! $customer || $customer->is_suspended) {
                return null;
            }

            return (int) $customer->id;
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * Parse cursor formats like "123", base64("123"), or base64("cursor:123").
     */
    private function extractCursorId(string $after): ?int
    {
        if (ctype_digit($after)) {
            return (int) $after;
        }

        $decoded = base64_decode($after, true);

        if (! is_string($decoded) || $decoded === '') {
            return null;
        }

        if (ctype_digit($decoded)) {
            return (int) $decoded;
        }

        if (preg_match('/(\d+)\s*$/', $decoded, $matches)) {
            return (int) $matches[1];
        }

        return null;
    }

    /**
     * Map order model to GraphQL output DTO.
     */
    private function mapOrderToOutput(CustomerOrder $order): array
    {
        return [
            'id'               => (string) $order->id,
            '_id'              => (string) $order->id,
            'incrementId'      => $order->increment_id !== null ? (string) $order->increment_id : null,
            'status'           => $order->status !== null ? (string) $order->status : null,
            'channelName'      => $order->channel_name !== null ? (string) $order->channel_name : null,
            'customerEmail'    => $order->customer_email !== null ? (string) $order->customer_email : null,
            'customerFirstName'=> $order->customer_first_name !== null ? (string) $order->customer_first_name : null,
            'customerLastName' => $order->customer_last_name !== null ? (string) $order->customer_last_name : null,
            'totalItemCount'   => $order->total_item_count !== null ? (int) $order->total_item_count : null,
            'totalQtyOrdered'  => $order->total_qty_ordered !== null ? (float) $order->total_qty_ordered : null,
            'grandTotal'       => $order->grand_total !== null ? (float) $order->grand_total : null,
            'baseGrandTotal'   => $order->base_grand_total !== null ? (float) $order->base_grand_total : null,
            'subTotal'         => $order->sub_total !== null ? (float) $order->sub_total : null,
            'taxAmount'        => $order->tax_amount !== null ? (float) $order->tax_amount : null,
            'discountAmount'   => $order->discount_amount !== null ? (float) $order->discount_amount : null,
            'shippingAmount'   => $order->shipping_amount !== null ? (float) $order->shipping_amount : null,
            'shippingTitle'    => $order->shipping_title !== null ? (string) $order->shipping_title : null,
            'couponCode'       => $order->coupon_code !== null ? (string) $order->coupon_code : null,
            'orderCurrencyCode'=> $order->order_currency_code !== null ? (string) $order->order_currency_code : null,
            'baseCurrencyCode' => $order->base_currency_code !== null ? (string) $order->base_currency_code : null,
            'createdAt'        => $order->created_at?->toDateTimeString(),
            'updatedAt'        => $order->updated_at?->toDateTimeString(),
        ];
    }
}
