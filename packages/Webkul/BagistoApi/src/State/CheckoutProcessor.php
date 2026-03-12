<?php

namespace Webkul\BagistoApi\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use Illuminate\Support\Facades\Request;
use Webkul\BagistoApi\Dto\CartData;
use Webkul\BagistoApi\Dto\CheckoutAddressInput;
use Webkul\BagistoApi\Dto\CheckoutAddressOutput;
use Webkul\BagistoApi\Exception\AuthenticationException;
use Webkul\BagistoApi\Exception\OperationFailedException;
use Webkul\BagistoApi\Exception\ResourceNotFoundException;
use Webkul\BagistoApi\Facades\CartTokenFacade;
use Webkul\BagistoApi\Facades\TokenHeaderFacade;
use Webkul\Checkout\Facades\Cart;
use Webkul\Checkout\Models\CartAddress;
use Webkul\Checkout\Repositories\CartRepository;
use Webkul\Customer\Repositories\CustomerRepository;
use Webkul\Sales\Repositories\OrderRepository;

/**
 * Handles checkout operations including address, shipping, payment, and order creation.
 */
class CheckoutProcessor implements ProcessorInterface
{
    public function __construct(
        protected CustomerRepository $customerRepository,
        protected OrderRepository $orderRepository,
        protected CartRepository $cartRepository
    ) {}

    /**
     * Process checkout operation.
     */
    public function process(
        mixed $data,
        Operation $operation,
        array $uriVariables = [],
        array $context = []
    ): mixed {
        $request = Request::instance() ?? ($context['request'] ?? null);
        $operationName = $this->mapOperation($operation);

        if ($operationName === 'read') {
            // Extract token from Authorization header only (no context/input parameters)
            $token = TokenHeaderFacade::getAuthorizationBearerToken($request);

            if (! $token) {
                throw new AuthenticationException(__('bagistoapi::app.graphql.cart.authentication-required'));
            }

            $cart = CartTokenFacade::getCartByToken($token);

            if (! $cart) {
                throw new ResourceNotFoundException(__('bagistoapi::app.graphql.cart.invalid-token'));
            }

            return $this->fetchAddresses($cart);
        }

        if (! $data instanceof CheckoutAddressInput) {
            throw new OperationFailedException(__('bagistoapi::app.graphql.checkout.invalid-input'));
        }

        $this->hydrateInputFromArgs($data, $context['args'] ?? []);

        // Extract token from Authorization header (Bearer token) via TokenHeaderFacade
        // Token is NOT a DTO property - it's extracted from Authorization header only
        $token = null;
        if ($request) {
            $token = TokenHeaderFacade::getAuthorizationBearerToken($request);
        }

        if (! $token) {
            throw new AuthenticationException(__('bagistoapi::app.graphql.cart.authentication-required'));
        }

        $cart = CartTokenFacade::getCartByToken($token);

        if (! $cart) {
            throw new ResourceNotFoundException(__('bagistoapi::app.graphql.cart.invalid-token'));
        }

        return match ($operationName) {
            'saveAddress'        => $this->saveAddress($cart, $data),
            'saveShippingMethod' => $this->saveShippingMethod($cart, $data),
            'savePaymentMethod'  => $this->savePaymentMethod($cart, $data),
            'createOrder'        => $this->createOrder($cart, $data),
            default              => throw new OperationFailedException(__('bagistoapi::app.graphql.checkout.unknown-operation', ['operation' => $operationName])),
        };
    }

    /**
     * Map BagistoApi operation name to internal operation type
     */
    private function mapOperation(Operation $operation): string
    {
        $operationName = $operation->getName();
        $resourceClass = $operation->getClass();
        $resourceClassName = $resourceClass ? class_basename($resourceClass) : '';

        return match ($resourceClassName) {
            'CheckoutAddress'        => 'saveAddress',
            'CheckoutShippingMethod' => 'saveShippingMethod',
            'CheckoutPaymentMethod'  => 'savePaymentMethod',
            'CheckoutOrder'          => 'createOrder',
            default                  => $operationName,
        };
    }

