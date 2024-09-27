## Adding a custom field to the checkout process

This module adds a custom text field to the Magento 2 checkout page. The field's value is stored in newly added columns in the `quote` and `sales_order` tables. The data is also shown on the Order details page in the Magento 2 admin interface. Verified with Magento 2.4.7.


The code is based on the following sources. None of them worked as-is for me, however, they are worth reading if you would like to understand how the module works.

- [How to Add Custom Field in Checkout Page Below Payment Method List in Magento 2 by Sanjay Jethva](https://meetanshi.com/blog/add-custom-field-in-checkout-page-below-payment-method-list-in-magento-2/)
The most recent tutorial I found as of September 2024

- [How to Add Custom Fields to Checkout Page in Magento 2 Programmatically by Nga Dong](https://blog.magezon.com/how-to-add-field-in-magento-2-checkout-page-mgt/)
This one did not work, most probably due to the custom mixin not loading for some reason. I found that this tutorial was copied all over the internet with errors. 

- [How to Create Basic Magento 2 Module? by Ihor Vansach](https://magefan.com/blog/create-basic-magento-2-extension)
Basic information about module creation

## Installation and tweaking:

- copy the directory tree to your <Magento Dir>/add/code directory
- run `setup:upgrade`
- the vendor and module name (Beersisters/Deliverynote) does not apper anywhere on the user interface, but if you want to change them, make sure to replace all namespaces and module indentifiers
- if you want to place the custom field at another location relative to the checkout steps and other checkout fields, you need to modify `checkout_index_index.xml`. However, this will require your own research as to how exactly to locate the right spot. The tutorials above provide some direction. Look at `LayoutProcessorPlugin.php` in Nga Dong's post and the [Magento Documentation](https://developer.adobe.com/commerce/php/tutorials/frontend/custom-checkout/)
- if you want to change the look and feel or the type of the field, modify `deliverynote.html`. You may need a different bidnig if you want to change the field type. For example, if you want a checkbox as in Sanjay Jethva's post, you will want to use `data-bind='checked:<YOUR VARIABLE>'`. For a list of Knockout data bindings, see [Knockout.js binding syntax](https://developer.adobe.com/commerce/frontend-core/ui-components/concepts/binding-syntax/). The variable name, initial value, and the name of the query parameters for the Ajax request will need to be modified in ``customJs.js``. The name of the query patameter will need to be modified in ``SaveInQuote.php`` and ``PlaveOrder.php``.