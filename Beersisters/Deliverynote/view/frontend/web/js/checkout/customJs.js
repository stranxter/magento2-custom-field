define([
    'ko',
    'jquery',
    'uiComponent',
    'mage/url',
    'mage/cookies'
], function (ko, $, Component, url) {
    'use strict';

    /* Keep in sync with saveInQuote::MAX_NOTE_LENGTH — the server enforces this too. */
    var MAX_NOTE_LENGTH = 1000;

    return Component.extend({
        defaults: {
            template: 'Beersisters_Deliverynote/checkout/deliverynote',
            maxNoteLength: MAX_NOTE_LENGTH
        },

        initObservable: function () {
            this._super().observe({
                NoteVals: ko.observable('')
            });

            this.NoteVals.subscribe(function (newValue) {
                var noteVal = $.trim(newValue || '').substring(0, MAX_NOTE_LENGTH);

                $.ajax({
                    showLoader: true,
                    url: url.build('beersisters_deliverynote/checkout/saveInQuote'),
                    data: {
                        noteVal: noteVal,
                        /* Required: the controller validates the form key. */
                        form_key: $.mage.cookies.get('form_key')
                    },
                    type: 'POST',
                    dataType: 'json'
                }).fail(function () {
                    console.error('Beersisters_Deliverynote: could not save the delivery note.');
                });
            });

            return this;
        }
    });
});
