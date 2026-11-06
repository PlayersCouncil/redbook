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
