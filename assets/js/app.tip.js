/* global app, _ */
/**
 * Card hover: the card's CDN image beside whatever is pointed at (Ketura, 2026-11-01/02).
 *
 * - Twice the old size by default (a long edge of 840px, never more than the window
 *   allows); "hover-size" in app.config overrides the long edge, for a setting later.
 * - A site has the same long edge as an upright card, turned sideways.
 * - It opens beside the element (right, else left, else below or above) and never
 *   covers it, so the next card in a grid stays visible.
 * - Elements with data-hover-grow (thumbnails) animate from their own position and
 *   size to the hover's.
 * - On a touch screen the first tap on a card shows it; the second does the usual
 *   thing (open the card's details).
 * - A card without an image falls back to the old text tooltip.
 * - Timing follows Gemp's AutoZoom (Ketura, 2026-11-03): the first hover waits
 *   HOVER_DELAY before showing; once a card is showing, moving to another shows it
 *   at once; leaving the cards keeps that for COOLDOWN, so crossing the gap between
 *   two thumbnails doesn't flicker (1s since Ketura, 2026-11-03, so moves within a second
 *   never re-animate). Pressing the mouse button hides it.
 * - While the next image loads the hover is black, never the previous card.
 */
(function app_tip(tip, $)
{
    var cards_zoom_regexp = /card\/(\d+_\d+)$/,
        PORTRAIT = 497 / 357,
        GAP = 10,
        overlay = null,
        current = null,
        last_touch = 0,
        HOVER_DELAY = 500,
        COOLDOWN = 1000,
        LEAVE_GRACE = 120,
        hover_timer = null,
        hide_timer = null,
        cooldown_timer = null,
        hover_valid = false;

    function long_edge()
    {
        var wanted = parseInt(app.config && app.config.get('hover-size'), 10) || 840;
        return Math.max(240, Math.min(wanted, window.innerHeight * 0.92, (window.innerWidth - 2 * GAP) * PORTRAIT));
    }

    function ensure_overlay()
    {
        if(!overlay) {
            // the second picture is a generic card's other half (Ketura, 2026-11-08)
            overlay = $('<div class="rb-hover" aria-hidden="true"><img alt=""><img class="rb-hover-half" alt=""></div>').appendTo('body');
            overlay.find('img').on('load', function () { $(this).addClass('is-loaded'); });
        }
        return overlay;
    }

    /** Where the image goes: beside the element, inside the window */
    function target_rect(el_rect, landscape)
    {
        var edge = long_edge(),
            w = landscape ? edge : edge / PORTRAIT,
            h = landscape ? edge / PORTRAIT : edge,
            vw = window.innerWidth,
            vh = window.innerHeight,
            left, top,
            mid_y = el_rect.top + el_rect.height / 2;
        if(el_rect.right + GAP + w <= vw) {
            left = el_rect.right + GAP;
            top = mid_y - h / 2;
        } else if(el_rect.left - GAP - w >= 0) {
            left = el_rect.left - GAP - w;
            top = mid_y - h / 2;
        } else {
            left = Math.min(Math.max(GAP, el_rect.left + el_rect.width / 2 - w / 2), vw - w - GAP);
            top = el_rect.bottom + GAP + h <= vh ? el_rect.bottom + GAP : el_rect.top - GAP - h;
        }
        top = Math.min(Math.max(GAP, top), Math.max(GAP, vh - h - GAP));
        return {left: left, top: top, width: w, height: h};
    }

    function place(rect)
    {
        overlay.css({left: rect.left + 'px', top: rect.top + 'px', width: rect.width + 'px', height: rect.height + 'px'});
    }

    tip.show = function show(element, card)
    {
        var image = $(element).data('image') || card.image_url;
        if(!image) {
            return show_text(element, card);
        }
        ensure_overlay();
        var switching = current !== null && overlay.hasClass('is-shown');
        current = element;
        var landscape = card.type_code === 'site',
            rect = element.getBoundingClientRect(),
            target = target_rect(rect, landscape),
            // moving from one card to the next: no grow, just the new card in place
            grow = !switching && $(element).is('[data-hover-grow]'),
            img = overlay.find('img').first(),
            // any version (Ketura, 2026-11-08): split down the middle between two versions, as its tile is
            split = String($(element).data('hover-split') || '').split(','),
            other = split.length === 2 && app.data.cards ? app.data.cards.findById(split[1]) : null,
            first = split.length === 2 && app.data.cards ? app.data.cards.findById(split[0]) : null,
            half = overlay.find('.rb-hover-half');
        if(first && other && first.image_url && other.image_url) {
            image = first.image_url;
            if(half.attr('src') !== other.image_url) {
                half.removeClass('is-loaded').attr('src', other.image_url);
            }
            if(half[0].complete && half[0].naturalWidth) {
                half.addClass('is-loaded');
            }
            overlay.addClass('is-split');
        } else {
            overlay.removeClass('is-split');
        }
        if(img.attr('src') !== image) {
            // black until the new image arrives, never the previous card
            img.removeClass('is-loaded').attr('src', image);
        }
        if(img[0].complete && img[0].naturalWidth) {
            img.addClass('is-loaded');
        }
        img.attr('alt', card.label || card.name || '');
        overlay.toggleClass('is-landscape', landscape);
        if(switching) {
            overlay.removeClass('is-animated is-fade');
            place(target);
            return;
        }
        overlay.removeClass('is-shown is-animated');
        if(grow) {
            // start where the thumbnail is, then grow into place
            var img = $(element).find('img')[0],
                from = (img || element).getBoundingClientRect();
            place({left: from.left, top: from.top, width: from.width, height: from.height});
            overlay.addClass('is-shown');
            overlay[0].getBoundingClientRect();
            overlay.addClass('is-animated');
            place(target);
        } else {
            place(target);
            overlay.addClass('is-shown is-fade');
        }
    };

    tip.hide = function hide()
    {
        current = null;
        if(overlay) {
            overlay.removeClass('is-shown is-animated is-fade');
        }
    };

    function show_text(element, card)
    {
        var info = '<h4 class="card-name">' + app.format.name(card) + '</h4>'
            + '<div class="card-faction">' + app.format.faction(card) + '</div>'
            + '<div class="card-info">' + app.format.info(card) + '</div>'
            + '<div class="card-traits">' + app.format.traits(card) + '</div>'
            + '<div class="card-text border-' + card.culture_code + '">' + app.format.text(card) + '</div>'
            + '<div class="card-pack">' + app.format.pack(card) + '</div>';
        $(element).qtip({
            content: {text: info},
            style: {classes: 'card-content qtip-bootstrap qtip-thronesdb qtip-thronesdb-text'},
            position: {my: 'center left', at: 'center right', viewport: $(window)},
            show: {event: 'mouseover', ready: true, solo: true},
            hide: {event: 'mouseout'}
        });
    }

    function card_for(element)
    {
        var code = $(element).data('hover-code') || $(element).data('code');
        if(!code) {
            var href = element.href;
            if(href && href.match(cards_zoom_regexp)) {
                code = RegExp.$1;
                if(href !== Routing.generate('cards_zoom', {card_code: code}, true)) {
                    return null;
                }
            }
        }
        return code && app.data.cards ? app.data.cards.findById(code + '') : null;
    }

    function on_enter(event)
    {
        if(Date.now() - last_touch < 800 || $(this).hasClass('no-popup')) {
            return;
        }
        var element = this,
            card = card_for(element);
        if(!card) {
            return;
        }
        clearTimeout(hide_timer);
        clearTimeout(cooldown_timer);
        clearTimeout(hover_timer);
        if(hover_valid) {
            tip.show(element, card);
            return;
        }
        hover_timer = setTimeout(function () {
            hover_valid = true;
            tip.show(element, card);
        }, HOVER_DELAY);
    }

    function on_leave()
    {
        clearTimeout(hover_timer);
        if(current !== this) {
            return;
        }
        // a moment's grace, so the next card along can take over without a flicker
        clearTimeout(hide_timer);
        hide_timer = setTimeout(tip.hide, LEAVE_GRACE);
        clearTimeout(cooldown_timer);
        cooldown_timer = setTimeout(function () { hover_valid = false; }, COOLDOWN);
    }

    /** A click, scroll or resize: hide now, and the next hover waits again */
    function dismiss()
    {
        clearTimeout(hover_timer);
        clearTimeout(hide_timer);
        clearTimeout(cooldown_timer);
        hover_valid = false;
        tip.hide();
    }

    $(document).on('start.app', function ()
    {
        var selector = 'a.card-tip, [data-hover-code]';
        $('body').on('mouseenter', selector, on_enter).on('mouseleave', selector, on_leave);
        $('body').on('mouseenter', 'a:not(.card-tip):not([data-hover-code])', function (event) {
            if($(this).closest('[data-hover-code]').length) {
                return;
            }
            on_enter.call(this, event);
        }).on('mouseleave', 'a:not(.card-tip):not([data-hover-code])', on_leave);
        $(window).on('resize', dismiss);
        // the page's scrolling happens in its panes now: catch scrolls anywhere
        document.addEventListener('scroll', dismiss, {passive: true, capture: true});
        document.addEventListener('mousedown', function () {
            // a touch makes a mousedown too; the tap handler below decides those
            if(Date.now() - last_touch > 800) {
                dismiss();
            }
        }, true);

        // touch: the first tap shows the card, the second goes through
        document.addEventListener('touchstart', function () { last_touch = Date.now(); }, {passive: true, capture: true});
        document.addEventListener('click', function (event) {
            if(Date.now() - last_touch > 800) {
                return;
            }
            var el = $(event.target).closest('a.card-tip, [data-hover-code]')[0];
            if(!el || $(event.target).closest('button').length) {
                tip.hide();
                return;
            }
            if(current !== el) {
                var card = card_for(el);
                if(card) {
                    event.preventDefault();
                    event.stopPropagation();
                    tip.show(el, card);
                }
            } else {
                tip.hide();
            }
        }, true);
    });

    // kept for pages that call them
    tip.set_mode = function () {};
    tip.set_hide_event = function () {};
    tip.display = function (event) { on_enter.call(this, event); };
    tip.guess = tip.display;

})(app.tip = {}, jQuery);
