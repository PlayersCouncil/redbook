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
        ui.setup_thumbnail_crop();
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

    /**
     * The thumbnail (Ketura, 2026-11-07): a list of its own. Open, hovering an option previews
     * it; the wheel over the closed control steps one option per notch; the cards are in the
     * deck list's order (type, the starting companions first, then name; sites by site
     * number, shadow number, name).
     */
    ui.setup_thumbnail_picker = function setup_thumbnail_picker()
    {
        var input = $('#thumbnail'), button = $('#thumb-select'), list = $('#thumb-options');
        if(!list.length) {
            return;
        }
        function options()
        {
            return list.find('.rb-thumb-option');
        }
        function preview(option)
        {
            var image = option.data('image');
            var img = $('#thumb-card').prop('hidden', !image).attr('class', 'rb-thumb rb-thumb-card rb-thumb-tpl-' + (option.data('template') || 'modifier'))
                .find('img').attr('src', image || '');
            ui.thumb_crop_style(img, option.data('value') + '');
            $('#thumb-cultures').prop('hidden', !!image);
        }
        function current()
        {
            var value = input.val() + '';
            return options().filter(function () { return ($(this).data('value') + '') === value; }).first();
        }
        function choose(option)
        {
            input.val(option.data('value') + '');
            options().attr('aria-selected', 'false').removeClass('is-active');
            option.attr('aria-selected', 'true');
            button.find('.rb-thumb-current').text(option.text());
            preview(option);
            // a card's framing can be adjusted; the banner's can't
            $('#thumb-edit').prop('hidden', !option.data('image'));
        }
        function sort()
        {
            var starting = app.deck && app.deck.is_starting ? app.deck.is_starting : function () { return false; };
            list.find('.rb-thumb-group').each(function () {
                var group = $(this).data('group'),
                    items = options().filter('[data-group="' + group + '"]').get();
                items.sort(function (a, b) {
                    var x = $(a), y = $(b);
                    if(group === 'sites') {
                        return x.data('site') - y.data('site') || x.data('shadow') - y.data('shadow') || x.text().localeCompare(y.text());
                    }
                    return x.data('rank') - y.data('rank')
                        || (starting(x.data('value') + '') ? 0 : 1) - (starting(y.data('value') + '') ? 0 : 1)
                        || x.text().localeCompare(y.text());
                });
                $(this).after(items);
            });
        }
        function open(on)
        {
            if(on) {
                sort();
                list.prop('hidden', false);
                // fixed to the window, so the scrolling pane doesn't cut it off: below the
                // button, or above it when there's more room there
                var rect = button[0].getBoundingClientRect(),
                    below = window.innerHeight - rect.bottom - 12, above = rect.top - 12,
                    up = below < 220 && above > below,
                    height = Math.min(340, up ? above : below);
                list.css({position: 'fixed', left: rect.left, width: rect.width, right: 'auto', maxHeight: height,
                    top: up ? 'auto' : rect.bottom + 3, bottom: up ? window.innerHeight - rect.top + 3 : 'auto'});
                reveal(current().addClass('is-active'));
            } else {
                list.prop('hidden', true);
                options().removeClass('is-active');
                preview(current());
            }
            button.attr('aria-expanded', on ? 'true' : 'false');
        }
        /** Scrolls the list alone (scrollIntoView would scroll the pane too, closing it) */
        function reveal(option)
        {
            var el = option[0], box = list[0];
            if(!el) {
                return;
            }
            if(el.offsetTop < box.scrollTop) {
                box.scrollTop = el.offsetTop;
            } else if(el.offsetTop + el.offsetHeight > box.scrollTop + box.clientHeight) {
                box.scrollTop = el.offsetTop + el.offsetHeight - box.clientHeight;
            }
        }
        function step(by)
        {
            var all = options(), i = all.index(current()), next = all.eq(Math.max(0, Math.min(all.length - 1, i + by)));
            if(next.length && next[0] !== current()[0]) {
                choose(next);
            }
        }
        sort();
        choose(current().length ? current() : options().first());

        button.on('click', function () { open(list.prop('hidden')); });
        list.on('mouseenter', '.rb-thumb-option', function () {
            options().removeClass('is-active');
            preview($(this).addClass('is-active'));
        });
        list.on('mouseleave', function () { preview(current()); });
        list.on('click', '.rb-thumb-option', function () {
            choose($(this));
            open(false);
            button.focus();
        });
        $(document).on('mousedown', function (event) {
            if(!list.prop('hidden') && !$(event.target).closest('.rb-thumb-choice').length) {
                open(false);
            }
        });
        // it stays where it opened, so it closes when the pane scrolls or the window changes
        $('.rb-publish-scroll').on('scroll', function () { if(!list.prop('hidden')) { open(false); } });
        $(window).on('resize', function () { if(!list.prop('hidden')) { open(false); } });
        // one option per notch of the wheel; a touchpad's small steps add up to one
        var wheel = 0;
        button[0].addEventListener('wheel', function (event) {
            if(!list.prop('hidden')) {
                return;
            }
            event.preventDefault();
            wheel += event.deltaY;
            if(Math.abs(wheel) >= 40) {
                step(wheel > 0 ? 1 : -1);
                wheel = 0;
            }
        }, {passive: false});
        button.on('keydown', function (event) {
            var openNow = !list.prop('hidden');
            if(event.key === 'ArrowDown' || event.key === 'ArrowUp') {
                event.preventDefault();
                if(openNow) {
                    var all = options(), at = all.index(all.filter('.is-active')), next = all.eq(Math.max(0, Math.min(all.length - 1, at + (event.key === 'ArrowDown' ? 1 : -1))));
                    all.removeClass('is-active');
                    preview(next.addClass('is-active'));
                    reveal(next);
                } else {
                    step(event.key === 'ArrowDown' ? 1 : -1);
                }
            } else if(event.key === 'Enter' || event.key === ' ') {
                event.preventDefault();
                if(openNow && options().filter('.is-active').length) {
                    choose(options().filter('.is-active'));
                    open(false);
                } else {
                    open(!openNow);
                }
            } else if(event.key === 'Escape' && openNow) {
                event.preventDefault();
                open(false);
            }
        });
    };

    /** The decklist's own thumbnail framing, {code, w, l, t}, or null (Ketura, 2026-11-07) */
    ui.thumb_crop = function thumb_crop()
    {
        return app.thumbnail.parse_crop($('#thumbnail_crop').val());
    };

    /** Frames a thumbnail's picture: the custom framing if it's for this card, else its template's */
    ui.thumb_crop_style = function thumb_crop_style(img, code)
    {
        app.thumbnail.crop_style(img, code, ui.thumb_crop());
    };

    /** The crop editor, shared with the library editor (app.thumbnail.js), on the card chosen */
    ui.setup_thumbnail_crop = function setup_thumbnail_crop()
    {
        app.thumbnail.crop_editor({
            dialog: '#thumb-crop',
            field: '#thumbnail_crop',
            pencil: '#thumb-edit',
            current: function () {
                var option = $('#thumb-options .rb-thumb-option[aria-selected=true]');
                return option.data('image') ? {code: option.data('value') + '', image: option.data('image'), template: option.data('template')} : null;
            },
            applied: function (code) {
                ui.thumb_crop_style($('#thumb-card img'), code);
            }
        });
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
