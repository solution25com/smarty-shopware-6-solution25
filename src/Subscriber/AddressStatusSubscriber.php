<?php

declare(strict_types=1);

namespace SmartyAddressValidation\Subscriber;

use Psr\Log\LoggerInterface;
use SmartyAddressValidation\Service\AddressConfirmationService;
use Shopware\Core\Checkout\Customer\Event\CustomerLoginEvent;
use Shopware\Core\Checkout\Customer\Event\CustomerLogoutEvent;
use Shopware\Core\PlatformRequest;
use Shopware\Storefront\Event\StorefrontRenderEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Throwable;

class AddressStatusSubscriber implements EventSubscriberInterface
{
    public const PENDING_COOKIE = 'smarty-address-pending';

    private const PENDING_ATTRIBUTE = '_smartyAddressPending';

    public function __construct(
        private readonly AddressConfirmationService $addressConfirmationService,
        private readonly RequestStack $requestStack,
        private readonly LoggerInterface $logger
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            StorefrontRenderEvent::class => 'onStorefrontRender',
            CustomerLoginEvent::class => 'onCustomerLogin',
            CustomerLogoutEvent::class => 'onCustomerLogout',
            KernelEvents::RESPONSE => 'onResponse',
        ];
    }

    public function onStorefrontRender(StorefrontRenderEvent $event): void
    {
        $request = $event->getRequest();
        $context = $event->getSalesChannelContext();
        $customer = $context->getCustomer();

        if (
            $request !== $this->requestStack->getMainRequest()
            || $request->isXmlHttpRequest()
            || $customer === null
            || $customer->getGuest()
        ) {
            return;
        }

        try {
            $status = $this->addressConfirmationService->getStatus($context, $request);
        } catch (Throwable $exception) {
            $this->logger->warning('Unable to prepare Smarty address status for the storefront.', [
                'exception' => $exception,
            ]);

            return;
        }

        if (!($status['shouldValidate'] ?? false)) {
            if (
                ($status['reason'] ?? null) !== 'checkout_page'
                && $request->cookies->has(self::PENDING_COOKIE)
                && !$request->attributes->get(PlatformRequest::ATTRIBUTE_HTTP_CACHE)
            ) {
                $request->attributes->set(self::PENDING_ATTRIBUTE, false);
            }

            return;
        }

        unset($status['address']['customFields']);

        $request->attributes->set(PlatformRequest::ATTRIBUTE_HTTP_CACHE, false);
        $request->attributes->set(PlatformRequest::ATTRIBUTE_NO_STORE, true);
        $request->attributes->set(self::PENDING_ATTRIBUTE, true);
        $event->setParameter('smartyAddressStatus', $status);
    }

    public function onCustomerLogin(CustomerLoginEvent $event): void
    {
        if ($event->getCustomer()->getGuest()) {
            return;
        }

        $this->requestStack->getMainRequest()?->attributes->set(self::PENDING_ATTRIBUTE, true);
    }

    public function onCustomerLogout(CustomerLogoutEvent $event): void
    {
        $request = $this->requestStack->getMainRequest();

        if ($request?->cookies->has(self::PENDING_COOKIE)) {
            $request->attributes->set(self::PENDING_ATTRIBUTE, false);
        }
    }

    public function onResponse(ResponseEvent $event): void
    {
        $request = $event->getRequest();

        if (!$event->isMainRequest() || !$request->attributes->has(self::PENDING_ATTRIBUTE)) {
            return;
        }

        $headers = $event->getResponse()->headers;

        if ($request->attributes->get(self::PENDING_ATTRIBUTE) === true) {
            $headers->setCookie($this->pendingCookie());

            return;
        }

        $headers->clearCookie(self::PENDING_COOKIE, '/', null, true, false, Cookie::SAMESITE_LAX);
    }

    private function pendingCookie(): Cookie
    {
        return Cookie::create(
            self::PENDING_COOKIE,
            '1',
            0,
            '/',
            null,
            true,
            false,
            false,
            Cookie::SAMESITE_LAX
        );
    }
}
