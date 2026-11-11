/* global _, app, Translator, Routing */

/**
 * A library's view (Ketura, 2026-11-08; reworked 2026-11-09): one decklist open at a time.
 * Opening a row (animated, CSS) fetches its deck and draws it as the decklist reader does
 * (app.deck, app.deck_editor read-only), in the one #deck, moved into that row; Previous and
 * Next open the neighbours, as does a deck in the contents, which also scrolls to it. The
 * address's #deck-N opens that one. The header holds Cheers, Favourite and Comment
 * (app.social) and Back to Top; its height places the open row's head under it.
 */
(function ui_library_view(ui, $)
{
    var data_ready = false, decks = {}, open_row = null, view, scroll_wanted = null;

    function draw(row)
    {
        var id = row.data('entry'), deck = decks[id];
        if(!deck || !data_ready || !open_row || !open_row.is(row)) {
            return;
        }
        row.find('.rb-lv-deck-slot').append($('#deck-toolbar'), $('#deck'));
        app.deck.init(deck);
        app.deck_editor.render();
        // the deck drawn makes the page longer: a scroll that ran short can finish now
        if(scroll_wanted && scroll_wanted.is(row)) {
            scroll_wanted = null;
            row[0].scrollIntoView({block: 'start', behavior: 'smooth'});
        }
    }

    /** Opens a row (closing any other); instant: no animation for the one closing */
    function open(row, scroll, instant)
    {
        if(open_row && open_row.is(row)) {
            close();
            return;
        }
        close(instant);
        open_row = row.addClass('is-open');
        row.find('.rb-lv-head').attr('aria-expanded', 'true');
        if(window.history.replaceState) {
            window.history.replaceState(null, '', '#' + row.attr('id'));
        }
        if(scroll) {
            scroll_to(row);
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

    /**
     * Scrolls a row to just under the header, once it has opened: while it is still sliding
     * open the page may not yet be long enough to bring it up that far
     */
    function scroll_to(row)
    {
        scroll_wanted = decks[row.data('entry')] && data_ready ? null : row;
        var done = false, go = function () {
            if(!done) {
                done = true;
                row[0].scrollIntoView({block: 'start', behavior: 'smooth'});
            }
        };
        row.find('.rb-lv-body').one('transitionend', go);
        setTimeout(go, 400);
    }

    /** What scrolls the page around an element: its nearest scrolling ancestor, or the page */
    function scroller(el)
    {
        for(var node = el.parentElement; node && node !== document.body; node = node.parentElement) {
            var overflow = getComputedStyle(node).overflowY;
            if((overflow === 'auto' || overflow === 'scroll') && node.scrollHeight > node.clientHeight) {
                return node;
            }
        }
        return document.scrollingElement || document.documentElement;
    }

    /**
     * From one open deck to another (Ketura, 2026-11-09): the new deck's head takes the place
     * the old one's had on the screen, at once, so nothing seems to move but the deck under it
     */
    function swap(row)
    {
        var old_head = open_row.find('.rb-lv-head')[0],
            before = old_head.getBoundingClientRect().top;
        row.addClass('no-anim');
        open(row, false, true);
        var head = row.find('.rb-lv-head')[0],
            box = scroller(head);
        box.scrollTop += head.getBoundingClientRect().top - before;
        setTimeout(function () { row.removeClass('no-anim'); }, 50);
    }

    /** Closes the open row; its deck stays in it until another row opens */
    function close(instant)
    {
        if(!open_row) {
            return;
        }
        var row = open_row;
        if(instant) {
            row.addClass('no-anim');
            setTimeout(function () { row.removeClass('no-anim'); }, 50);
        }
        row.removeClass('is-open').find('.rb-lv-head').attr('aria-expanded', 'false');
        open_row = null;
    }

    /** The header's height, so an open row's head sticks just under it */
    function measure()
    {
        view[0].style.setProperty('--rb-lv-header-h', Math.round($('#library-top').outerHeight()) + 'px');
    }

    function setup_social()
    {
        var post = {id: view.data('id'), token: view.data('token')};
        $('#social-like').on('click', function () { app.social.toggle(this, Routing.generate('library_like'), post); });
        $('#social-favorite').on('click', function () { app.social.toggle(this, Routing.generate('library_favorite'), post); });
        $('#social-comment').on('click', function (event) {
            event.preventDefault();
            app.comments.focus_form();
        });
        app.comments.setup({title: $.trim($('.rb-library-title').text())});
    }

    ui.on_dom_loaded = function on_dom_loaded()
    {
        view = $('.rb-library-view');
        app.deck_editor.setup('#deck', '#deck-toolbar', {readonly: true});
        measure();
        $(window).on('resize', _.debounce(measure, 100));
        var list = $('#library-list');
        /** A deck's row, opened and scrolled to; already open, scrolled to */
        function go_to(row)
        {
            if(!row.is(open_row)) {
                open(row, true, true);
            } else {
                row[0].scrollIntoView({block: 'start', behavior: 'smooth'});
            }
        }
        // a click on a deck's head does what its line in the contents does (Ketura, 2026-11-09)
        list.on('click', '.rb-lv-head', function () {
            go_to($(this).closest('.rb-lv-entry'));
        });
        list.on('click', '[data-step]', function () {
            var row = $(this).closest('.rb-lv-entry'), next = $(this).data('step') < 0 ? row.prev('.rb-lv-entry') : row.next('.rb-lv-entry');
            if(next.length) {
                open_row ? swap(next) : open(next, true, true);
            }
        });
        // a deck in the contents: its row, opened and scrolled to
        $('.rb-lv-toc').on('click', '.rb-lv-toc-link', function (event) {
            event.preventDefault();
            go_to($('#' + $(this).data('row')));
        });
        $('#btn-back-to-top').on('click', function () {
            $('#library-intro')[0].scrollIntoView({block: 'start', behavior: 'smooth'});
        });
        setup_social();
        var wanted = window.location.hash && list.find(window.location.hash.replace(/[^#\w-]/g, ''));
        if(wanted && wanted.length) {
            open(wanted, true);
        }
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
