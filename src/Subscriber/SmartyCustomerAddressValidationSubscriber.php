<?php

declare(strict_types=1);

namespace SmartyIntegration\Subscriber;

use Shopware\Core\Checkout\Customer\Aggregate\CustomerAddress\CustomerAddressDefinition;
use SmartyIntegration\Service\SmartyApiService;
use SmartyIntegration\Domain\Address\AdressDto;
use Shopware\Core\Checkout\Customer\CustomerEvents;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\Validation\Exception\ConstraintViolationException;
use Shopware\Core\System\Country\CountryEntity;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;

class SmartyCustomerAddressValidationSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly SmartyApiService $smartyApiService,
        private readonly EntityRepository $countryRepository
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            CustomerEvents::CUSTOMER_ADDRESS_WRITTEN_EVENT => 'onCustomerAddressWritten',
        ];
    }

    public function onCustomerAddressWritten(EntityWrittenEvent $event): void
    {
        $context = $event->getContext();

        foreach ($event->getWriteResults() as $writeResult) {
            $payload = $writeResult->getPayload();
            if (!$payload) {
                continue;
            }

            $street    = $payload['street']  ?? null;
            $city      = $payload['city']    ?? null;
            $zip       = $payload['zipcode'] ?? null;
            $countryId = $payload['countryId'] ?? null;

            if (!$street || !$city || !$zip || !$countryId) {
                continue;
            }

            $countryIso = $this->getCountryIsoFromId($countryId, $context);
            if (!$countryIso) {
                continue;
            }

            $addressDto = new AdressDto(
                $street,
                $city,
                $zip,
                $countryIso,
            );

            $result = $this->smartyApiService->validateAdress($addressDto);

            if ($result->isValid()) {
                continue;
            }

            $violations = new ConstraintViolationList([
                new ConstraintViolation(
                    'Address is not valid according to Smarty.',
                    '',
                    [],
                    null,
                    'street',
                    $street
                ),
            ]);

            throw new ConstraintViolationException($violations, $payload);
        }
    }

    private function getCountryIsoFromId(string $countryId, Context $context): ?string
    {
        $criteria = new Criteria([$countryId]);

        /** @var CountryEntity|null $country */
        $country = $this->countryRepository->search($criteria, $context)->first();

        return $country?->getIso();
    }
}
