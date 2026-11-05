/* global app, _ */

/**
 * The decklists page (Ketura, 2026-11-05): the card picker, filters that apply as they
 * change, clean addresses, and rows that open their deck.
 */
(function ui_decklist_search(ui, $)
{
    var form;

    /** A picked card: a chip carrying the hidden cards[] input */
    function add_card(card)
    {
        if(form.find('input[name="cards[]"][value="' + card.code + '"]').length) {
            return;
        }
        $('<span class="rb-card-pick"><input type="hidden" name="cards[]"><button type="button">&times;</button></span>')
            .find('input').val(card.code).end()
            .prepend(document.createTextNode(card.label))
            .find('button').attr('aria-label', Translator.trans('decklist.browse.remove')).end()
            .insertBefore('#browse-card');
        submit();
    }

    function setup_card_picker()
    {
        var input = $('#browse-card');
        input.typeahead({hint: true, highlight: true, minLength: 2}, {
            name: 'cardnames',
            displayKey: 'label',
            source: function (q, cb)
            {
                var regexp = new RegExp(q.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'), 'i');
                // one suggestion per name: a search finds every printing of it
                cb(_.uniq(app.data.cards.find({label: regexp}, {$orderBy: {label: 1}}), false, 'label').slice(0, 12));
            }
        });
        input.on('typeahead:selected typeahead:autocompleted', function (event, card)
        {
            input.typeahead('val', '');
            add_card(card);
        });
    }

    /**
     * Submits without the empty fields and the defaults, so addresses stay short and the
     * presets can recognise them
     */
    function submit()
    {
        var kinds = form.find('input[name="kind[]"]');
        if(kinds.filter(':checked').length === kinds.length) {
            kinds.prop('disabled', true);
        }
        form.find('input, select').each(function ()
        {
            if(this.name && (this.value === '' || (this.name === 'sort' && this.value === 'new'))) {
                this.disabled = true;
            }
        });
        form.trigger('submit');
    }

    ui.on_dom_loaded = function on_dom_loaded()
    {
        form = $('#browse-form');
        // back to this page from the browser's cache: the fields submit() turned off come back
        $(window).on('pageshow', function ()
        {
            form.find(':disabled').prop('disabled', false);
            form.removeData('cleaned');
        });
        form.on('change', '[data-autosubmit]', function ()
        {
            // at least one kind stays on: unticking the last would match nothing
            var kinds = form.find('input[name="kind[]"]');
            if(this.name === 'kind[]' && !kinds.filter(':checked').length) {
                kinds.not(this).prop('checked', true);
            }
            submit();
        });
        form.on('submit', function ()
        {
            if(!form.data('cleaned')) {
                form.data('cleaned', true);
                submit();
                return false;
            }
        });
        form.on('click', '.rb-filters-toggle', function ()
        {
            var open = form.toggleClass('is-open').hasClass('is-open');
            $(this).attr('aria-expanded', open ? 'true' : 'false');
        });
        form.on('click', '.rb-card-pick button', function ()
        {
            $(this).closest('.rb-card-pick').remove();
            submit();
        });
        $('#browse-cards').on('click', function (event)
        {
            if(event.target === this) {
                $('#browse-card').trigger('focus');
            }
        });
        // a row opens its deck; the name stays a real link for new tabs and keyboards
        $('.rb-decks tbody').on('click', 'tr[data-href]', function (event)
        {
            if($(event.target).closest('a, button').length || window.getSelection().toString()) {
                return;
            }
            if(event.ctrlKey || event.metaKey || event.button === 1) {
                window.open($(this).data('href'));
            } else {
                window.location = $(this).data('href');
            }
        });
    };

    ui.on_data_loaded = function on_data_loaded()
    {
        setup_card_picker();
    };

    ui.on_all_loaded = function on_all_loaded()
    {
    };

})(app.ui, jQuery);
