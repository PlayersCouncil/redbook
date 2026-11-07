/* global Translator, _, app */
/**
 * The decklist form (Ketura, 2026-11-07): the deck on the left, read-only, as the
 * deckbuilder draws it; on the right the form, whose notes are the deckbuilder's Notes tab
 * (app.deck_notes) run on the decklist.
 */
(function ui_decklist_form(ui, $)
{
    /**
     * sets up event handlers ; dataloaded not fired yet
     * @memberOf ui
     */
    ui.setup_event_handlers = function setup_event_handlers()
    {
        // the thumbnail: the chosen card's top half, or the deck's cultures
        $('#thumbnail').on('change', function () {
            var image = $(this).find('option:selected').data('image');
            $('#thumb-card').prop('hidden', !image).find('img').attr('src', image || '');
            $('#thumb-cultures').prop('hidden', !!image);
        }).trigger('change');

        ui.setup_version_line();
        ui.setup_derived_picker();
    };

    /**
     * Publishing: "Publishing version 2 of X" while a line is picked, "a new decklist" when
     * it's cleared (Ketura, 2026-11-07)
     */
    ui.setup_version_line = function setup_version_line()
    {
        var line = $('#version-line'), select = $('#previous_version');
        if(!select.length) {
            return;
        }
        select.on('change', function () {
            var option = select.find('option:selected');
            if(option.val()) {
                line.html(line.data('of').replace('__V__', option.data('next')).replace('__N__', _.escape(option.data('name'))));
            } else {
                line.html(line.data('new'));
            }
        }).trigger('change');
    };

    /**
     * Derived from (Ketura, 2026-11-07): the author and deck-name fields search public
     * decklists; an id or a decklist's address in the name field picks that one. The pick
     * shows as a card, with Clear to search again.
     */
    ui.setup_derived_picker = function setup_derived_picker()
    {
        var box = $('.rb-derived'), results = $('#derived-results'), timer = null, request = null;
        if(!box.length) {
            return;
        }
        function card(d)
        {
            return '<a href="' + d.url + '" target="_blank" class="rb-derived-name">' + _.escape(d.name) + '</a>'
                + '<span class="rb-derived-meta">v' + d.version + ' &middot; ' + _.escape(d.author) + ' &middot; ' + d.date + '</span>';
        }
        function choose(d)
        {
            $('#precedent').val(d.id);
            $('#derived-chosen').prop('hidden', false).find('.rb-derived-card').html(card(d));
            $('#derived-search').prop('hidden', true);
            results.prop('hidden', true).empty();
        }
        function search()
        {
            var author = $.trim($('#derived-author').val()), name = $.trim($('#derived-name').val());
            var params = {except: box.data('except') || ''};
            if(/^\d+$/.test(name) || /\/decklist\/view\/\d+/.test(name)) {
                params.id = name;
            } else if(author.length >= 2 || name.length >= 2) {
                params.author = author;
                params.name = name;
            } else {
                results.prop('hidden', true).empty();
                return;
            }
            if(request) {
                request.abort();
            }
            request = $.getJSON(box.data('lookup'), params, function (found) {
                results.empty().prop('hidden', false);
                if(params.id && found.length === 1) {
                    choose(found[0]);
                    return;
                }
                if(!found.length) {
                    results.append('<div class="list-group-item text-muted">' + Translator.trans('decklist.form.derived_none') + '</div>');
                }
                found.forEach(function (d) {
                    $('<button type="button" class="list-group-item rb-derived-result">')
                        .html(card(d).replace(/<a [^>]*>/, '<span class="rb-derived-name">').replace('</a>', '</span>'))
                        .on('click', function () { choose(d); })
                        .appendTo(results);
                });
            });
        }
        $('#derived-author, #derived-name').on('input', function () {
            clearTimeout(timer);
            timer = setTimeout(search, 250);
        });
        $('#derived-clear').on('click', function () {
            $('#precedent').val('');
            $('#derived-chosen').prop('hidden', true);
            $('#derived-search').prop('hidden', false);
            $('#derived-name').focus();
        });
    };

    /**
     * called when the DOM is loaded
     * @memberOf ui
     */
    ui.on_dom_loaded = function on_dom_loaded()
    {
        ui.setup_event_handlers();
        app.deck_editor.setup('#deck', '#deck-toolbar', {readonly: true});
        // an annotation shows as a mark on its card in the deck
        app.deck_notes.setup(_.debounce(function () { app.deck_editor.render(); }, 300));
    };

    /**
     * called when the app data is loaded
     * @memberOf ui
     */
    ui.on_data_loaded = function on_data_loaded()
    {
    };

    /**
     * called when both the DOM and the data app have finished loading
     * @memberOf ui
     */
    ui.on_all_loaded = function on_all_loaded()
    {
        app.deck_notes.fill();
        app.deck_editor.render();
    };

})(app.ui, jQuery);
