define(
    [
        'ko',
        'jquery',
        'uiComponent',
        'mage/url'
    ],
    function (ko, $, Component,url) {
        'use strict';
        return Component.extend({
            defaults: {
                template: 'Beersisters_Deliverynote/checkout/deliverynote'
            },
            initObservable: function () {

                this._super()
                    .observe({
                        NoteVals: ko.observable("")
                    });
                var noteVal="";
                self = this;
                this.NoteVals.subscribe(function (newValue) {
                    var linkUrls  = url.build('module/checkout/saveInQuote');
                    noteVal = newValue;
                    $.ajax({
                        showLoader: true,
                        url: linkUrls,
                        data: {noteVal : noteVal},
                        type: "POST",
                        dataType: 'json'
                    }).done(function (data) {
                        console.log('success');
                    });
                });
                return this;
            }
        });
    }
);