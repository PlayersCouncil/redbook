/* global _, app, Translator */

/**
 * A library's view (Ketura, 2026-11-08): one decklist open at a time. Opening a row fetches its
 * deck and draws it as the decklist reader does (app.deck, app.deck_editor read-only), in the
 * one #deck, moved into that row; Previous and Next open the neighbours. The address's
 * #deck-N opens that one.
 */
(function ui_library_view(ui, $)
{
    var data_ready = false, decks = {}, open_row = null;

    function draw(row)
    {
        var id = row.data('entry'), deck = decks[id];
        if(!deck || !data_ready || !open_row || !open_row.is(row)) {
            return;
        }
        row.find('.rb-lv-deck-slot').append($('#deck-toolbar'), $('#deck'));
        app.deck.init(deck);
        app.deck_editor.render();
    }

    function open(row, scroll)
    {
        if(open_row && open_row.is(row)) {
            close();
            return;
        }
        close();
        open_row = row.addClass('is-open');
        row.find('.rb-lv-head').attr('aria-expanded', 'true');
        row.find('.rb-lv-body').prop('hidden', false);
        if(window.history.replaceState) {
            window.history.replaceState(null, '', '#' + row.attr('id'));
        }
        if(scroll) {
            row[0].scrollIntoView({block: 'start', behavior: 'smooth'});
        }
        var id = row.data('entry');
        if(decks[id]) {
            return draw(row);
        }
        $('#deck').html('<div class="rb-reader-loading">' + _.escape(Translator.trans('decklist.reader.loading')) + '</div>');
        row.find('.rb-lv-deck-slot').append($('#deck-toolbar'), $('#deck'));
        $.getJSON(row.data('url')).done(function (deck) {
            decks[id] = deck;
            draw(row);
        }).fail(function () {
            $('#deck').html('<p class="rb-muted">' + _.escape(Translator.trans('library.view.deck_failed')) + '</p>');
        });
    }

    function close()
    {
        if(!open_row) {
            return;
        }
        $('.rb-lv-deck-home').append($('#deck-toolbar'), $('#deck'));
        open_row.removeClass('is-open').find('.rb-lv-head').attr('aria-expanded', 'false');
        open_row.find('.rb-lv-body').prop('hidden', true);
        open_row = null;
    }

    ui.on_dom_loaded = function on_dom_loaded()
    {
        app.deck_editor.setup('#deck', '#deck-toolbar', {readonly: true});
        var list = $('#library-list');
        list.on('click', '.rb-lv-head', function () {
            open($(this).closest('.rb-lv-entry'), false);
        });
        list.on('click', '[data-step]', function () {
            var row = $(this).closest('.rb-lv-entry'), next = $(this).data('step') < 0 ? row.prev('.rb-lv-entry') : row.next('.rb-lv-entry');
            if(next.length) {
                open(next, true);
            }
        });
        var wanted = window.location.hash && list.find(window.location.hash.replace(/[^#\w-]/g, ''));
        open(wanted && wanted.length ? wanted : list.find('.rb-lv-entry').first(), !!(wanted && wanted.length));
    };

    ui.on_data_loaded = function on_data_loaded()
    {
    };

    ui.on_all_loaded = function on_all_loaded()
    {
        data_ready = true;
        open_row && draw(open_row);
    };
})(app.ui, jQuery);
