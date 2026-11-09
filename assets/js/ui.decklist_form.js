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
        ui.setup_version_line();
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
            + '<span class="rb-derived-meta">' + _.escape(d.author) + ' &middot; ' + d.date + ' &middot; ' + d.version + '.0' + '</span>'
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
        try {
            var crop = JSON.parse($('#thumbnail_crop').val() || 'null');
            return crop && crop.code ? crop : null;
        } catch(e) {
            return null;
        }
    };

    /** Frames a thumbnail's picture: the custom framing if it's for this card, else its template's */
    ui.thumb_crop_style = function thumb_crop_style(img, code)
    {
        var crop = ui.thumb_crop();
        img.css(crop && crop.code === code ? {width: crop.w + '%', left: crop.l + '%', top: crop.t + '%'} : {width: '', left: '', top: ''});
    };

    /**
     * The crop editor (Ketura, 2026-11-07): the whole card, and a box the tile's shape for the
     * part the thumbnail shows. Dragging the box moves it; dragging an edge or corner resizes
     * it, keeping its shape, from the opposite corner (or edge, staying centred along it). It
     * stays on the card, no larger than the card allows and no smaller than an eighth of that (Ketura: small
     * enough for close-ups, large enough to handle).
     * Reset goes back to the template's framing. One framing is kept, for the last card
     * confirmed, so adjusting another card drops the old one.
     *
     * The box is kept in units of the card's width: x, y its top left corner, w its width;
     * the card is 1 wide and `ratio` tall, the box w / A tall, A being the tile's width over
     * its height. As the thumbnail's CSS: the picture is 100 / w % of the tile's width,
     * shifted -100 x / w % of its width and -100 y A / w % of its height.
     */
    ui.setup_thumbnail_crop = function setup_thumbnail_crop()
    {
        var dialog = $('#thumb-crop'), field = $('#thumbnail_crop'), pencil = $('#thumb-edit');
        if(!dialog.length) {
            return;
        }
        var image = dialog.find('.rb-crop-image'), frame = dialog.find('.rb-crop-frame'),
            mini = dialog.find('.rb-crop-preview'), miniImg = mini.find('img'),
            state = null, drag = null;
        dialog.appendTo('body');

        function clamp(v, lo, hi)
        {
            return Math.max(lo, Math.min(hi, v));
        }
        function largest()
        {
            return Math.min(1, state.ratio * state.A);
        }
        function smallest()
        {
            return largest() / 8;
        }
        function from_css(css)
        {
            var w = 100 / css.w;
            return {x: -css.l * w / 100, y: -css.t * w / (100 * state.A), w: w};
        }
        function to_css(box)
        {
            function r(v) { return Math.round(v * 100) / 100; }
            return {w: r(100 / box.w), l: r(-100 * box.x / box.w), t: r(-100 * box.y * state.A / box.w)};
        }
        /** The template's framing, read off the preview tile with no framing of its own */
        function template_box()
        {
            miniImg.css({width: '', left: '', top: ''});
            var tile = mini[0].getBoundingClientRect(), pic = miniImg[0].getBoundingClientRect();
            return from_css({w: pic.width / tile.width * 100, l: (pic.left - tile.left) / tile.width * 100, t: (pic.top - tile.top) / tile.height * 100});
        }
        /** Kept on the card and within the sizes allowed */
        function fit(box)
        {
            var w = clamp(box.w, smallest(), largest());
            return {w: w, x: clamp(box.x, 0, 1 - w), y: clamp(box.y, 0, state.ratio - w / state.A)};
        }
        function render()
        {
            var box = state.box, ratio = state.ratio, css = to_css(box);
            frame.css({left: box.x * 100 + '%', top: box.y / ratio * 100 + '%', width: box.w * 100 + '%', height: box.w / state.A / ratio * 100 + '%'});
            miniImg.css({width: css.w + '%', left: css.l + '%', top: css.t + '%'});
        }
        function open()
        {
            var option = $('#thumb-options .rb-thumb-option[aria-selected=true]'), src = option.data('image');
            if(!src) {
                return;
            }
            state = {code: option.data('value') + '', edited: false, reset: false};
            mini.attr('class', 'rb-thumb rb-thumb-card rb-crop-preview rb-thumb-tpl-' + (option.data('template') || 'modifier'));
            miniImg.attr('src', src);
            dialog.prop('hidden', false);
            $('body').addClass('rb-crop-open');
            image.one('load', start).attr('src', src);
            if(image[0].complete && image[0].naturalWidth) {
                image.off('load', start);
                start();
            }
        }
        /** The card as large as the window allows, beside the preview (or above it, narrow) */
        function size()
        {
            var w = image[0].naturalWidth, h = image[0].naturalHeight, narrow = window.innerWidth <= 600,
                room = Math.min(Math.min(700, window.innerWidth - (narrow ? 80 : 300)) / w, (window.innerHeight - (narrow ? 360 : 200)) / h);
            image.css({width: Math.round(w * room), height: Math.round(h * room)});
        }
        function start()
        {
            size();
            var crop = ui.thumb_crop();
            state.ratio = image[0].naturalHeight / image[0].naturalWidth;
            var tile = mini[0].getBoundingClientRect();
            state.A = tile.width / tile.height;
            state.box = fit(crop && crop.code === state.code ? from_css(crop) : template_box());
            render();
            frame.focus();
        }
        function close(confirmed)
        {
            if(confirmed && state.box) {
                var crop = ui.thumb_crop();
                if(state.edited) {
                    field.val(JSON.stringify(_.extend({code: state.code}, to_css(state.box))));
                } else if(state.reset && crop && crop.code === state.code) {
                    field.val('');
                }
                ui.thumb_crop_style($('#thumb-card img'), state.code);
            }
            drag = null;
            state = null;
            dialog.prop('hidden', true);
            $('body').removeClass('rb-crop-open');
            pencil.focus();
        }
        function edited(box)
        {
            state.box = fit(box);
            state.edited = true;
            render();
        }

        /** Resizing from a handle, the pointer at (px, py) in the card's units */
        function resized(handle, b, px, py)
        {
            var A = state.A, ratio = state.ratio, max = largest(),
                sx = handle.indexOf('e') >= 0 ? 1 : (handle.indexOf('w') >= 0 ? -1 : 0),
                sy = handle.indexOf('s') >= 0 ? 1 : (handle.indexOf('n') >= 0 ? -1 : 0),
                ax = sx > 0 ? b.x : b.x + b.w, ay = sy > 0 ? b.y : b.y + b.w / A,
                want = Math.max(sx ? (px - ax) * sx : -Infinity, sy ? (py - ay) * sy * A : -Infinity),
                limit = Math.min(max, sx > 0 ? 1 - ax : (sx < 0 ? ax : Infinity), sy > 0 ? (ratio - ay) * A : (sy < 0 ? ay * A : Infinity)),
                w = clamp(want, smallest(), limit);
            return {
                w: w,
                // the side opposite the handle stays put; along an edge handle, the box stays centred
                x: sx > 0 ? ax : (sx < 0 ? ax - w : b.x + (b.w - w) / 2),
                y: sy > 0 ? ay : (sy < 0 ? ay - w / A : b.y + (b.w - w) / A / 2)
            };
        }

        pencil.on('click', open);
        frame.on('pointerdown', function (event) {
            var e = event.originalEvent;
            if(!state || !state.box || e.button !== 0) {
                return;
            }
            event.preventDefault();
            frame.focus();
            drag = {handle: $(event.target).data('handle') || '', x: e.clientX, y: e.clientY, box: _.clone(state.box), rect: image[0].getBoundingClientRect()};
            this.setPointerCapture(e.pointerId);
        });
        frame.on('pointermove', function (event) {
            if(!drag) {
                return;
            }
            var e = event.originalEvent, unit = drag.rect.width, b = drag.box;
            if(!drag.handle) {
                edited({w: b.w, x: b.x + (e.clientX - drag.x) / unit, y: b.y + (e.clientY - drag.y) / unit});
            } else {
                edited(resized(drag.handle, b, (e.clientX - drag.rect.left) / unit, (e.clientY - drag.rect.top) / unit));
            }
        });
        frame.on('pointerup pointercancel lostpointercapture', function () { drag = null; });
        // the keyboard too: the arrows move it, + and - resize it about its centre
        frame.on('keydown', function (event) {
            if(!state || !state.box) {
                return;
            }
            var b = state.box, step = event.shiftKey ? 0.05 : 0.01,
                moves = {ArrowLeft: [-step, 0], ArrowRight: [step, 0], ArrowUp: [0, -step], ArrowDown: [0, step]};
            if(moves[event.key]) {
                event.preventDefault();
                edited({w: b.w, x: b.x + moves[event.key][0], y: b.y + moves[event.key][1]});
            } else if(event.key === '+' || event.key === '=' || event.key === '-') {
                event.preventDefault();
                var w = clamp(b.w * (event.key === '-' ? 0.95 : 1.05), smallest(), largest());
                edited({w: w, x: b.x + (b.w - w) / 2, y: b.y + (b.w - w) / state.A / 2});
            }
        });
        dialog.on('click', '.rb-crop-reset', function () {
            if(state && state.box) {
                state.box = fit(template_box());
                state.edited = false;
                state.reset = true;
                render();
            }
        });
        dialog.on('click', '.rb-crop-cancel', function () { close(false); });
        dialog.on('click', '.rb-crop-confirm', function () { close(true); });
        $(window).on('resize', function () { if(state && state.box) { size(); } });
        $(document).on('keydown', function (event) {
            if(state && event.key === 'Escape') {
                event.preventDefault();
                close(false);
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
            key: 'rb-publish-width',
            min: 420,
            max: function () { return row.width() - 360; },
            current: function () { return side.outerWidth(); },
            direction: -1,
            set: function (w) { side.css('flex-basis', w === null ? '' : w + 'px'); }
        });
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
                line.html(line.data('of').replace('__V__', option.data('next')));
                $('#version-card').prop('hidden', false).html(ui.deck_ref({
                    name: option.data('name'), url: option.data('url'), author: option.data('author'), date: option.data('date'),
                    version: option.data('version'), tags: (option.data('tags') + '').split(' ')
                }));
            } else {
                line.html(line.data('new'));
                $('#version-card').prop('hidden', true).empty();
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
            return ui.deck_ref(d);
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
