/* global app, _, Translator */

/**
 * The decklists page (Ketura, 2026-11-05). Changing a filter, the sort or the page doesn't
 * load the page again: the results dim under a spinner while the server sends the parts that
 * changed, which are swapped in, and the address follows so Back and links work. Without
 * script the form and links still work as plain pages.
 */
(function ui_decklist_search(ui, $)
{
    var form, dataReady = false, request = null;

    /** The form's query, without the empty fields and the defaults, so addresses stay short */
    function form_query()
    {
        var params = [], kinds = form.find('input[name="kind[]"]');
        var allKinds = kinds.filter(':checked').length === kinds.length;
        $.each(form[0].elements, function (i, field)
        {
            if(!field.name || field.disabled || field.value === '' || field.type === 'button' || field.type === 'submit') {
                return;
            }
            if((field.type === 'checkbox' || field.type === 'radio') && !field.checked) {
                return;
            }
            if((field.name === 'sort' && field.value === 'new') || (field.name === 'kind[]' && allKinds)) {
                return;
            }
            params.push(encodeURIComponent(field.name) + '=' + encodeURIComponent(field.value));
        });
        return params.length ? '?' + params.join('&') : '';
    }

    function list_url(query)
    {
        return form.attr('action') + query;
    }

    /**
     * Fetches the list at url and swaps it in. fields: also replace the filter fields, when
     * the change didn't come from them (a preset, Clear All, Back); otherwise they stay, and
     * with them whatever has the focus.
     */
    function load(url, options)
    {
        options = _.extend({push: true, fields: false, scroll: false}, options);
        var results = $('#browse-results');
        if(request) {
            request.abort();
        }
        results.addClass('is-loading').attr('aria-busy', 'true');
        if(options.scroll && results.offset().top < $(window).scrollTop()) {
            $('html, body').scrollTop(results.offset().top - 10);
        }
        request = $.ajax({url: url, dataType: 'json', cache: true});
        request.done(function (parts)
        {
            $('#browse-top').html(parts.top);
            $('#browse-top .rb-filters-toggle').attr('aria-expanded', form.hasClass('is-open') ? 'true' : 'false');
            if(options.fields) {
                $('#browse-fields').html(parts.fields);
                setup_card_picker();
            }
            results.html(parts.results);
            if(options.push) {
                window.history.pushState({browse: true}, '', url);
            }
        });
        request.fail(function (xhr, status)
        {
            if(status !== 'abort') {
                window.location = url;
            }
        });
        request.always(function (data, status)
        {
            if(status !== 'abort') {
                results.removeClass('is-loading').removeAttr('aria-busy');
                request = null;
            }
        });
    }

    function apply()
    {
        load(list_url(form_query()));
    }

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
        apply();
    }

    function setup_card_picker()
    {
        var input = $('#browse-card');
        if(!dataReady || !input.length) {
            return;
        }
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

    /** A plain click on a link: one that should open in this tab */
    function plain_click(event)
    {
        return !(event.ctrlKey || event.metaKey || event.shiftKey || event.altKey || event.button > 0);
    }

    ui.on_dom_loaded = function on_dom_loaded()
    {
        form = $('#browse-form');
        if(!form.length) {
            return;
        }
        window.history.replaceState({browse: true}, '', window.location.href);

        // the sort sits by the results, outside the form, so the handlers are on the document
        $(document).on('change', '[data-autosubmit]', function ()
        {
            // at least one kind stays on: unticking the last would match nothing
            var kinds = form.find('input[name="kind[]"]');
            if(this.name === 'kind[]' && !kinds.filter(':checked').length) {
                kinds.not(this).prop('checked', true);
            }
            apply();
        });
        form.on('submit', function (event)
        {
            event.preventDefault();
            apply();
        });
        form.on('click', '.rb-card-pick button', function ()
        {
            $(this).closest('.rb-card-pick').remove();
            apply();
        });
        form.on('click', '.rb-filters-toggle', function ()
        {
            var open = form.toggleClass('is-open').hasClass('is-open');
            $(this).attr('aria-expanded', open ? 'true' : 'false');
        });
        $('#browse-fields').on('click', '#browse-cards', function (event)
        {
            if(event.target === this) {
                $('#browse-card').trigger('focus');
            }
        });

        // presets and Clear All set every filter; the column sorts and the pager set one thing
        $(document).on('click', '.rb-preset, .rb-browse-clear', function (event)
        {
            if(plain_click(event)) {
                event.preventDefault();
                load(this.href, {fields: true});
            }
        });
        $('#browse-results').on('click', '.rb-decks th a, .rb-pager a', function (event)
        {
            if(plain_click(event)) {
                event.preventDefault();
                load(this.href, {scroll: true});
            }
        });
        $('#browse-results').on('submit', '.rb-page-jump', function (event)
        {
            event.preventDefault();
            var page = parseInt($(this).find('input[name="page"]').val(), 10);
            var max = parseInt($(this).find('input[name="page"]').attr('max'), 10);
            if(page > 0) {
                $(this).find('input[name="page"]').val(Math.min(page, max));
                load(form.attr('action') + '?' + $(this).serialize(), {scroll: true});
            }
        });

        // Back and Forward: the list for that address, fields and all
        $(window).on('popstate', function (event)
        {
            if(event.originalEvent.state && event.originalEvent.state.browse) {
                load(window.location.href, {push: false, fields: true});
            }
        });

        // a row opens its deck; the name stays a real link for new tabs and keyboards
        $('#browse-results').on('click', 'tr[data-href]', function (event)
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
        dataReady = true;
        setup_card_picker();
    };

    ui.on_all_loaded = function on_all_loaded()
    {
    };

})(app.ui, jQuery);
