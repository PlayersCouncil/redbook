/* global _, jQuery, app */

/**
 * Thumbnails, shared (Ketura, 2026-11-09): the decklist form's and the library editor's live
 * previews, drawn as Decklist/thumbnail.html.twig draws them, and the crop editor that frames
 * a card's picture by hand (its dialog: Thumbnail/crop-dialog.html.twig). A design fix to any
 * of these is made once, here.
 */
(function app_thumbnail(thumb, $)
{
    /** As Card::getThumbnailTemplate frames a card's picture */
    thumb.template = function template(type_code)
    {
        return ['companion', 'ally', 'minion', 'follower'].indexOf(type_code) !== -1 ? 'character' : type_code === 'site' ? 'site' : 'modifier';
    };

    /** A hand framing, {code, w, l, t}, from its JSON; null for none */
    thumb.parse_crop = function parse_crop(value)
    {
        try {
            var crop = typeof value === 'string' ? JSON.parse(value || 'null') : value;
            return crop && crop.code ? crop : null;
        } catch(e) {
            return null;
        }
    };

    /** Frames a thumbnail's picture: the hand framing if it's for this card, else its template's */
    thumb.crop_style = function crop_style(img, code, crop)
    {
        $(img).css(crop && crop.code === code ? {width: crop.w + '%', left: crop.l + '%', top: crop.t + '%'} : {width: '', left: '', top: ''});
    };

    /** A card's thumbnail: {code, image, template, label} and its framing */
    thumb.card_html = function card_html(card, crop)
    {
        var style = crop && crop.code === card.code ? ' style="width: ' + crop.w + '%; left: ' + crop.l + '%; top: ' + crop.t + '%"' : '';
        return '<span class="rb-thumb rb-thumb-card rb-thumb-tpl-' + _.escape(card.template || 'modifier') + '"><img src="' + _.escape(card.image) + '" alt="' + _.escape(card.label || '') + '"' + style + '></span>';
    };

    /**
     * A culture banner: parts [{code, name, side, corner}], corner full for one culture, fp
     * and shadow for the two halves of the diagonal
     */
    thumb.cultures_html = function cultures_html(parts)
    {
        if(!parts || !parts.length) {
            return '<span class="rb-thumb rb-thumb-empty" aria-hidden="true"><span class="fas fa-book"></span></span>';
        }
        return '<span class="rb-thumb rb-thumb-cultures" role="img" aria-label="' + _.escape(_.pluck(parts, 'name').join(' / ')) + '">'
            + _.map(parts, function (part) {
                return '<span class="rb-thumb-part rb-thumb-' + part.corner + ' rb-thumb-side-' + (part.side || 'free_peoples') + ' rb-thumb-c-' + part.code.replace(/_/g, '-')
                    + '" style="--art: url(/images/cultures/art/' + encodeURIComponent(part.code) + '.webp)"><span class="rb-thumb-icon"></span></span>';
            }).join('') + '</span>';
    };

    /** One culture fills the tile; two share it, the first top left */
    thumb.culture_parts = function culture_parts(cultures)
    {
        var corners = cultures.length === 1 ? ['full'] : ['fp', 'shadow'];
        return _.map(cultures.slice(0, 2), function (c, i) { return _.extend({}, c, {corner: corners[i]}); });
    };

    /**
     * The thumbnail picker (Ketura, 2026-11-07; shared with the deckbuilder 2026-11-09): its
     * markup is Thumbnail/picker.html.twig. A list of its own rather than a <select>: open,
     * hovering an option previews it; the wheel over the closed control steps one option per
     * notch; the cards are in the deck list's order (type, the starting companions first,
     * then name; sites by site number, shadow number, name). The pencil over a card's preview
     * opens the crop editor.
     *
     * options: scroll (what scrolling closes the list), changed(code, crop) after a choice or
     * a framing. Returns {refresh(options_html), cultures(html)}: the deckbuilder's list and
     * culture banner follow the deck as it changes.
     */
    thumb.picker = function picker(options)
    {
        options = options || {};
        var input = $('#thumbnail'), button = $('#thumb-select'), list = $('#thumb-options'), field = $('#thumbnail_crop');
        if(!list.length) {
            return null;
        }
        function crop()
        {
            return thumb.parse_crop(field.val());
        }
        function all()
        {
            return list.find('.rb-thumb-option');
        }
        function preview(option)
        {
            var image = option.data('image');
            var img = $('#thumb-card').prop('hidden', !image).attr('class', 'rb-thumb rb-thumb-card rb-thumb-tpl-' + (option.data('template') || 'modifier'))
                .find('img').attr('src', image || '');
            thumb.crop_style(img, option.data('value') + '', crop());
            $('#thumb-cultures').prop('hidden', !!image);
        }
        function current()
        {
            var value = input.val() + '';
            return all().filter(function () { return ($(this).data('value') + '') === value; }).first();
        }
        function choose(option, quiet)
        {
            var before = input.val() + '';
            input.val(option.data('value') + '');
            all().attr('aria-selected', 'false').removeClass('is-active');
            option.attr('aria-selected', 'true');
            button.find('.rb-thumb-current').text(option.text());
            preview(option);
            // a card's framing can be adjusted; the banner's can't
            $('#thumb-edit').prop('hidden', !option.data('image'));
            if(!quiet && before !== input.val() + '' && options.changed) {
                options.changed(input.val() + '', crop());
            }
        }
        function sort()
        {
            var starting = app.deck && app.deck.is_starting ? app.deck.is_starting : function () { return false; };
            list.find('.rb-thumb-group').each(function () {
                var group = $(this).data('group'),
                    items = all().filter('[data-group="' + group + '"]').get();
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
                all().removeClass('is-active');
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
            var items = all(), i = items.index(current()), next = items.eq(Math.max(0, Math.min(items.length - 1, i + by)));
            if(next.length && next[0] !== current()[0]) {
                choose(next);
            }
        }
        sort();
        choose(current().length ? current() : all().first(), true);

        button.on('click', function () { open(list.prop('hidden')); });
        list.on('mouseenter', '.rb-thumb-option', function () {
            all().removeClass('is-active');
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
        options.scroll && $(options.scroll).on('scroll', function () { if(!list.prop('hidden')) { open(false); } });
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
                    var items = all(), at = items.index(items.filter('.is-active')), next = items.eq(Math.max(0, Math.min(items.length - 1, at + (event.key === 'ArrowDown' ? 1 : -1))));
                    items.removeClass('is-active');
                    preview(next.addClass('is-active'));
                    reveal(next);
                } else {
                    step(event.key === 'ArrowDown' ? 1 : -1);
                }
            } else if(event.key === 'Enter' || event.key === ' ') {
                event.preventDefault();
                if(openNow && all().filter('.is-active').length) {
                    choose(all().filter('.is-active'));
                    open(false);
                } else {
                    open(!openNow);
                }
            } else if(event.key === 'Escape' && openNow) {
                event.preventDefault();
                open(false);
            }
        });

        // the crop editor, on the card chosen
        thumb.crop_editor({
            dialog: '#thumb-crop',
            field: '#thumbnail_crop',
            pencil: '#thumb-edit',
            current: function () {
                var option = list.find('.rb-thumb-option[aria-selected=true]');
                return option.data('image') ? {code: option.data('value') + '', image: option.data('image'), template: option.data('template')} : null;
            },
            applied: function (code) {
                thumb.crop_style($('#thumb-card img'), code, crop());
                options.changed && options.changed(input.val() + '', crop());
            }
        });

        return {
            /** New options (the cultures' first): the chosen card kept if it's still there, else the cultures */
            refresh: function refresh(html)
            {
                list.html(html);
                sort();
                var chosen = current();
                if(chosen.length) {
                    choose(chosen, true);
                } else {
                    choose(all().first());
                }
            },
            /** A choice and framing from elsewhere (the deckbuilder's draft, a revert), quietly */
            set: function set(code, framing)
            {
                field.val(framing ? JSON.stringify(framing) : '');
                input.val(code || '');
                var chosen = current();
                choose(chosen.length ? chosen : all().first(), true);
            },
            /** The culture banner the cultures option shows */
            cultures: function cultures(html)
            {
                $('#thumb-cultures').html(html);
            }
        };
    };

    var TYPE_RANK = {companion: 0, ally: 1, follower: 2, minion: 3, artifact: 4, possession: 5, condition: 6, event: 7};

    /**
     * A deck's picker options from the deckbuilder's deck (Ketura, 2026-11-09), as
     * Thumbnail/picker.html.twig lists a decklist's: the cultures, then the Ring-bearer and the
     * Ring, the Free Peoples, the Shadow and the sites, each card once
     */
    thumb.deck_options_html = function deck_options_html(deck, labels)
    {
        var html = '<div class="rb-thumb-option" role="option" data-value="">' + _.escape(labels.cultures) + '</div>',
            seen = {},
            groups = [
                {key: 'rb', label: labels.rb, slots: deck.get_cards('ring_bearer').concat(deck.get_cards('ring'))},
                {key: 'fp', label: labels.fp, slots: _.filter(deck.get_cards('draw'), function (s) { return s.card.side === 'free_peoples'; })},
                {key: 'shadow', label: labels.shadow, slots: _.filter(deck.get_cards('draw'), function (s) { return s.card.side === 'shadow'; })},
                {key: 'sites', label: labels.sites, slots: deck.get_cards('site')}
            ];
        _.each(groups, function (group) {
            var cards = _.filter(_.pluck(_.reject(group.slots, 'pending'), 'card'), function (card) {
                if(!card.image_url || seen[card.code]) {
                    return false;
                }
                return (seen[card.code] = true);
            });
            if(!cards.length) {
                return;
            }
            html += '<div class="rb-thumb-group" data-group="' + group.key + '" role="presentation">' + _.escape(group.label) + '</div>';
            _.each(cards, function (card) {
                var rank = group.key === 'rb' ? (card.type_code === 'the_one_ring' ? -1 : -2) : (TYPE_RANK[card.type_code] !== undefined ? TYPE_RANK[card.type_code] : 99);
                html += '<div class="rb-thumb-option" role="option" data-group="' + group.key + '" data-value="' + _.escape(card.code) + '"'
                    + ' data-image="' + _.escape(card.image_url) + '" data-template="' + thumb.template(card.type_code) + '"'
                    + ' data-rank="' + rank + '" data-site="' + (card.site_number || 99) + '" data-shadow="' + (card.shadow_number || 99) + '">'
                    + _.escape(card.label) + '</div>';
            });
        });
        return html;
    };

    /**
     * A deck's culture banner as PublishedDeck::pickThumbnailCultures picks it: the draw deck's
     * largest Free Peoples culture top left, its largest Shadow culture bottom right (ties by
     * name); one alone fills the tile
     */
    thumb.deck_cultures = function deck_cultures(deck)
    {
        var counts = {free_peoples: {}, shadow: {}};
        _.each(_.reject(deck.get_cards('draw'), 'pending'), function (slot) {
            var card = slot.card;
            if(counts[card.side] && card.culture_code) {
                var row = counts[card.side][card.culture_code] = counts[card.side][card.culture_code] || {code: card.culture_code, name: card.culture_name, count: 0};
                row.count += slot.qty;
            }
        });
        function top(side)
        {
            return _.values(counts[side]).sort(function (a, b) { return b.count - a.count || a.name.localeCompare(b.name); })[0] || null;
        }
        var fp = top('free_peoples'), shadow = top('shadow');
        if(fp && shadow) {
            return [_.extend({}, fp, {corner: 'fp', side: 'free_peoples'}), _.extend({}, shadow, {corner: 'shadow', side: 'shadow'})];
        }
        if(fp) {
            return [_.extend({}, fp, {corner: 'full', side: 'free_peoples'})];
        }
        return shadow ? [_.extend({}, shadow, {corner: 'full', side: 'shadow'})] : [];
    };

    /**
     * The crop editor (Ketura, 2026-11-07; shared 2026-11-09): the whole card, and a box the tile's shape for the
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
     *
     * options: dialog, field (the input holding the framing's JSON), pencil (the button that
     * opens it); current() the card being framed, {code, image, template}, or null;
     * applied(code) once a framing is confirmed, to redraw the preview.
     */
    thumb.crop_editor = function crop_editor(options)
    {
        var dialog = $(options.dialog), field = $(options.field), pencil = $(options.pencil);
        if(!dialog.length) {
            return;
        }
        function current_crop()
        {
            return thumb.parse_crop(field.val());
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
            var card = options.current(), src = card && card.image;
            if(!src) {
                return;
            }
            state = {code: card.code + '', edited: false, reset: false};
            dialog.removeClass('is-ready');
            mini.attr('class', 'rb-thumb rb-thumb-card rb-crop-preview rb-thumb-tpl-' + (card.template || 'modifier'));
            miniImg.attr('src', src);
            dialog.prop('hidden', false);
            $('body').addClass('rb-crop-open');
            image.off('load', start).one('load', start).attr('src', src);
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
        /** The card has arrived: frame it. Until then the frame isn't shown and takes no keys; a
            picture arriving after the dialog closed is ignored */
        function start()
        {
            if(!state) {
                return;
            }
            size();
            var crop = current_crop();
            state.ratio = image[0].naturalHeight / image[0].naturalWidth;
            var tile = mini[0].getBoundingClientRect();
            state.A = tile.width / tile.height;
            state.box = fit(crop && crop.code === state.code ? from_css(crop) : template_box());
            render();
            dialog.addClass('is-ready');
            frame.focus();
        }
        function close(confirmed)
        {
            if(confirmed && state.box) {
                var crop = current_crop();
                if(state.edited) {
                    field.val(JSON.stringify(_.extend({code: state.code}, to_css(state.box))));
                } else if(state.reset && crop && crop.code === state.code) {
                    field.val('');
                }
                field.trigger('change');
                options.applied && options.applied(state.code);
            }
            drag = null;
            state = null;
            image.off('load', start);
            dialog.removeClass('is-ready').prop('hidden', true);
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
        dialog.on('click', '.rb-crop-confirm', function () { if(state && state.box) { close(true); } });
        $(window).on('resize', function () { if(state && state.box) { size(); } });
        $(document).on('keydown', function (event) {
            if(state && event.key === 'Escape') {
                event.preventDefault();
                close(false);
            }
        });
    };

    /**
     * A thumbnail chooser (Ketura, 2026-11-11; Thumbnail/chooser.html.twig, from the library
     * editor's): a card found by name and framed with the crop editor, a banner of cultures,
     * or the subject's fallback. options: mode, card ({code, image, template, label} or null),
     * cultures ([{code, name, side}]), changed() after any change. Returns {value()}:
     * {mode, card (code or ''), crop, cultures (codes)}.
     */
    thumb.chooser = function chooser(root, options)
    {
        root = $(root);
        var max = parseInt(root.data('max-cultures'), 10) || 2,
            changed = options.changed || function () {},
            crop_field = root.find('.rb-chooser-crop'),
            state = {mode: options.mode, card: options.card || null, cultures: (options.cultures || []).slice(0, max)};

        function render()
        {
            var preview = root.find('.rb-chooser-preview'), crop = thumb.parse_crop(crop_field.val());
            if(state.mode === 'card' && state.card) {
                preview.html(thumb.card_html(state.card, crop));
            } else if((state.mode === 'cultures' || state.mode === 'culture') && state.cultures.length) {
                preview.html(thumb.cultures_html(thumb.culture_parts(state.cultures)));
            } else {
                preview.html(root.find('.rb-chooser-fallback').html());
            }
            $('#thumb-edit').prop('hidden', !(state.mode === 'card' && state.card));
            root.find('[data-thumb-mode]').each(function () {
                var on = $(this).data('thumb-mode') === state.mode;
                $(this).toggleClass('active', on).attr('aria-checked', on ? 'true' : 'false');
            });
            root.find('[data-thumb-panel]').each(function () {
                $(this).prop('hidden', $(this).data('thumb-panel') !== state.mode);
            });
            var name = root.find('.rb-chooser-name');
            name.text(state.card ? state.card.label : name.data('none'));
            var codes = _.pluck(state.cultures, 'code');
            root.find('.rb-lib-culture').each(function () {
                var at = codes.indexOf($(this).data('culture'));
                $(this).attr('aria-pressed', at !== -1 ? 'true' : 'false').find('.rb-lib-culture-order').text(at !== -1 && codes.length > 1 ? at + 1 : '');
            });
            root.find('.rb-chooser-swap').prop('hidden', codes.length < 2);
        }

        function set_card(card)
        {
            if(!state.card || state.card.code !== card.code) {
                crop_field.val('');   // a framing is for one card
            }
            state.card = card;
            state.mode = 'card';
            render();
            changed();
        }

        /** A click adds a culture, or takes it off; one past the most replaces the first */
        function toggle_culture(button)
        {
            var code = button.data('culture'), at = _.pluck(state.cultures, 'code').indexOf(code);
            if(at !== -1) {
                state.cultures.splice(at, 1);
            } else {
                state.cultures.push({code: code, name: button.data('name'), side: button.data('side')});
                if(state.cultures.length > max) {
                    state.cultures.shift();
                }
            }
            render();
            changed();
        }

        render();
        root.find('[data-thumb-mode]').on('click', function () {
            state.mode = $(this).data('thumb-mode');
            render();
            changed();
            if(state.mode === 'card' && !state.card) {
                root.find('.rb-chooser-search').trigger('focus');
            }
        });
        root.find('.rb-lib-culture').on('click', function () { toggle_culture($(this)); });
        root.find('.rb-chooser-swap').on('click', function () {
            state.cultures.reverse();
            render();
            changed();
        });
        thumb.crop_editor({
            dialog: '#thumb-crop',
            field: crop_field,
            pencil: '#thumb-edit',
            current: function () { return state.mode === 'card' ? state.card : null; },
            applied: function () {
                render();
                changed();
            }
        });

        // the card search, once the cards are here
        var input = root.find('.rb-chooser-search');
        function setup_search()
        {
            if(!input.length || input.data('ready') || !app.data.cards) {
                return;
            }
            input.data('ready', true);
            input.typeahead({hint: true, highlight: true, minLength: 2}, {
                name: 'thumbcards',
                displayKey: 'label',
                source: function (q, cb)
                {
                    var regexp = new RegExp(q.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'), 'i');
                    cb(_.filter(app.data.cards.find({label: regexp}, {$orderBy: {label: 1}}), function (card) { return !!card.image_url; }).slice(0, 12));
                }
            });
            input.on('typeahead:selected typeahead:autocompleted', function (event, card)
            {
                input.typeahead('val', '');
                set_card({code: card.code, image: card.image_url, template: thumb.template(card.type_code), label: card.label});
            });
        }
        setup_search();
        $(document).on('data.app', setup_search);

        return {
            value: function value()
            {
                return {
                    mode: state.mode,
                    card: state.card ? state.card.code : '',
                    crop: thumb.parse_crop(crop_field.val()),
                    cultures: _.pluck(state.cultures, 'code')
                };
            }
        };
    };

})(app.thumbnail = {}, jQuery);
