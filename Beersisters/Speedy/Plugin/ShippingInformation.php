<?php
declare(strict_types=1);

namespace Beersisters\Speedy\Plugin;

use Beersisters\Speedy\Model\AddressApplier;
use Magento\Checkout\Api\Data\ShippingInformationInterface;
use Magento\Checkout\Model\ShippingInformationManagement;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Model\ResourceModel\Quote\Address as AddressResource;

/**
 * Validates and stores the Speedy choice when the checkout saves the shipping step. Guest checkout goes
 * through the same method (GuestShippingInformationManagement delegates to it).
 */
class ShippingInformation
{
    /**
     * @var AddressApplier
     */
    private $addressApplier;

    /**
     * @var CartRepositoryInterface
     */
    private $cartRepository;

    /**
     * @var AddressResource
     */
    private $addressResource;

    public function __construct(
        AddressApplier $addressApplier,
        CartRepositoryInterface $cartRepository,
        AddressResource $addressResource
    ) {
        $this->addressApplier = $addressApplier;
        $this->cartRepository = $cartRepository;
        $this->addressResource = $addressResource;
    }

    /**
     * @param int|string $cartId
     */
    public function aroundSaveAddressInformation(
        ShippingInformationManagement $subject,
        callable $proceed,
        $cartId,
        ShippingInformationInterface $addressInformation
    ) {
        $values = $this->addressApplier->apply(
            $addressInformation->getShippingAddress(),
            (string)$addressInformation->getShippingCarrierCode(),
            (string)$addressInformation->getShippingMethodCode()
        );

        $result = $proceed($cartId, $addressInformation);

        // A saved customer address is re-imported from the address book during save, which drops our columns.
        $address = $this->cartRepository->getActive((int)$cartId)->getShippingAddress();
        $changed = false;
        foreach ($values as $key => $value) {
            if ($address->getData($key) != $value) {
                $address->setData($key, $value);
                $changed = true;
            }
        }
        if ($changed) {
            $this->addressResource->save($address);
        }

        return $result;
    }
}
