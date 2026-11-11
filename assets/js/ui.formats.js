/* global app, Translator, _ */

/*
 * The Formats page's Formats tab (Ketura, 2026-11-11), as Gemp's Help > Format Definitions
 * behaves (its formatDefinitions.js):
 * - the list of formats stays in view beside them, as tall as the page's scrolling pane, and
 *   marks the format being read, which is also put in the address bar (#format-<code>)
 * - a click in the list, or a format's #, goes to it; so does a #format-<code> link, the
 *   format lighting up when it gets there
 * - the box finds formats by name, and every format that lists a card (the card marked)
 * - "Card images" shows the card lists as pictures, remembered in this browser
 * - the (i) beside the introduction explains the lists
 */
(function ui_formats(ui, $)
{
    var IMAGES_KEY = 'rb.formats.images',
        PIN_MS = 3000,      // a reveal's scroll takes no longer than this to bring the format into view
        root, box, filter_timer = null, spy_frame = null,
        pin = null;         // {anchor, until}: the format just gone to; the spy keeps to it while it's in view

    function t(key, params) { return Translator.trans(key, params || {}); }

    /** Lower case, without accents, so "eowyn" finds "Éowyn" */
    function fold(text)
    {
        text = String(text == null ? '' : text).toLowerCase();
        return text.normalize ? text.normalize('NFD').replace(/[̀-ͯ]/g, '') : text;
    }

    function stored(key, value)
    {
        try {
            if(value === undefined) {
                return window.localStorage.getItem(key);
            }
            window.localStorage.setItem(key, value);
        } catch (ignored) {
            // no storage: the choice lasts until the page is left
        }
        return null;
    }

    /* ---- card images ---- */

    /** A chip as a picture: the card's image (lazily), its name under it */
    function thumb(chip)
    {
        chip = $(chip);
        var link = chip.is('a'),
            el = $(link ? '<a>' : '<span>').addClass('rb-fd-thumb' + (link ? ' card-tip' : ' is-unknown'))
                .attr({title: chip.attr('title'), 'data-id': chip.attr('data-id')});
        if(link) {
            el.attr({href: chip.attr('href'), 'data-code': chip.attr('data-code')});
        }
        if(chip.attr('data-image')) {
            el.append($('<img loading="lazy" decoding="async" alt="">').attr('src', chip.attr('data-image')));
        } else {
            el.append('<span class="rb-fd-thumb-blank" aria-hidden="true"></span>');
        }
        return el.append($('<span class="rb-fd-thumb-name"></span>').text(chip.text()));
    }

    function set_images(on)
    {
        root.find('.rb-fd-images').prop('checked', on);
        root.toggleClass('is-images', on);
        root.find('.rb-fd-list:not(.is-names) > .rb-fd-list-body').each(function () {
            var body = $(this);
            if(on && !body.children('.rb-fd-thumbs').length) {
                var thumbs = $('<div class="rb-fd-thumbs"></div>');
                body.find('.rb-fd-chip').each(function () { thumbs.append(thumb(this)); });
                body.append(thumbs);
            }
        });
        apply_filter(root.find('.rb-fd-filter').val());
    }

    /* ---- finding ---- */

    /** Shows the formats whose name matches, and those listing a matching card (it marked) */
    function apply_filter(value)
    {
        var query = fold($.trim(value || '')),
            sections = root.find('.rb-fd-format');
        root.find('.is-hit').removeClass('is-hit');
        if(query === '') {
            sections.prop('hidden', false);
            root.find('.rb-fd-nav li').prop('hidden', false);
            root.find('.rb-fd-status').text('');
            return;
        }
        var shown = 0, by_card = 0;
        sections.each(function () {
            var section = $(this),
                name_match = fold(section.find('.rb-fd-name').first().text() + ' ' + section.attr('data-format')).indexOf(query) >= 0,
                hits = query.length >= 2 ? section.find('.rb-fd-chip, .rb-fd-thumb').filter(function () {
                    return fold($(this).text()).indexOf(query) >= 0;
                }) : $(),
                visible = name_match || hits.length > 0;
            hits.addClass('is-hit').closest('details').prop('open', true);
            if(!name_match && hits.length) {
                by_card++;
            }
            section.prop('hidden', !visible);
            root.find('.rb-fd-nav li[data-anchor="' + section.attr('id') + '"]').prop('hidden', !visible);
            shown += visible ? 1 : 0;
        });
        root.find('.rb-fd-status').text(t(by_card ? 'formats.status_by_card' : 'formats.status', {shown: shown, total: sections.length, cards: by_card}));
    }

    /* ---- going to a format, and following the reading ---- */

    /**
     * #<anchor> in the address bar, with no history entry and no hashchange. Not before the page
     * has loaded: the browser scrolls to the address's # when it finishes loading
     */
    function set_hash(anchor)
    {
        if(document.readyState === 'complete' && window.location.hash !== '#' + anchor && window.history.replaceState) {
            try {
                window.history.replaceState(window.history.state, '', '#' + anchor);
            } catch (ignored) {
                // a sandboxed page may refuse; the link still works
            }
        }
    }

    function mark_active(anchor)
    {
        var items = root.find('.rb-fd-nav li').removeClass('is-active'),
            item = items.filter('[data-anchor="' + anchor + '"]').addClass('is-active'),
            side = root.find('.rb-fd-side')[0];
        if(item.length && side && side.scrollHeight > side.clientHeight) {
            var top = item[0].offsetTop;
            if(top < side.scrollTop || top > side.scrollTop + side.clientHeight - 30) {
                side.scrollTop = Math.max(0, top - side.clientHeight / 3);
            }
        }
    }

    /** Goes to a format (a filter hiding it cleared first), and lights it up when it's there */
    function go(anchor, smooth)
    {
        var entry = anchor && document.getElementById(anchor);
        if(!entry || !$(entry).hasClass('rb-fd-format')) {
            return false;
        }
        pin = {anchor: anchor, until: Date.now() + PIN_MS};
        if(entry.hidden) {
            root.find('.rb-fd-filter').val('');
            apply_filter('');
        }
        mark_active(anchor);
        set_hash(anchor);
        root.find('.rb-fd-format.is-highlight').removeClass('is-highlight');
        var light = function () {
            $(entry).addClass('is-highlight');
            setTimeout(function () { $(entry).removeClass('is-highlight'); }, 4000);
        };
        if(smooth === false) {
            entry.scrollIntoView({block: 'start'});
            light();
        } else {
            app.ui.glide(entry, light);
        }
        return true;
    }

    /** The format at the top of the pane, or the one just gone to while it's on the way or in view */
    function spy()
    {
        var view = box.getBoundingClientRect(),
            top = (box === document.scrollingElement || box === document.documentElement ? 0 : view.top) + 40,
            current = null;
        root.find('.rb-fd-format:not([hidden])').each(function () {
            if(this.getBoundingClientRect().bottom > top) {
                current = this.id;
                return false;
            }
        });
        if(pin) {
            var pinned = document.getElementById(pin.anchor),
                rect = pinned && !pinned.hidden ? pinned.getBoundingClientRect() : null,
                in_view = rect && rect.height > 0 && rect.top < view.bottom && rect.bottom > view.top;
            if(rect && (Date.now() < pin.until || in_view)) {
                current = pin.anchor;
            } else {
                pin = null;
            }
        }
        if(current) {
            mark_active(current);
            set_hash(current);
        }
    }

    /** The list as tall as the pane it scrolls in (the pane's padding left out) */
    function fit()
    {
        var height = box.clientHeight,
            style = window.getComputedStyle(box);
        if(box !== document.scrollingElement && box !== document.documentElement) {
            height -= (parseFloat(style.paddingTop) || 0) + (parseFloat(style.paddingBottom) || 0);
        } else {
            height = window.innerHeight;
        }
        root[0].style.setProperty('--rb-fd-view-height', Math.max(200, height - 20) + 'px');
    }

    function anchor_of_hash()
    {
        var match = /^#(format-[A-Za-z0-9_-]+)$/.exec(window.location.hash || '');
        return match ? match[1] : null;
    }

    ui.on_dom_loaded = function on_dom_loaded()
    {
        $('.rb-fd-info').on('click', function () {
            var open = $(this).attr('aria-expanded') !== 'true';
            $(this).attr('aria-expanded', open ? 'true' : 'false');
            $('#' + $(this).attr('aria-controls')).prop('hidden', !open);
        });
        root = $('#format-definitions');
        if(!root.length) {
            return;
        }
        box = app.ui.scroller(root[0]);
        fit();
        $(window).on('resize', _.debounce(fit, 100));

        root.find('.rb-fd-filter').on('input', function () {
            clearTimeout(filter_timer);
            var value = $(this).val();
            filter_timer = setTimeout(function () { apply_filter(value); }, 120);
        });
        root.on('change', '.rb-fd-images', function () {
            stored(IMAGES_KEY, this.checked ? '1' : '0');
            set_images(this.checked);
        });
        root.on('click', '.rb-fd-nav a, .rb-fd-permalink', function (event) {
            event.preventDefault();
            go($(this).attr('data-anchor'));
        });
        if(stored(IMAGES_KEY) === '1') {
            set_images(true);
        }

        // the reader scrolling by hand lets go of a format just gone to
        var scroll_target = box === document.scrollingElement || box === document.documentElement ? window : box;
        $(scroll_target).on('wheel touchstart keydown mousedown', function () { pin = null; })
            .on('scroll', function () {
                if(spy_frame === null) {
                    spy_frame = window.requestAnimationFrame(function () {
                        spy_frame = null;
                        spy();
                    });
                }
            });
        $(window).on('hashchange', function () {
            go(anchor_of_hash());
        });
        // a #format-<code> link: straight there, lit up
        if(!go(anchor_of_hash(), false)) {
            spy();
        }
    };

})(app.ui, jQuery);
