/**
 * The customer's Speedy choice, shared by the checkout component and the mixins.
 */
define(['ko'], function (ko) {
    'use strict';

    return {
        /** null, 'office' or 'address' */
        mode: ko.observable(null),
        /** {id, type, name, municipality, region, region_id, post_code} */
        site: ko.observable(null),
        /** {id, type, name, address, local_address, post_code, hours_*, cash_payment, card_payment} */
        office: ko.observable(null)
    };
});
