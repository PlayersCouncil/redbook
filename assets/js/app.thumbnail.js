/* global _, jQuery */

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

})(app.thumbnail = {}, jQuery);
