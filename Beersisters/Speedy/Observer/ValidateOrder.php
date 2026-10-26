<?php
declare(strict_types=1);

namespace Beersisters\Speedy\Observer;

use Beersisters\Speedy\Model\AddressApplier;
use Beersisters\Speedy\Model\Carrier;
use Beersisters\Speedy\Model\Lookup;
use Beersisters\Speedy\Model\TemplateSyntaxGuard;
use Magento\Framework\App\Area;
use Magento\Framework\App\State;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Quote\Model\Quote\Address;

/**
 * Last check before an order is created (sales_model_service_quote_submit_before), whatever path the order
 * took (storefront, REST, GraphQL), so it trusts nothing stored earlier:
 *  - every order: no template braces in either address (09-24 incident class);
 *  - Speedy orders from the storefront: the stored address still matches the stored office/site exactly (another
 *    checkout path could have changed the street after Plugin\ShippingInformation ran), and an office order has the
 *    customer's own billing address (needed for the invoice, НАП).
 * Orders entered by staff in the admin skip the Speedy checks: there is no admin UI to pick a site or office.
 */
class ValidateOrder implements ObserverInterface
{
    /**
     * @var Lookup
     */
    private $lookup;

    /**
     * @var AddressApplier
     */
    private $addressApplier;

    /**
     * @var TemplateSyntaxGuard
     */
    private $guard;

    /**
     * @var State
     */
    private $appState;

    public function __construct(
        Lookup $lookup,
        AddressApplier $addressApplier,
        TemplateSyntaxGuard $guard,
        State $appState
    ) {
        $this->lookup = $lookup;
        $this->addressApplier = $addressApplier;
        $this->guard = $guard;
        $this->appState = $appState;
    }

    public function execute(Observer $observer)
    {
        /** @var \Magento\Quote\Model\Quote|null $quote */
        $quote = $observer->getEvent()->getQuote();
        if ($quote === null) {
            return;
        }
        $this->guard->assertClean($quote->getBillingAddress());
        if ($quote->isVirtual()) {
            return;
        }
        $shipping = $quote->getShippingAddress();
        $this->guard->assertClean($shipping);

        $method = (string)$shipping->getShippingMethod();
        if (!str_starts_with($method, Carrier::CODE . '_') || $this->isAdmin()) {
            return;
        }
        $code = substr($method, strlen(Carrier::CODE) + 1);
        $site = $this->lookup->getSite((int)$shipping->getData('speedy_site_id'));
        if ($shipping->getData('speedy_method') !== $code || !$site
            || $shipping->getCountryId() !== 'BG' || (string)$shipping->getCity() !== (string)$site['name']
        ) {
            throw $this->chooseAgain();
        }
        if ($code !== Carrier::METHOD_OFFICE) {
            return;
        }

        $office = $this->lookup->getOffice((int)$shipping->getData('speedy_office_id'));
        if (!$office) {
            throw new LocalizedException(
                __('The selected Speedy office is no longer available. Please choose another one.')
            );
        }
        if ((int)$office['site_id'] !== (int)$site['site_id']
            || $this->lines($shipping) !== $this->addressApplier->officeStreet($office)
            || (string)$shipping->getPostcode() !== $this->addressApplier->officePostcode($office, $site)
        ) {
            throw $this->chooseAgain();
        }
        $this->checkBilling($quote->getBillingAddress(), $shipping);
    }

    /**
     * @throws LocalizedException
     */
    private function checkBilling(Address $billing, Address $shipping): void
    {
        $street = trim(implode(' ', $this->lines($billing)));
        if (mb_strlen($street) < 5 || $this->lines($billing) === $this->lines($shipping)
            || trim((string)$billing->getCity()) === '' || trim((string)$billing->getPostcode()) === ''
        ) {
            throw new LocalizedException(__('Please enter your own billing address. It is needed for the invoice.'));
        }
    }

    /**
     * @return string[] street lines, trimmed, without empty trailing lines
     */
    private function lines(Address $address): array
    {
        $lines = array_map(static fn ($line): string => trim((string)$line), (array)$address->getStreet());
        while ($lines && end($lines) === '') {
            array_pop($lines);
        }

        return array_values($lines);
    }

    private function chooseAgain(): LocalizedException
    {
        return new LocalizedException(
            __('Please go back to the shipping step and choose your Speedy delivery again.')
        );
    }

    private function isAdmin(): bool
    {
        try {
            return $this->appState->getAreaCode() === Area::AREA_ADMINHTML;
        } catch (LocalizedException $e) {
            return false;
        }
    }
}
