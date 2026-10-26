define([
    'mage/utils/wrapper',
    'Beersisters_Speedy/js/model/state'
], function (wrapper, state) {
    'use strict';

    /**
     * An office is not the customer's address, so it can't be their billing address: in office mode the
     * "billing same as shipping" checkbox disappears and the billing form is required (invoices, НАП).
     */
    return function (newCustomerAddress) {
        return wrapper.wrap(newCustomerAddress, function (original, addressData) {
            var address = original(addressData),
                canUseForBilling = address.canUseForBilling;

            address.canUseForBilling = function () {
                return state.mode() !== 'office' && canUseForBilling.call(address);
            };

            return address;
        });
    };
});
