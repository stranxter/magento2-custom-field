define([], function () {
    'use strict';

    /** Same component, our template: billing = delivery address, form only for virtual-only carts. */
    return function (BillingAddress) {
        return BillingAddress.extend({
            defaults: {
                template: 'Beersisters_Speedy/billing-address'
            }
        });
    };
});
