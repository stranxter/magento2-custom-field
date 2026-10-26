define(['uiRegistry'], function (registry) {
    'use strict';

    /** "Next" on the shipping step also requires a complete Speedy choice. */
    return function (Shipping) {
        return Shipping.extend({
            validateShippingInformation: function () {
                var speedy = registry.get('checkout.steps.shipping-step.shippingAddress.bsspeedy');

                if (speedy && !speedy.validate()) {
                    return false;
                }

                return this._super();
            }
        });
    };
});