    /**
     * Fallback hydration for GraphQL mutations where API Platform may not bind
     * input payload to CheckoutAddressInput reliably.
     */
    private function hydrateInputFromArgs(CheckoutAddressInput $input, mixed $args): void
    {
        $args = $this->normalizeArgs($args);

        if ($args === []) {
            return;
        }

        $fieldAliases = [
            'billingFirstName'   => ['billingFirstName', 'billing_first_name'],
            'billingLastName'    => ['billingLastName', 'billing_last_name'],
            'billingEmail'       => ['billingEmail', 'billing_email'],
            'billingCompanyName' => ['billingCompanyName', 'billing_company_name'],
            'billingAddress'     => ['billingAddress', 'billing_address'],
            'billingCountry'     => ['billingCountry', 'billing_country'],
            'billingState'       => ['billingState', 'billing_state'],
            'billingCity'        => ['billingCity', 'billing_city'],
            'billingPostcode'    => ['billingPostcode', 'billing_postcode'],
            'billingPhoneNumber' => ['billingPhoneNumber', 'billing_phone_number'],
            'shippingFirstName'  => ['shippingFirstName', 'shipping_first_name'],
            'shippingLastName'   => ['shippingLastName', 'shipping_last_name'],
            'shippingEmail'      => ['shippingEmail', 'shipping_email'],
            'shippingCompanyName'=> ['shippingCompanyName', 'shipping_company_name'],
            'shippingAddress'    => ['shippingAddress', 'shipping_address'],
            'shippingCountry'    => ['shippingCountry', 'shipping_country'],
            'shippingState'      => ['shippingState', 'shipping_state'],
            'shippingCity'       => ['shippingCity', 'shipping_city'],
            'shippingPostcode'   => ['shippingPostcode', 'shipping_postcode'],
            'shippingPhoneNumber'=> ['shippingPhoneNumber', 'shipping_phone_number'],
            'useForShipping'     => ['useForShipping', 'use_for_shipping'],
            'shippingMethod'     => ['shippingMethod', 'shipping_method'],
            'paymentMethod'      => ['paymentMethod', 'payment_method'],
            'paymentSuccessUrl'  => ['paymentSuccessUrl', 'payment_success_url'],
            'paymentFailureUrl'  => ['paymentFailureUrl', 'payment_failure_url'],
            'paymentCancelUrl'   => ['paymentCancelUrl', 'payment_cancel_url'],
        ];

        foreach ($fieldAliases as $property => $aliases) {
            if ($this->hasValue($input->{$property})) {
                continue;
            }

            $value = $this->findValueRecursively($args, $aliases);

            if ($value === null) {
                continue;
            }

            $input->{$property} = $this->castInputValue($property, $value);
        }
    }

    /**
     * Normalize GraphQL args payload to array recursively.
     */
    private function normalizeArgs(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if ($value instanceof \ArrayObject) {
            return $value->getArrayCopy();
        }

        if ($value instanceof \stdClass) {
            return (array) $value;
        }

        return [];
    }

