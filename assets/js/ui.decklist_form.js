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
        ui.setup_thumbnail_picker();
        ui.setup_derived_picker();
        ui.setup_splitter();
        ui.setup_apply_prompt();
    };

    /**
     * Saving a changed decklist whose deck has a private revision (Ketura, 2026-11-08): ask
     * whether the revision takes the changes too. The form as it was is taken at the first
     * touch, once its fields are filled in; saving it unchanged doesn't ask.
     */
    ui.setup_apply_prompt = function setup_apply_prompt()
    {
        var form = $('#decklist-form'), modal = $('#apply-to-deck-modal'), baseline = null, decided = false;
        if(!form.data('private-revision') || !modal.length) {
            return;
        }
        function state()
        {
            return $(form[0].elements).not('[name=_token], #apply-to-deck').serialize();
        }
        function take()
        {
            if(baseline === null) {
                baseline = state();
            }
        }
        $(document).one('focusin mousedown keydown', take);
        $(window).on('load', function () { setTimeout(take, 500); });
        form.on('submit', function (event) {
            if(decided || baseline === null || state() === baseline) {
                return;
            }
            event.preventDefault();
            modal.modal('show');
        });
        modal.on('click', '[data-apply]', function () {
            $('#apply-to-deck').val($(this).data('apply') + '');
            decided = true;
            modal.modal('hide');
            form[0].requestSubmit ? form[0].requestSubmit() : form.trigger('submit');
        });
    };

    /** A decklist as the form refers to it: name (linked), author, date, version, tags (Templates: Decklist/deck-ref) */
    ui.deck_ref = function deck_ref(d, link)
    {
        var tags = _.filter(d.tags || [], _.identity);
        return (link === false ? '<span class="rb-derived-name">' + _.escape(d.name) + '</span>'
                : '<a href="' + d.url + '" target="_blank" class="rb-derived-name">' + _.escape(d.name) + '</a>')
            + '<span class="rb-derived-meta">' + _.escape(d.author) + ' &middot; ' + d.date + ' &middot; v' + d.version + '.0' + '</span>'
            + (tags.length ? '<span class="rb-derived-tags">' + _.map(tags, function (tag) { return '<span class="rb-tag-chip">' + _.escape(tag) + '</span>'; }).join('') + '</span>' : '');
    };

    /** The thumbnail picker and its crop editor, shared with the deckbuilder (app.thumbnail.picker) */
    ui.setup_thumbnail_picker = function setup_thumbnail_picker()
    {
        app.thumbnail.picker({scroll: '.rb-publish-scroll'});
    };

    /**
     * The line between the deck and the form, dragged to share the width between them
     * (Ketura, 2026-11-07); the share is kept in this browser
     */
    ui.setup_splitter = function setup_splitter()
    {
        var side = $('.rb-publish-side'), row = side.parent();
        ui.splitter('#publish-splitter', {
            key: 'rb-publish-width-2',
            min: 420,
            max: function () { return row.width() - 360; },
            current: function () { return side.outerWidth(); },
            direction: -1,
            set: function (w) { side.css('flex-basis', w === null ? '' : w + 'px'); }
        });
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
            return ui.deck_ref(d);
        }
        // several picks (Ketura, 2026-11-08): each a card with Remove; the hidden field lists their ids
        function sync()
        {
            $('#precedents').val($('#derived-chosen .rb-derived-pick').map(function () { return $(this).data('id'); }).get().join(','));
        }
        function choose(d)
        {
            if(!$('#derived-chosen .rb-derived-pick[data-id="' + d.id + '"]').length) {
                $('<div class="rb-derived-pick">').attr('data-id', d.id)
                    .append($('<div class="rb-derived-card">').html(card(d)))
                    .append($('<button type="button" class="btn btn-link btn-sm rb-derived-remove">').text(Translator.trans('decklist.form.derived_clear')))
                    .appendTo('#derived-chosen');
                sync();
            }
            $('#derived-author, #derived-name').val('');
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
                        .html(ui.deck_ref(d, false))
                        .on('click', function () { choose(d); })
                        .appendTo(results);
                });
            });
        }
        $('#derived-author, #derived-name').on('input', function () {
            clearTimeout(timer);
            timer = setTimeout(search, 250);
        });
        $('#derived-chosen').on('click', '.rb-derived-remove', function () {
            $(this).closest('.rb-derived-pick').remove();
            sync();
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
        app.deck_notes.setup(_.debounce(function () { app.data.cards && app.deck_editor.render(); }, 300));
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
