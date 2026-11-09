/* global Translator, app */

(function ui_deck(ui, $)
{
    var dom_loaded = new $.Deferred(),
      data_loaded = new $.Deferred();

    /**
     * called when the DOM is loaded
     * @memberOf ui
     */
    ui.on_dom_loaded = function on_dom_loaded()
    {};

    /**
     * called when the app data is loaded
     * @memberOf ui
     */
    ui.on_data_loaded = function on_data_loaded()
    {};

    /**
     * called when both the DOM and the app data have finished loading
     * @memberOf ui
     */
    ui.on_all_loaded = function on_all_loaded()
    {};

    ui.insert_alert_message = function ui_insert_alert_message(type, message)
    {
        var alert = $('<div class="alert" role="alert"></div>').addClass('alert-' + type).append(message);
        $('#wrapper>div.container').first().prepend(alert);
    };

    /** Shows the deck as text (or as a Gemp deck string) in the export modal */
    ui.export_plaintext = function export_plaintext(deck) {
        $('#export-deck').text(deck.get_export('text'));
        $('#exportModal').modal('show');
    };

    /** Puts text on the clipboard; a promise either way */
    ui.copy_text = function copy_text(text) {
        if(navigator.clipboard && navigator.clipboard.writeText) {
            return navigator.clipboard.writeText(text).catch(function () { return fallback(); });
        }
        return fallback();

        function fallback() {
            var area = $('<textarea>').val(text).css({position: 'fixed', top: 0, left: 0, opacity: 0}).appendTo('body').select();
            try {
                document.execCommand('copy');
            } catch (e) {
                // nothing more to try
            }
            area.remove();
            return $.Deferred().resolve().promise();
        }
    };

    /** A short green note under an element that fades away (Ketura, 2026-11-04) */
    ui.toast = function toast(message, anchor) {
        $('.rb-toast').remove();
        var note = $('<div class="rb-toast" role="status"></div>').text(message).appendTo('body'),
            box = anchor ? anchor.getBoundingClientRect() : null;
        if(box) {
            note.css({top: box.bottom + 6, left: Math.max(8, Math.min(box.left + box.width / 2 - note.outerWidth() / 2, window.innerWidth - note.outerWidth() - 8))});
        }
        setTimeout(function () { note.addClass('is-leaving'); }, 1600);
        setTimeout(function () { note.remove(); }, 2100);
    };

    /**
     * A menu or popover hung off its button against the window, not the pane it's in, so a
     * scrolling pane can't cut it off (Ketura, 2026-11-03; shared 2026-11-09): below the
     * button, or above it when there's no room below
     */
    ui.float_below = function float_below(button, popup, align_right) {
        var rect = $(button)[0].getBoundingClientRect(),
            el = $(popup),
            width = el.outerWidth(),
            height = el.outerHeight(),
            left = align_right ? rect.right - width : rect.left,
            top = rect.bottom + 4;
        left = Math.max(8, Math.min(left, window.innerWidth - width - 8));
        if(top + height > window.innerHeight - 8 && rect.top - 4 - height > 8) {
            top = rect.top - 4 - height;
        }
        el.css({position: 'fixed', left: left + 'px', top: top + 'px', right: 'auto'});
    };

    /**
     * Copy Text (Ketura, 2026-11-04; shared 2026-11-09): the deck on the page, as a list,
     * straight onto the clipboard, wherever the reader's buttons are (Decklist/reader-copy-buttons)
     */
    $(document).on('click', '[data-copy-list]', function (event) {
        event.preventDefault();
        var anchor = $(this).is(':visible') ? this : $(this).closest('.rb-reader-buttons-narrow').find('.dropdown-toggle')[0];
        ui.copy_text(app.deck.get_export('list')).then(function () {
            ui.toast(Translator.trans('decklist.reader.copied'), anchor);
        });
    });

    // the top bar's menu button (Ketura, 2026-11-09): below 1100px the sections fold into it;
    // a click outside the bar, or Escape, folds them again
    $(document).on('click', '.rb-burger', function (event) {
        event.stopPropagation();
        var open = !$('body').hasClass('rb-menu-open');
        $('body').toggleClass('rb-menu-open', open);
        $(this).attr('aria-expanded', open ? 'true' : 'false');
    });
    $(document).on('click', function (event) {
        if ($('body').hasClass('rb-menu-open') && !$(event.target).closest('.rb-topbar').length) {
            $('body').removeClass('rb-menu-open');
            $('.rb-burger').attr('aria-expanded', 'false');
        }
    });
    $(document).on('keydown', function (event) {
        if (event.key === 'Escape' && $('body').hasClass('rb-menu-open')) {
            $('body').removeClass('rb-menu-open');
            $('.rb-burger').attr('aria-expanded', 'false').trigger('focus');
        }
    });

    // tabs made of buttons (the design's .rb-tabs, Ketura 2026-11-09): each shows the panel it
    // controls and hides its siblings' panels
    $(document).on('click', '.rb-tabs[role=tablist] > button[aria-controls]', function () {
        var tab = $(this);
        tab.siblings('[aria-controls]').each(function () {
            $(this).attr('aria-selected', 'false');
            $('#' + $(this).attr('aria-controls')).prop('hidden', true);
        });
        tab.attr('aria-selected', 'true');
        $('#' + tab.attr('aria-controls')).prop('hidden', false);
    });

    /** One of the theme's tokens as it is now, e.g. ui.token('--rb-fp-solid'), for code that
        needs a colour value (charts); it changes with the theme */
    ui.token = function token(name) {
        return getComputedStyle(document.documentElement).getPropertyValue(name).trim();
    };

    /** A text size given in the design's px, in the page's current px (Ketura, 2026-11-09):
        for code that must give a number, like the charts; follows the root text size */
    ui.text_px = function text_px(px) {
        return px * (parseFloat(getComputedStyle(document.documentElement).fontSize) || 16) / 16;
    };

    ui.export_gemp = function export_gemp(deck) {
        $('#export-deck').text(deck.get_export('gemp'));
        $('#exportModal').modal('show');
    };

    $(document).ready(function ()
    {
        $('[data-toggle="tooltip"]').tooltip();
        // dates as "3 days ago", with the full date on hover; data-absolute keeps a date as written
        $('time:not([data-absolute])').each(function (index, element)
        {
            var datetime = moment($(element).attr('datetime'));
            $(element).html(datetime.fromNow());
            $(element).attr('title', datetime.format('LLLL'));
        });
        if(typeof ui.on_dom_loaded === 'function')
            ui.on_dom_loaded();
        dom_loaded.resolve();
    });
    $(document).on('data.app', function ()
    {
        if(typeof ui.on_data_loaded === 'function')
            ui.on_data_loaded();
        data_loaded.resolve();
    });
    $(document).on('start.app', function () {
        var title, keyword;
        if (typeof ui.on_all_loaded === 'function') {
            ui.on_all_loaded();
        }
        $('abbr').each(function (index, element) {
            if ($(this).data('title')) {
                title = $(this).data('title');
            } else if ($(this).data('keyword')) {
                keyword = $(this).data('keyword');
                title = Translator.trans('keyword.' + keyword + '.title');
            }
            if (title) {
                $(element).attr('title', title).tooltip();
            }
        });
    });
    $.when(dom_loaded, data_loaded).done(function ()
    {
        setTimeout(function ()
        {
            $(document).trigger('start.app');
        }, 0);
    });

})(app.ui = {}, jQuery);
