define([
    'jquery',
    'mage/utils/wrapper',
    'Beersisters_Speedy/js/model/state'
], function ($, wrapper, state) {
    'use strict';

    /** Sends the Speedy ids with the shipping address; the server rebuilds everything else from them. */
    return function (payloadExtender) {
        return wrapper.wrap(payloadExtender, function (original, payload) {
            var info, address;

            payload = original(payload);
            info = payload.addressInformation;
            if (!info || info['shipping_carrier_code'] !== 'bsspeedy' || !info['shipping_address'] || !state.mode()) {
                return payload;
            }
            address = $.extend({}, info['shipping_address']);
            // One spelling only: the address model also carries camelCase `extensionAttributes`.
            delete address.extensionAttributes;
            address['extension_attributes'] = $.extend({}, address['extension_attributes'], {
                'speedy_method': state.mode(),
                'speedy_site_id': state.site() ? state.site().id : null,
                'speedy_office_id': state.mode() === 'office' && state.office() ? state.office().id : null
            });
            info['shipping_address'] = address;

            return payload;
        });
    };
});
