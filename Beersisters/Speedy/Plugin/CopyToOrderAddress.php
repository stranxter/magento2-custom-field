<?php
declare(strict_types=1);

namespace Beersisters\Speedy\Plugin;

use Magento\Quote\Model\Quote\Address as QuoteAddress;
use Magento\Quote\Model\Quote\Address\ToOrderAddress;
use Magento\Sales\Api\Data\OrderAddressInterface;

/**
 * Copies the Speedy columns from the quote address to the order address.
 *
 * A fieldset.xml entry is not enough: ToOrderAddress fills the order address through
 * DataObjectHelper::populateWithArray(), which only calls the interface's setters and drops other keys.
 */
class CopyToOrderAddress
{
    private const FIELDS = ['speedy_method', 'speedy_site_id', 'speedy_office_id'];

    /**
     * @param array $data
     */
    public function afterConvert(
        ToOrderAddress $subject,
        OrderAddressInterface $result,
        QuoteAddress $object,
        $data = []
    ): OrderAddressInterface {
        foreach (self::FIELDS as $field) {
            $result->setData($field, $object->getData($field));
        }

        return $result;
    }
}