    /**
     * Recursively find a value by alias in args payload.
     */
    private function findValueRecursively(array $args, array $aliases): mixed
    {
        foreach ($aliases as $alias) {
            if (array_key_exists($alias, $args)) {
                return $args[$alias];
            }
        }

        foreach ($args as $value) {
            $nested = $this->normalizeArgs($value);

            if ($nested === []) {
                continue;
            }

            $found = $this->findValueRecursively($nested, $aliases);

            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }

    /**
     * Determine whether a DTO property already has a meaningful value.
     */
    private function hasValue(mixed $value): bool
    {
        return $value !== null;
    }

    /**
     * Cast values from GraphQL args into CheckoutAddressInput property types.
     */
    private function castInputValue(string $property, mixed $value): mixed
    {
        if ($property === 'useForShipping') {
            return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        }

        return is_scalar($value) ? (string) $value : null;
    }

    /**
     * Save billing and shipping addresses for cart.
     */
    private function saveAddress($cart, CheckoutAddressInput $input)
    {
        try {
            if (! $input->billingFirstName && ! $input->billingAddress) {
                throw new OperationFailedException(__('bagistoapi::app.graphql.checkout.billing-address-required'));
            }

            if ($cart->haveStockableItems()) {
                $hasShippingData = $input->shippingFirstName || $input->shippingAddress || $input->useForShipping;
                if (! $hasShippingData) {
                    throw new OperationFailedException(__('bagistoapi::app.graphql.checkout.shipping-address-required'));
                }
            }

            $billingAddress = null;
            $shippingAddress = null;

            $cart->billing_address()->delete();
            $cart->shipping_address()->delete();

            if ($input->billingFirstName || $input->billingAddress) {
                $billingAddress = new CartAddress;
                $billingAddress->cart_id = $cart->id;
                $billingAddress->address_type = CartAddress::ADDRESS_TYPE_BILLING;
                $billingAddress->first_name = $input->billingFirstName;
                $billingAddress->last_name = $input->billingLastName;
                $billingAddress->email = $input->billingEmail;
                $billingAddress->company_name = $input->billingCompanyName;
                $billingAddress->address = $input->billingAddress;
                $billingAddress->country = $input->billingCountry;
                $billingAddress->state = $input->billingState;
                $billingAddress->city = $input->billingCity;
                $billingAddress->postcode = $input->billingPostcode;
                $billingAddress->phone = $input->billingPhoneNumber;
                $billingAddress->save();

                if ($input->billingEmail && ! $cart->customer_email) {
                    $cart->customer_email = $input->billingEmail;
                    $cart->save();
                }
            }

            if ($input->useForShipping && $billingAddress !== null) {
                $shippingAddress = new CartAddress;
                $shippingAddress->cart_id = $cart->id;
                $shippingAddress->address_type = CartAddress::ADDRESS_TYPE_SHIPPING;
                $shippingAddress->first_name = $input->billingFirstName;
                $shippingAddress->last_name = $input->billingLastName;
                $shippingAddress->email = $input->billingEmail;
                $shippingAddress->company_name = $input->billingCompanyName;
                $shippingAddress->address = $input->billingAddress;
                $shippingAddress->country = $input->billingCountry;
                $shippingAddress->state = $input->billingState;
                $shippingAddress->city = $input->billingCity;
                $shippingAddress->postcode = $input->billingPostcode;
                $shippingAddress->phone = $input->billingPhoneNumber;
                $shippingAddress->save();
            } elseif ($input->shippingFirstName || $input->shippingAddress) {
                $shippingAddress = new CartAddress;
                $shippingAddress->cart_id = $cart->id;
                $shippingAddress->address_type = CartAddress::ADDRESS_TYPE_SHIPPING;
                $shippingAddress->first_name = $input->shippingFirstName;
                $shippingAddress->last_name = $input->shippingLastName;
                $shippingAddress->email = $input->shippingEmail;
                $shippingAddress->company_name = $input->shippingCompanyName;
                $shippingAddress->address = $input->shippingAddress;
                $shippingAddress->country = $input->shippingCountry;
                $shippingAddress->state = $input->shippingState;
                $shippingAddress->city = $input->shippingCity;
                $shippingAddress->postcode = $input->shippingPostcode;
                $shippingAddress->phone = $input->shippingPhoneNumber;
                $shippingAddress->save();
            }

            if (! $billingAddress) {
                throw new OperationFailedException('No billing address was provided');
            }

            \Webkul\Checkout\Facades\Cart::collectTotals();

            if ($cart->haveStockableItems()) {
                \Webkul\Shipping\Facades\Shipping::collectRates();
            }

            return $this->buildAddressOutput($billingAddress, $shippingAddress);
        } catch (\Exception $e) {
            throw new OperationFailedException($e->getMessage(), 0, $e);
        }
    }

    /**
     * Save shipping method for cart.
     */
    private function saveShippingMethod($cart, CheckoutAddressInput $input)
    {
        try {
            cart()->setCart($cart);

            if (! $input->shippingMethod) {
                throw new OperationFailedException(__('bagistoapi::app.graphql.checkout.shipping-method-required'));
            }

            \Webkul\Shipping\Facades\Shipping::collectRates();

            if (! \Webkul\Shipping\Facades\Shipping::isMethodCodeExists($input->shippingMethod)) {
                throw new OperationFailedException(__('bagistoapi::app.graphql.checkout.invalid-shipping-method'));
            }

            if (! \Webkul\Checkout\Facades\Cart::saveShippingMethod($input->shippingMethod)) {
                throw new OperationFailedException(__('bagistoapi::app.graphql.checkout.shipping-method-save-failed'));
            }

            \Webkul\Checkout\Facades\Cart::collectTotals();

            return (object) [
                'id'             => (string) $cart->id,
                'success'        => true,
                'message'        => __('bagistoapi::app.graphql.checkout.shipping-method-saved'),
                'cartToken'      => (string) ($cart->guest_cart_token ?? $cart->customer_id),
                'shippingMethod' => (string) ($cart->shipping_method ?? ''),
            ];
        } catch (\Exception $e) {
            throw new OperationFailedException($e->getMessage(), 0, $e);
        }
    }

    /**
     * Save payment method for cart.
     */
    private function savePaymentMethod($cart, CheckoutAddressInput $input)
    {
        cart()->setCart($cart);

        if (! $input->paymentMethod) {
            throw new OperationFailedException(__('bagistoapi::app.graphql.checkout.payment-method-required'));
        }

        $paymentMethodConfig = config('payment_methods.'.$input->paymentMethod);
        if (! $paymentMethodConfig || ! isset($paymentMethodConfig['class'])) {
            throw new OperationFailedException(__('bagistoapi::app.graphql.checkout.invalid-payment-method'));
        }

        if (! \Webkul\Checkout\Facades\Cart::savePaymentMethod(['method' => $input->paymentMethod])) {
            throw new OperationFailedException(__('bagistoapi::app.graphql.checkout.payment-method-save-failed'));
        }

        try {
            \Webkul\Checkout\Facades\Cart::collectTotals();
            $cart = \Webkul\Checkout\Facades\Cart::getCart();

            $response = (object) [
                'success'        => true,
                'message'        => __('bagistoapi::app.graphql.checkout.payment-method-saved'),
                'cartToken'      => (string) ($cart->guest_cart_token ?? $cart->customer_id),
                'paymentMethod'  => (string) ($cart->payment?->method ?? ''),
            ];

            if ($cart->payment) {
                $paymentMethodClass = app($paymentMethodConfig['class']);

                if (method_exists($paymentMethodClass, 'getPaymentUrl') && method_exists($paymentMethodClass, 'getPaymentData')) {
                    $paymentData = $paymentMethodClass->getPaymentData($cart);

                    if ($input->paymentSuccessUrl) {
                        $paymentData['surl'] = $input->paymentSuccessUrl;
                    }
                    if ($input->paymentFailureUrl) {
                        $paymentData['furl'] = $input->paymentFailureUrl;
                    }
                    if ($input->paymentCancelUrl) {
                        $paymentData['curl'] = $input->paymentCancelUrl;
                    }

                    if ($input->paymentSuccessUrl || $input->paymentFailureUrl || $input->paymentCancelUrl) {
                        if (method_exists($paymentMethodClass, 'generateHash')) {
                            $paymentData['hash'] = $paymentMethodClass->generateHash(
                                $paymentData['txnid'],
                                $paymentData['amount'],
                                $paymentData['productinfo'],
                                $paymentData['firstname'],
                                $paymentData['email'],
                                $paymentData['udf1']
                            );
                        }
                    }

                    $response->paymentGatewayUrl = $paymentMethodClass->getPaymentUrl();
                    $response->paymentData = json_encode($paymentData);
                }
            }

            return $response;
        } catch (\Exception $e) {
            throw new OperationFailedException($e->getMessage(), 0, $e);
        }
    }

    /**
     * Create order from cart data.
     */
    private function createOrder($cart, CheckoutAddressInput $input)
    {
        try {
            $this->validateOrderCreation($cart, $input);

            Cart::setCart($cart);
            Cart::collectTotals();

            $orderData = $this->buildOrderDataFromCart($cart);
            $order = $this->orderRepository->create($orderData);

            if (! $order || ! $order->id) {
                throw new \Exception(__('bagistoapi::app.graphql.checkout.order-creation-failed'));
            }

            $orderId = $order->id;
            $order = $this->orderRepository->find($orderId);

            if (! $order) {
                throw new \Exception(__('bagistoapi::app.graphql.checkout.order-retrieval-failed', ['orderId' => $orderId]));
            }

            Cart::deActivateCart($cart);

            $response = (object) [
                'id'        => $cart->id,
                'cartToken' => (string) ($cart->guest_cart_token ?? $cart->customer_id),
                'orderId'   => (string) $order->id,
            ];

            return $response;
        } catch (\Exception $e) {
            throw new OperationFailedException($e->getMessage(), 0, $e);
        }
    }

    /**
     * Build order data from cart.
     */
    private function buildOrderDataFromCart($cart): array
    {
        $orderResource = new \Webkul\Sales\Transformers\OrderResource($cart);

        return $orderResource->jsonSerialize();
    }

    /**
     * Validate order can be created.
     */
    private function validateOrderCreation($cart, CheckoutAddressInput $input): void
    {
        if (! $cart || $cart->items()->count() === 0) {
            throw new OperationFailedException(__('bagistoapi::app.graphql.checkout.cart-empty'));
        }

        if (auth()->guard('customer')->check()) {
            $customer = auth()->guard('customer')->user();

            if ($customer && $customer->is_suspended) {
                throw new OperationFailedException(__('bagistoapi::app.graphql.checkout.account-suspended'));
            }

            if ($customer && ! $customer->status) {
                throw new OperationFailedException(__('bagistoapi::app.graphql.checkout.account-inactive'));
            }
        }

        $minimumOrderAmount = core()->getConfigData('sales.order_settings.minimum_order.minimum_order_amount') ?: 0;
        if (! \Webkul\Checkout\Facades\Cart::haveMinimumOrderAmount()) {
            throw new OperationFailedException(__('bagistoapi::app.graphql.checkout.minimum-order-not-met', ['amount' => core()->currency($minimumOrderAmount)]));
        }

        $hasBillingAddress = $input->billingAddress || $cart->billing_address()->exists();
        if (! $hasBillingAddress) {
            throw new OperationFailedException(__('bagistoapi::app.graphql.checkout.billing-address-required'));
        }

        $hasShippingAddress = $input->shippingAddress || $input->useForShipping || $cart->shipping_address()->exists();
        if (! $hasShippingAddress && $cart->haveStockableItems()) {
            throw new OperationFailedException(__('bagistoapi::app.graphql.checkout.shipping-address-required'));
        }

        $hasEmail = $cart->customer_email || $input->billingEmail || ($cart->billing_address && $cart->billing_address->email);
        if (! $hasEmail) {
            throw new OperationFailedException(__('bagistoapi::app.graphql.checkout.email-required'));
        }

        if ($cart->haveStockableItems()) {
            $hasShippingMethod = $input->shippingMethod || $cart->shipping_method;
            if (! $hasShippingMethod) {
                throw new OperationFailedException(__('bagistoapi::app.graphql.checkout.shipping-method-required'));
            }

            if (! $cart->selected_shipping_rate) {
                throw new OperationFailedException(__('bagistoapi::app.graphql.checkout.invalid-shipping-method'));
            }
        }

        $hasPaymentMethod = $input->paymentMethod || $cart->payment()->exists();
        if (! $hasPaymentMethod) {
            throw new OperationFailedException(__('bagistoapi::app.graphql.checkout.payment-method-required'));
        }
    }

    /**
     * Build CartData from cart model.
     */
    private function buildCartData($cart): CartData
    {
        $cartData = CartData::fromModel($cart);

        return $cartData;
    }

    /**
     * Build CheckoutAddressOutput from cart address models.
     */
    private function buildAddressOutput($billingAddress = null, $shippingAddress = null)
    {
        $output = (object) [
            'success' => true,
            'message' => __('bagistoapi::app.graphql.checkout.address-saved'),
        ];

        if ($billingAddress) {
            $output->id = $billingAddress->id;
            $output->cartToken = (string) ($billingAddress->cart->guest_cart_token ?? $billingAddress->cart->customer_id);
            $output->customerId = $billingAddress->cart->customer_id;

            $output->billingFirstName = (string) ($billingAddress->first_name ?? '');
            $output->billingLastName = (string) ($billingAddress->last_name ?? '');
            $output->billingEmail = (string) ($billingAddress->email ?? '');
            $output->billingCompanyName = (string) ($billingAddress->company_name ?? '');
            $output->billingAddress = (string) ($billingAddress->address ?? '');
            $output->billingCountry = (string) ($billingAddress->country ?? '');
            $output->billingState = (string) ($billingAddress->state ?? '');
            $output->billingCity = (string) ($billingAddress->city ?? '');
            $output->billingPostcode = (string) ($billingAddress->postcode ?? '');
            $output->billingPhoneNumber = (string) ($billingAddress->phone ?? '');
        }

        if ($shippingAddress) {
            $output->shippingFirstName = (string) ($shippingAddress->first_name ?? '');
            $output->shippingLastName = (string) ($shippingAddress->last_name ?? '');
            $output->shippingEmail = (string) ($shippingAddress->email ?? '');
            $output->shippingCompanyName = (string) ($shippingAddress->company_name ?? '');
            $output->shippingAddress = (string) ($shippingAddress->address ?? '');
            $output->shippingCountry = (string) ($shippingAddress->country ?? '');
            $output->shippingState = (string) ($shippingAddress->state ?? '');
            $output->shippingCity = (string) ($shippingAddress->city ?? '');
            $output->shippingPostcode = (string) ($shippingAddress->postcode ?? '');
            $output->shippingPhoneNumber = (string) ($shippingAddress->phone ?? '');
        }

        return $output;
    }

    /**
     * Fetch billing and shipping addresses for cart.
     */
    private function fetchAddresses($cart)
    {
        try {
            $output = new \Webkul\BagistoApi\Dto\CheckoutAddressOutput;

            $output->id = $cart->id;
            $output->cartToken = $cart->guest_cart_token ?? $cart->customer_id;
            $output->customerId = $cart->customer_id;

            $billingAddress = $cart->billing_address;
            if ($billingAddress) {
                $output->billingFirstName = $billingAddress->first_name;
                $output->billingLastName = $billingAddress->last_name;
                $output->billingEmail = $billingAddress->email;
                $output->billingCompanyName = $billingAddress->company_name;
                $output->billingAddress = $billingAddress->address;
                $output->billingCountry = $billingAddress->country;
                $output->billingState = $billingAddress->state;
                $output->billingCity = $billingAddress->city;
                $output->billingPostcode = $billingAddress->postcode;
                $output->billingPhoneNumber = $billingAddress->phone;
            }

            $shippingAddress = $cart->shipping_address;
            if ($shippingAddress) {
                $output->shippingFirstName = $shippingAddress->first_name;
                $output->shippingLastName = $shippingAddress->last_name;
                $output->shippingEmail = $shippingAddress->email;
                $output->shippingCompanyName = $shippingAddress->company_name;
                $output->shippingAddress = $shippingAddress->address;
                $output->shippingCountry = $shippingAddress->country;
                $output->shippingState = $shippingAddress->state;
                $output->shippingCity = $shippingAddress->city;
                $output->shippingPostcode = $shippingAddress->postcode;
                $output->shippingPhoneNumber = $shippingAddress->phone;
            }

            $output->success = true;
            $output->message = __('bagistoapi::app.graphql.address.retrieved');

            return $output;
        } catch (\Exception $e) {
            throw new OperationFailedException($e->getMessage(), 0, $e);
        }
    }
}
