/**
 * IABook readers uses URL fragments to trigger modes and page locations.
 * This interferes with Drupal's collapse

 **/

(function ($, Modernizr, Drupal, debounce) {
    function CollapsibleDetails(node) {
        this.$node = $(node);
        this.$node.data('details', this);

        var anchor = window.location.hash && window.location.hash !== '#' ? ', ' + window.location.hash : '';

        try {
            if (this.$node.find('.error' + anchor).length) {
                this.$node.attr('open', true);
            }

        }
        catch(err) {
            // Ignore exception
        }


        this.setupSummary();

        this.setupLegend();
    }

    $.extend(CollapsibleDetails, {
        instances: []
    });

    $.extend(CollapsibleDetails.prototype, {
        setupSummary: function setupSummary() {
            this.$summary = $('<span class="summary"></span>');
            this.$node.on('summaryUpdated', $.proxy(this.onSummaryUpdated, this)).trigger('summaryUpdated');
        },
        setupLegend: function setupLegend() {
            var $legend = this.$node.find('> summary');

            $('<span class="details-summary-prefix visually-hidden"></span>').append(this.$node.attr('open') ? Drupal.t('Hide') : Drupal.t('Show')).prependTo($legend).after(document.createTextNode(' '));

            $('<a class="details-title"></a>').attr('href', '#' + this.$node.attr('id')).prepend($legend.contents()).appendTo($legend);

            $legend.append(this.$summary).on('click', $.proxy(this.onLegendClick, this));
        },
        onLegendClick: function onLegendClick(e) {
            this.toggle();
            e.preventDefault();
        },
        onSummaryUpdated: function onSummaryUpdated() {
            var text = $.trim(this.$node.drupalGetSummary());
            this.$summary.html(text ? ' (' + text + ')' : '');
        },
        toggle: function toggle() {
            var _this = this;

            var isOpen = !!this.$node.attr('open');
            var $summaryPrefix = this.$node.find('> summary span.details-summary-prefix');
            if (isOpen) {
                $summaryPrefix.html(Drupal.t('Show'));
            } else {
                $summaryPrefix.html(Drupal.t('Hide'));
            }

            setTimeout(function () {
                _this.$node.attr('open', !isOpen);
            }, 0);
        }
    });

    Drupal.behaviors.collapse = {
        attach: function attach(context) {
            if (Modernizr.details) {
                return;
            }
            var $collapsibleDetails = $(context).find('details').once('collapse').addClass('collapse-processed');
            if ($collapsibleDetails.length) {
                for (var i = 0; i < $collapsibleDetails.length; i++) {
                    CollapsibleDetails.instances.push(new CollapsibleDetails($collapsibleDetails[i]));
                }
            }
        }
    };

    var handleFragmentLinkClickOrHashChange = function handleFragmentLinkClickOrHashChange(e, $target) {
        try {
            $target.parents('details').not('[open]').find('> summary').trigger('click');
        }
        catch(err) {
            // Ignore exception
        }
    };

    $('body').on('formFragmentLinkClickOrHashChange.details', handleFragmentLinkClickOrHashChange);


    var handleFragmentLinkClickOrHashChangeFormOverride = function handleFragmentLinkClickOrHashChange(e) {
        var url = void 0;
        if (e.type === 'click') {
            url = e.currentTarget.location ? e.currentTarget.location : e.currentTarget;
        } else {
            url = window.location;
        }
        var hash = url.hash.substr(1);
        if (hash) {
            try {
                var $target = $('#' + hash);
                $('body').trigger('formFragmentLinkClickOrHashChange', [$target]);

                setTimeout(function () {
                    return $target.trigger('focus');
                }, 300);
            }
            catch(err) {
                // Ignore exception
            }
        }
    };

    // Important. Disables form.js hash events handle
    // core/misc/form.js WindowEventHandlersEventMap.hashchange
    $(window).off('hashchange.form-fragment');

    //Re enable with smarter logic
    var debouncedHandleFragmentLinkClickOrHashChange = debounce(handleFragmentLinkClickOrHashChangeFormOverride, 300, true);

    $(window).on('hashchange.form-fragment', debouncedHandleFragmentLinkClickOrHashChange);


    Drupal.CollapsibleDetails = CollapsibleDetails;
})(jQuery, Modernizr, Drupal, Drupal.debounce);