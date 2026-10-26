define([], function () {
    'use strict';

    /** Same component, our template: Magento's plus a "Данни за фактура" heading. */
    return function (BillingAddress) {
        return BillingAddress.extend({
            defaults: {
                template: 'Beersisters_Speedy/billing-address'
            }
        });
    };
});
