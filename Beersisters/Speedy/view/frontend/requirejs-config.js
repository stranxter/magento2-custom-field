/**
 * Checkout-only mixins: each target module is loaded by the checkout page alone, so nothing here runs on
 * other pages (unlike the vendor module's global mage/menu mixin, SPEEDY-PLAN.md Appendix A).
 */
var config = {
    config: {
        mixins: {
            'Magento_Checkout/js/view/shipping': {
                'Beersisters_Speedy/js/mixin/shipping-mixin': true
            },
            'Magento_Checkout/js/model/shipping-save-processor/payload-extender': {
                'Beersisters_Speedy/js/mixin/payload-extender-mixin': true
            },
            'Magento_Checkout/js/model/new-customer-address': {
                'Beersisters_Speedy/js/mixin/new-customer-address-mixin': true
            },
            'Magento_Checkout/js/view/billing-address': {
                'Beersisters_Speedy/js/mixin/billing-address-mixin': true
            }
        }
    }
};
