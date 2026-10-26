/**
 * Speedy delivery choice at the top of the shipping step (SPEEDY-PLAN.md §3.5):
 *  - a switch "to an office/automat" | "to an address" that also selects the shipping method;
 *  - a town/village autocomplete from our local copy of Speedy's sites (replaces Magento's city field);
 *  - in office mode, the list of that place's offices and automats with a search box; the street,
 *    city, postcode and region fields are hidden and filled from the office.
 * The server rebuilds the address from the ids we send (Model\AddressApplier); this file only drives the UI.
 */
define([
    'jquery',
    'ko',
    'underscore',
    'uiComponent',
    'uiRegistry',
    'mage/translate',
    'Magento_Checkout/js/model/quote',
    'Magento_Checkout/js/model/shipping-service',
    'Magento_Checkout/js/action/select-shipping-method',
    'Magento_Checkout/js/action/select-shipping-address',
    'Magento_Checkout/js/checkout-data',
    'Magento_Checkout/js/model/address-converter',
    'Magento_Catalog/js/price-utils',
    'Beersisters_Speedy/js/model/state'
], function (
    $, ko, _, Component, registry, $t, quote, shippingService, selectShippingMethodAction,
    selectShippingAddressAction, checkoutData, addressConverter, priceUtils, state
) {
    'use strict';

    var SHIPPING = 'checkout.steps.shipping-step.shippingAddress',
        FIELDSET = SHIPPING + '.shipping-address-fieldset',
        STORAGE_KEY = 'bsspeedy-choice';

    return Component.extend({
        defaults: {
            template: 'Beersisters_Speedy/speedy',
            siteUrl: '',
            officeUrl: ''
        },

        initialize: function () {
            var self = this;

            this._super();

            this.mode = state.mode;
            this.site = state.site;
            this.office = state.office;
            this.modes = [
                {code: 'office', title: $t('To a Speedy office or automat')},
                {code: 'address', title: $t('To an address')}
            ];
            this.modeChoice = ko.pureComputed({
                read: function () {
                    return state.mode();
                },
                write: function (code) {
                    self.setMode(code);
                }
            });
            this.siteTerm = ko.observable('');
            this.siteSuggestions = ko.observableArray([]);
            this.siteCursor = ko.observable(-1);
            this.siteLoading = ko.observable(false);
            this.siteRequest = 0;
            this.offices = ko.observableArray([]);
            this.officesLoading = ko.observable(false);
            this.officeFilter = ko.observable('');
            this.modeError = ko.observable('');
            this.siteError = ko.observable('');
            this.officeError = ko.observable('');
            this.recipient = ko.observable(null);
            this.savedAddress = null;
            this.autoPostcode = null;

            this.rates = shippingService.getShippingRates();
            this.isAvailable = ko.pureComputed(function () {
                return !!(self.findRate('office') && self.findRate('address'));
            });
            this.filteredOffices = ko.pureComputed(function () {
                var term = $.trim(self.officeFilter()).toLowerCase();

                if (!term) {
                    return self.offices();
                }

                return _.filter(self.offices(), function (office) {
                    return (office.name + ' ' + office.address).toLowerCase().indexOf(term) !== -1;
                });
            });
            this.selectedOfficeId = ko.pureComputed({
                read: function () {
                    return state.office() ? state.office().id : null;
                },
                write: function (id) {
                    var office = _.findWhere(self.offices(), {id: id});

                    if (office) {
                        self.chooseOffice(office);
                    }
                }
            });
            this.isAutomat = ko.pureComputed(function () {
                return !!state.office() && state.office().type === 'APT';
            });

            this.siteTerm.subscribe(_.debounce(this.searchSites.bind(this), 250));
            this.isAvailable.subscribe(this.refresh, this);
            this.rates.subscribe(function () {
                // Rates are re-estimated whenever the address changes; keep our method selected.
                _.defer(function () {
                    if (state.mode()) {
                        self.selectRate(state.mode());
                    }
                });
            });
            quote.shippingAddress.subscribe(this.onShippingAddressChange, this);

            // The address form renders after us; apply visibility/values once its fields exist.
            registry.async(FIELDSET + '.city')(function () {
                self.refresh();
            });
            registry.async(FIELDSET + '.country_id')(function (country) {
                country.value.subscribe(function () {
                    // Magento's region component re-shows the region fields on a country change.
                    _.defer(self.applyFormVisibility.bind(self));
                });
            });

            this.restore();

            return this;
        },

        /* ---------- mode ---------- */

        findRate: function (code) {
            return _.find(this.rates(), function (rate) {
                return rate['carrier_code'] === 'bsspeedy' && rate['method_code'] === code && rate.available !== false;
            });
        },

        priceFor: function (code) {
            var rate = this.findRate(code);

            if (!rate) {
                return '';
            }

            return rate['price_incl_tax'] > 0
                ? priceUtils.formatPrice(rate['price_incl_tax'], quote.getPriceFormat())
                : $t('Free');
        },

        setMode: function (code) {
            if (code !== 'office' && code !== 'address') {
                return;
            }
            this.modeError('');
            if (code === 'office') {
                this.rememberSavedAddress(quote.shippingAddress());
                state.office(null);
                this.setStreet(['', '']);
                state.mode(code);
                if (state.site()) {
                    this.loadOffices(state.site().id);
                }
            } else {
                state.mode(code);
                state.office(null);
                this.offices([]);
                if (this.isOfficeStreet()) {
                    this.setStreet(['', '']);
                }
                if (!this.isFormInline() && this.savedAddress) {
                    selectShippingAddressAction(this.savedAddress);
                }
            }
            this.selectRate(code);
            this.refresh();
            this.save();
        },

        selectRate: function (code) {
            var rate = this.findRate(code),
                current = quote.shippingMethod();

            if (!rate || current && current['carrier_code'] === 'bsspeedy' && current['method_code'] === code) {
                return;
            }
            selectShippingMethodAction(rate);
            checkoutData.setSelectedShippingRate(rate['carrier_code'] + '_' + rate['method_code']);
        },

        /* ---------- town / village ---------- */

        siteLabel: function (site) {
            return $.trim((site.type || '') + ' ' + site.name);
        },

        siteMeta: function (site) {
            return _.compact([
                site.municipality ? $t('mun.') + ' ' + site.municipality : '',
                site.region ? $t('reg.') + ' ' + site.region : '',
                site['post_code']
            ]).join(', ');
        },

        searchSites: function (term) {
            var self = this,
                request;

            term = $.trim(term);
            if (state.site() && term === this.siteLabel(state.site())) {
                this.siteSuggestions([]);

                return;
            }
            if (state.site()) {
                state.site(null);
                state.office(null);
                this.offices([]);
            }
            this.siteError('');
            if (term.length < 2) {
                this.siteSuggestions([]);

                return;
            }
            request = ++this.siteRequest;
            this.siteLoading(true);
            $.ajax({
                url: this.siteUrl,
                type: 'GET',
                dataType: 'json',
                data: {q: term, 'with_offices': state.mode() === 'office' ? 1 : 0}
            }).done(function (response) {
                var items = response && response.items ? response.items : [];

                if (request !== self.siteRequest) {
                    return;
                }
                self.siteSuggestions(items);
                self.siteCursor(items.length ? 0 : -1);
                if (!items.length) {
                    self.siteError(state.mode() === 'office'
                        ? $t('There is no Speedy office or automat in a place with this name.')
                        : $t('No town or village found with this name.'));
                }
            }).always(function () {
                if (request === self.siteRequest) {
                    self.siteLoading(false);
                }
            });
        },

        chooseSite: function (site) {
            state.site(site);
            state.office(null);
            this.siteTerm(this.siteLabel(site));
            this.siteSuggestions([]);
            this.siteError('');
            if (state.mode() === 'office') {
                this.loadOffices(site.id);
            }
            this.fillFromChoice();
            this.save();

            return true;
        },

        onSiteKeydown: function (data, event) {
            var items = this.siteSuggestions(),
                cursor = this.siteCursor();

            if (!items.length) {
                return true;
            }
            switch (event.key) {
                case 'ArrowDown':
                    this.siteCursor(Math.min(cursor + 1, items.length - 1));

                    return false;
                case 'ArrowUp':
                    this.siteCursor(Math.max(cursor - 1, 0));

                    return false;
                case 'Enter':
                    if (cursor >= 0) {
                        this.chooseSite(items[cursor]);
                    }

                    return false;
                case 'Escape':
                    this.siteSuggestions([]);

                    return false;
                default:
                    return true;
            }
        },

        onSiteBlur: function () {
            var self = this;

            setTimeout(function () {
                self.siteSuggestions([]);
            }, 200);

            return true;
        },

        /* ---------- offices ---------- */

        loadOffices: function (siteId) {
            var self = this;

            this.officesLoading(true);
            this.officeFilter('');
            $.ajax({
                url: this.officeUrl,
                type: 'GET',
                dataType: 'json',
                data: {'site_id': siteId}
            }).done(function (response) {
                var items = response && response.items ? response.items : [],
                    current = state.office();

                if (!state.site() || state.site().id !== siteId) {
                    return;
                }
                self.offices(items);
                if (current && !_.findWhere(items, {id: current.id})) {
                    state.office(null);
                    self.save();
                }
            }).always(function () {
                self.officesLoading(false);
            });
        },

        chooseOffice: function (office) {
            state.office(office);
            this.officeError('');
            this.fillFromChoice();
            this.save();
        },

        hoursText: function (office) {
            var format = function (value) {
                if (!value || value === '00:00-00:00') {
                    return $t('closed');
                }

                return value === '00:00-23:59' ? $t('24 h') : value.replace('-', '–');
            };

            if (office['hours_full'] === '00:00-23:59' && office['hours_half'] === office['hours_full']
                && office['hours_dayoff'] === office['hours_full']) {
                return $t('Open 24/7');
            }

            return $t('Weekdays') + ' ' + format(office['hours_full']) + ' · '
                + $t('Sat') + ' ' + format(office['hours_half']) + ' · '
                + $t('Sun') + ' ' + format(office['hours_dayoff']);
        },

        /* ---------- the Magento address form ---------- */

        isFormInline: function () {
            var shipping = registry.get(SHIPPING);

            return shipping ? !!shipping.isFormInline : true;
        },

        field: function (name) {
            return registry.get(FIELDSET + '.' + name);
        },

        setField: function (name, value) {
            var field = this.field(name);

            if (field && field.value() !== value) {
                field.value(value);
            }
        },

        setVisible: function (name, visible) {
            var field = this.field(name);

            if (field && field.visible() !== visible) {
                field.visible(visible);
            }
            if (name === 'street') {
                // The street group's template ignores its own `visible`: hide each line.
                _.each([0, 1, 2, 3], function (index) {
                    this.setVisible('street.' + index, visible);
                }, this);
            }
        },

        setStreet: function (lines) {
            if (this.field('street.1')) {
                this.setField('street.0', lines[0] || '');
                this.setField('street.1', lines[1] || '');
            } else {
                this.setField('street.0', _.compact(lines).join(', '));
            }
        },

        /** True while the street lines hold an office (filled by us), whichever office it was. */
        isOfficeStreet: function () {
            var line = this.field('street.0'),
                prefix = $t('Speedy office %1: %2').split('%1')[0];

            return !!line && typeof line.value() === 'string' && line.value().indexOf(prefix) === 0;
        },

        officeLine: function (office) {
            return office ? $t('Speedy office %1: %2').replace('%1', office.id).replace('%2', office.name) : null;
        },

        /** Show/hide Magento's own fields for the current mode. Region and city are always ours. */
        applyFormVisibility: function () {
            var active = this.isAvailable(),
                office = active && state.mode() === 'office';

            if (!this.isFormInline()) {
                return;
            }
            this.setVisible('street', !office);
            this.setVisible('postcode', !office);
            this.setVisible('company', !office);
            this.setVisible('country_id', !office);
            this.setVisible('city', !active);
            if (active) {
                this.setVisible('region_id', false);
                this.setVisible('region', false);
            }
        },

        /** Copy the chosen site/office into the address (inline form) or build the office address (saved addresses). */
        fillFromChoice: function () {
            var site = state.site(),
                office = state.office(),
                postcode,
                saved;

            if (!site || !this.isAvailable()) {
                return;
            }
            if (this.isFormInline()) {
                this.setField('country_id', 'BG');
                this.setField('city', site.name);
                if (site['region_id']) {
                    this.setField('region_id', String(site['region_id']));
                }
                if (state.mode() === 'office') {
                    this.setStreet(office ? [this.officeLine(office), office['local_address']] : ['', '']);
                    this.setField('postcode', office && office['post_code'] || site['post_code']);
                } else {
                    postcode = this.field('postcode') ? this.field('postcode').value() : '';
                    if (!/^\d{4}$/.test(postcode || '') || postcode === this.autoPostcode) {
                        this.setField('postcode', site['post_code']);
                    }
                }
                this.autoPostcode = site['post_code'];
            } else if (state.mode() === 'office' && office && this.savedAddress) {
                saved = this.savedAddress;
                selectShippingAddressAction(addressConverter.formAddressDataToQuoteAddress({
                    firstname: saved.firstname,
                    lastname: saved.lastname,
                    telephone: saved.telephone,
                    street: {0: this.officeLine(office), 1: office['local_address']},
                    city: site.name,
                    postcode: office['post_code'] || site['post_code'],
                    'country_id': 'BG',
                    'region_id': site['region_id'],
                    'save_in_address_book': 0
                }));
            }
        },

        rememberSavedAddress: function (address) {
            if (address && address.getType && address.getType() === 'customer-address') {
                this.savedAddress = address;
                this.recipient({
                    name: $.trim(address.firstname + ' ' + address.lastname),
                    telephone: address.telephone
                });
            }
        },

        onShippingAddressChange: function (address) {
            if (this.isFormInline() || !address || !address.getType || address.getType() !== 'customer-address') {
                return;
            }
            this.rememberSavedAddress(address);
            if (state.mode() !== 'office') {
                this.resolveSiteFor(address);
            }
        },

        /** A saved address only has a city name: find the matching Speedy site for it. */
        resolveSiteFor: function (address) {
            var self = this,
                city = $.trim(address.city || '');

            if (city.length < 2 || state.site() && state.site().name.toLowerCase() === city.toLowerCase()) {
                return;
            }
            $.getJSON(this.siteUrl, {q: city}).done(function (response) {
                var items = response && response.items ? response.items : [],
                    exact = _.filter(items, function (site) {
                        return site.name.toLowerCase() === city.toLowerCase();
                    }),
                    match = _.find(exact, function (site) {
                        return site['post_code'] === address.postcode;
                    }) || (exact.length === 1 ? exact[0] : null);

                if (match) {
                    state.site(match);
                    self.siteTerm(self.siteLabel(match));
                    self.save();
                }
            });
        },

        refresh: function () {
            var active = this.isAvailable();

            $('body')
                .toggleClass('bsspeedy-active', active)
                .toggleClass('bsspeedy-mode-office', active && state.mode() === 'office')
                .toggleClass('bsspeedy-mode-address', active && state.mode() === 'address');
            this.applyFormVisibility();
            if (active && state.mode()) {
                this.selectRate(state.mode());
                this.fillFromChoice();
            }
        },

        /* ---------- validation (called by the shipping step's "Next") ---------- */

        validate: function () {
            var valid = true,
                element;

            if (!this.isAvailable()) {
                return true;
            }
            this.modeError('');
            this.siteError('');
            this.officeError('');
            if (!state.mode()) {
                this.modeError($t('Please choose delivery to an office/automat or to an address.'));
                valid = false;
            } else if (!state.site()) {
                this.siteError($t('Please choose your town or village from the list.'));
                valid = false;
            } else if (state.mode() === 'office' && !state.office()) {
                this.officeError($t('Please choose an office or automat.'));
                valid = false;
            } else {
                this.selectRate(state.mode());
                this.fillFromChoice();
            }
            if (!valid) {
                element = document.getElementById('bsspeedy');
                if (element) {
                    element.scrollIntoView({behavior: 'smooth', block: 'center'});
                }
            }

            return valid;
        },

        /* ---------- remember the last choice (per browser) ---------- */

        save: function () {
            try {
                window.localStorage.setItem(STORAGE_KEY, JSON.stringify({
                    mode: state.mode(),
                    site: state.site(),
                    office: state.office()
                }));
            } catch (e) {
                // storage unavailable: the choice just isn't remembered
            }
        },

        restore: function () {
            var data;

            try {
                data = JSON.parse(window.localStorage.getItem(STORAGE_KEY) || 'null');
            } catch (e) {
                data = null;
            }
            if (!data || data.mode !== 'office' && data.mode !== 'address' || !data.site || !data.site.id) {
                return;
            }
            state.mode(data.mode);
            state.site(data.site);
            state.office(data.mode === 'office' && data.office && data.office.id ? data.office : null);
            this.siteTerm(this.siteLabel(data.site));
            if (data.mode === 'office') {
                // Refreshes the list and drops the office if it closed since.
                this.loadOffices(data.site.id);
            }
        }
    });
});
