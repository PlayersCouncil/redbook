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

    /**
     * The form's query, without the empty fields and the defaults, so addresses stay short: a
     * group of toggles left as its data-default leaves its values out (Ketura, 2026-11-07)
     */
    function form_query()
    {
        var params = [], defaults = {};
        form.find('.rb-toggles[data-default]').each(function ()
        {
            var group = $(this), boxes = group.find('input[type=checkbox]'),
                on = boxes.filter(':checked').map(function () { return this.value; }).get().sort().join(','),
                wanted = String(group.data('default')).split(',').sort().join(',');
            if(on === wanted && boxes.length) {
                defaults[boxes.attr('name')] = true;
            }
        });
        $.each(form[0].elements, function (i, field)
        {
            if(!field.name || field.disabled || field.value === '' || field.type === 'button' || field.type === 'submit') {
                return;
            }
            if((field.type === 'checkbox' || field.type === 'radio') && !field.checked) {
                return;
            }
            // a field left at its data-default: the sort, the page size, My Decks' tab
            if(defaults[field.name] || (field.dataset.default !== undefined && field.value === field.dataset.default)) {
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
     * the change didn't come from them (Clear All, Back); otherwise they stay, and
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
            // and whatever else the page has: My Decks' tabs and actions (Ketura, 2026-11-07)
            _.each(_.omit(parts, 'top', 'fields', 'results'), function (html, part)
            {
                $('#browse-' + part).html(html);
            });
            $(document).trigger('browse:loaded');
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

    /**
     * A tag to search for (Ketura, 2026-11-06): typed into the Tags field, or clicked on a
     * deck's row; a chip carrying the hidden tags[] input
     */
    function add_tag(tag)
    {
        tag = $.trim(String(tag)).replace(/\s+/g, '');
        if(!tag || form.find('input[name="tags[]"]').filter(function () { return this.value === tag; }).length) {
            return false;
        }
        $('<span class="rb-card-pick rb-tag-pick"><input type="hidden" name="tags[]"><button type="button">&times;</button></span>')
            .find('input').val(tag).end()
            .prepend(document.createTextNode(tag))
            .find('button').attr('aria-label', Translator.trans('decklist.browse.remove')).end()
            .insertBefore('#browse-tag');
        return true;
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

    /** Sets the page up: the decklists page, and My Decks (ui.decks.js), which adds its own */
    ui.browse_setup = function browse_setup()
    {
        form = $('#browse-form');
        if(!form.length) {
            return;
        }
        window.history.replaceState({browse: true}, '', window.location.href);

        // the sort sits by the results, outside the form, so the handlers are on the document
        $(document).on('change', '[data-autosubmit]', function ()
        {
            // a group of toggles keeps one on: unticking the last would match nothing
            var group = $(this).closest('.rb-toggles').find('input[type=checkbox]');
            if(group.length && !group.filter(':checked').length) {
                group.not(this).prop('checked', true);
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
        $('#browse-fields').on('click', '#browse-cards, #browse-tags', function (event)
        {
            if(event.target === this) {
                $(this).find('input[type=text]').trigger('focus');
            }
        });
        // Enter in the Tags field adds the tag rather than submitting what's typed
        $('#browse-fields').on('keydown', '#browse-tag', function (event)
        {
            if(event.key === 'Enter') {
                event.preventDefault();
                var added = add_tag(this.value);
                this.value = '';
                if(added) {
                    apply();
                }
            }
        });
        $('#browse-results').on('click', '.rb-tag-link', function (event)
        {
            event.preventDefault();
            if(add_tag($(this).data('tag'))) {
                apply();
            }
        });

        // a tab (My Decks): its list, with the filters as they are in the form, even mid-change
        $(document).on('click', '#browse-tabs .rb-tab', function (event)
        {
            if(plain_click(event)) {
                event.preventDefault();
                form.find('input[name="tab"]').val($(this).data('tab'));
                load(list_url(form_query()), {fields: true});
            }
        });
        // Reset sets every filter; the column sorts and the pager set one thing
        $(document).on('click', '.rb-browse-clear', function (event)
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
            if($(event.target).closest('a, button, input, label, .rb-col-pick').length || window.getSelection().toString()) {
                return;
            }
            if(event.ctrlKey || event.metaKey || event.button === 1) {
                window.open($(this).data('href'));
            } else {
                window.location = $(this).data('href');
            }
        });
    };

    /** The list again, as it is: after something on it changed (My Decks) */
    ui.browse_reload = function browse_reload()
    {
        load(window.location.href, {push: false});
    };

    ui.browse_data_loaded = function browse_data_loaded()
    {
        dataReady = true;
        setup_card_picker();
    };

    ui.on_dom_loaded = function on_dom_loaded()
    {
        ui.browse_setup();
    };

    ui.on_data_loaded = function on_data_loaded()
    {
        ui.browse_data_loaded();
    };

    ui.on_all_loaded = function on_all_loaded()
    {
    };

})(app.ui, jQuery);
