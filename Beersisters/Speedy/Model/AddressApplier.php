<?php
declare(strict_types=1);

namespace Beersisters\Speedy\Model;

use Magento\Framework\Exception\InputException;
use Magento\Framework\Exception\LocalizedException;
use Magento\Quote\Api\Data\AddressInterface;

/**
 * Checks the customer's Speedy choice against the local tables and writes it onto the shipping address
 * (SPEEDY-PLAN.md §3.6). The browser only sends ids; everything shown on the order is rebuilt from our data:
 *  - office: street lines, city, postcode and region come from the office, never from the client;
 *  - address: city and region come from the chosen site; the street lines stay as typed.
 * Observer\ValidateOrder re-checks the stored address against the same rules before the order is created.
 */
class AddressApplier
{
    /**
     * @var Lookup
     */
    private $lookup;

    /**
     * @var TemplateSyntaxGuard
     */
    private $guard;

    public function __construct(Lookup $lookup, TemplateSyntaxGuard $guard)
    {
        $this->lookup = $lookup;
        $this->guard = $guard;
    }

    /**
     * @return array{speedy_method: ?string, speedy_site_id: ?int, speedy_office_id: ?int} the values stored
     * @throws InputException with a message fit for the shopper
     */
    public function apply(AddressInterface $address, string $carrierCode, string $methodCode): array
    {
        $values = ['speedy_method' => null, 'speedy_site_id' => null, 'speedy_office_id' => null];
        if ($carrierCode !== Carrier::CODE) {
            $this->setValues($address, $values);

            return $values;
        }
        if (!in_array($methodCode, [Carrier::METHOD_OFFICE, Carrier::METHOD_ADDRESS], true)) {
            throw new InputException(__('Please choose Speedy delivery to an office or to an address.'));
        }

        $extension = $address->getExtensionAttributes();
        $siteId = (int)($extension ? $extension->getSpeedySiteId() : 0);
        $officeId = (int)($extension ? $extension->getSpeedyOfficeId() : 0);

        if ($methodCode === Carrier::METHOD_OFFICE) {
            $office = $this->lookup->getOffice($officeId);
            $site = $office ? $this->lookup->getSite((int)$office['site_id']) : null;
            if (!$office || !$site) {
                throw new InputException(__('Please choose a Speedy office or automat.'));
            }
            $address->setStreet($this->officeStreet($office));
            $address->setPostcode($this->officePostcode($office, $site));
            $address->setCompany(null);
            // An office is not the customer's address: never put it in their address book or reuse it for billing.
            $address->setCustomerAddressId(null);
            $address->setSaveInAddressBook(0);
            $address->setSameAsBilling(0);
        } else {
            $site = $siteId > 0
                ? $this->lookup->getSite($siteId)
                : $this->lookup->resolveSite((string)$address->getCity(), (string)$address->getPostcode());
            if (!$site) {
                throw new InputException(__('Please choose your town or village from the list.'));
            }
            if (!preg_match('/^\d{4}$/', (string)$address->getPostcode())) {
                $address->setPostcode((string)$site['post_code']);
            }
            $officeId = 0;
        }

        $address->setCountryId('BG');
        $address->setCity((string)$site['name']);
        $regionMap = $this->lookup->getRegionMap();
        $regionId = $regionMap->getRegionId((string)$site['region']);
        if ($regionId !== null) {
            $address->setRegionId($regionId);
            $address->setRegion($regionMap->getDisplayName((string)$site['region']));
        }
        try {
            $this->guard->assertClean($address);
        } catch (LocalizedException $e) {
            throw new InputException(__($e->getMessage()));
        }

        $values = [
            'speedy_method' => $methodCode,
            'speedy_site_id' => (int)$site['site_id'],
            'speedy_office_id' => $officeId > 0 ? $officeId : null,
        ];
        $this->setValues($address, $values);

        return $values;
    }

    /**
     * The two street lines of an office delivery, as built here and re-checked by Observer\ValidateOrder.
     *
     * @return string[]
     */
    public function officeStreet(array $office): array
    {
        return [
            (string)__('Speedy office %1: %2', $office['office_id'], $office['name']),
            (string)$office['local_address'],
        ];
    }

    public function officePostcode(array $office, array $site): string
    {
        return (string)($office['post_code'] ?: $site['post_code']);
    }

    private function setValues(AddressInterface $address, array $values): void
    {
        foreach ($values as $key => $value) {
            $address->setData($key, $value);
        }
    }
}
