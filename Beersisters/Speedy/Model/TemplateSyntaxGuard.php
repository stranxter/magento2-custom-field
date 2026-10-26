<?php
declare(strict_types=1);

namespace Beersisters\Speedy\Model;

use Magento\Framework\DataObject;
use Magento\Framework\Exception\LocalizedException;

/**
 * Refuses address text that could be read as an e-mail template directive.
 *
 * In the 2026-09-24 incident, `{{block class=...}}` staged in quote addresses reached the e-mail template parser
 * (SECURITY-BACKLOG B0/B5). Formatted addresses join fields ("firstname lastname"), so a directive can be split
 * across fields; refusing any single brace in any address field closes that. Nobody needs braces in an address.
 */
class TemplateSyntaxGuard
{
    private const FIELDS = ['firstname', 'middlename', 'lastname', 'prefix', 'suffix', 'company', 'street', 'city',
        'region', 'postcode', 'telephone', 'fax', 'email', 'vat_id'];

    /**
     * @param DataObject $address a quote or order address
     * @throws LocalizedException
     */
    public function assertClean(DataObject $address): void
    {
        foreach (self::FIELDS as $field) {
            $value = $address->getData($field);
            foreach (is_array($value) ? $value : [$value] as $text) {
                if (is_string($text) && (str_contains($text, '{') || str_contains($text, '}'))) {
                    throw new LocalizedException(
                        __('The address contains characters that are not allowed (curly braces).')
                    );
                }
            }
        }
    }
}
