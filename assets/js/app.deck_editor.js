/* global app, _, Translator */
/**
 * The deckbuilder's deck list (the "green" region, Ketura's design of 2026-11-02).
 *
 * - Display options in a toolbar above the deck's scrolling area: list / thumbnails /
 *   half-thumbnails, and grouping by culture, type or none.
 * - A heading with the deck's totals collapses its top part: the Ring-bearer, Ring
 *   and map to one line each, the sites to list lines in three columns. Free
 *   Peoples and Shadow collapse away entirely from their own headings.
 * - Every draw-deck card has X 1 2 3 4: X takes it out of the deck but keeps the
 *   line (the X becomes Trash, which deletes the line), and numbers above the
 *   format's limit are disabled.
 * - The Ring-bearer, Ring and Adventure Deck have + buttons that point the card pool
 *   at the right cards (deckbuilder.find); an empty numbered site has its own +.
 * - Cards Gemp complains about are red with a dashed underline (red dashed border on
 *   thumbnails), for colour-blind readers too.
 */
(function app_deck_editor(editor, $)
{
    var container = null,
        toolbar = null,
        // the reader (Ketura, 2026-11-04): the same deck, shown not edited; its own view settings
        readonly = false,
        prefix = '',
        // the nine sites don't fit beside the Ring-bearer as thumbnails: they're list lines instead
        sites_fallback = false,
        // the view or grouping just clicked, whose word shows for a moment
        flash = null, flash_timer = null,
        TYPE_ORDER = ['companion', 'ally', 'follower', 'minion', 'artifact', 'possession', 'condition', 'event'],
        ICONS = {
            list: '<svg width="18" height="18" viewBox="0 0 18 18" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><path d="M3 4.5h12M3 9h12M3 13.5h12"/></svg>',
            thumb: '<svg width="18" height="18" viewBox="0 0 18 18" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><rect x="2.5" y="2" width="5.5" height="14" rx="1"/><rect x="10" y="2" width="5.5" height="14" rx="1"/></svg>',
            half: '<svg width="18" height="18" viewBox="0 0 18 18" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><rect x="2.5" y="2" width="5.5" height="6.5" rx="1"/><rect x="10" y="2" width="5.5" height="6.5" rx="1"/><rect x="2.5" y="9.5" width="5.5" height="6.5" rx="1"/><rect x="10" y="9.5" width="5.5" height="6.5" rx="1"/></svg>',
            // generic marks for the groupings (Ketura, 2026-11-03): a banner, mixed shapes, none
            culture: '<svg width="16" height="16" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3.5 14.5V1.5M3.5 2.5h9l-2.2 3 2.2 3h-9"/></svg>',
            type: '<svg width="16" height="16" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round" aria-hidden="true"><circle cx="4.5" cy="4.5" r="2.6"/><rect x="9" y="2" width="5" height="5" rx=".6"/><path d="M4.5 9.2l2.9 4.9H1.6z"/><path d="M11.5 9l2.6 2.6-2.6 2.6-2.6-2.6z"/></svg>',
            none: '<svg width="16" height="16" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" aria-hidden="true"><circle cx="8" cy="8" r="6"/><path d="M3.8 12.2l8.4-8.4"/></svg>',
            chevron_up: '<svg width="12" height="12" viewBox="0 0 12 12" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 7.5l3-3 3 3"/></svg>',
            chevron_down: '<svg width="12" height="12" viewBox="0 0 12 12" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 4.5l3 3 3-3"/></svg>',
            x: '<svg width="12" height="12" viewBox="0 0 14 14" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" aria-hidden="true"><path d="M3 3l8 8M11 3l-8 8"/></svg>',
            trash: '<svg width="13" height="13" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2.5 4h11M6 4V2.5h4V4M4 4l.7 9.5h6.6L12 4M6.8 6.5v4.5M9.2 6.5v4.5"/></svg>'
        };

    editor.ICONS = ICONS;

    function view()
    {
        return app.config.get(prefix + 'deck-view') || 'list';
    }

    function grouping()
    {
        return app.config.get(prefix + 'deck-group') || 'culture';
    }

    /** A card with a note in the deck's annotations gets a mark the reader links to it */
    function annotation_mark(card)
    {
        if(!readonly || !_.findWhere(app.deck.get_annotations ? app.deck.get_annotations() : [], {code: card.code})) {
            return '';
        }
        return '<span class="rb-annot-mark" data-annot="' + card.code + '" title="' + t('decklist.reader.has_note') + '" aria-hidden="true">'
            + '<svg width="12" height="12" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round"><path d="M2.5 2.5h11v8h-6l-3.5 3v-3h-1.5z"/></svg></span>';
    }

    function t(key, params)
    {
        return Translator.trans(key, params || {});
    }

    /* ---- pieces ---- */

    function display_options()
    {
        var v = view(), g = grouping(),
            vbtn = function (code) {
                return '<button type="button" class="rb-opt rb-opt-view' + (v === code ? ' active' : '') + (flash === code ? ' show-label' : '') + '" data-view="' + code + '" aria-pressed="' + (v === code)
                    + '" title="' + t('deckbuilder.view.' + code) + '">' + ICONS[code] + '<span>' + t('deckbuilder.view.' + code + '_short') + '</span></button>';
            },
            gbtn = function (code, label) {
                return '<button type="button" class="rb-opt rb-opt-group' + (g === code ? ' active' : '') + (flash === code ? ' show-label' : '') + '" data-group="' + code + '" aria-pressed="' + (g === code)
                    + '" title="' + t('deckbuilder.group.' + code + '_hint') + '">' + ICONS[code] + '<span>' + label + '</span></button>';
            };
        // one row above the deck's scrolling area, so it never scrolls away (Ketura, 2026-11-03)
        return '<div class="rb-display-opts" role="group" aria-label="' + t('deckbuilder.view.label') + '">'
            + vbtn('list') + vbtn('thumb') + vbtn('half')
            + '</div><div class="rb-display-opts" role="group" aria-label="' + t('deckbuilder.group.label') + '">'
            + gbtn('culture', t('deckbuilder.group.culture')) + gbtn('type', t('deckbuilder.group.type')) + gbtn('none', t('deckbuilder.group.none'))
            + '</div>';
    }

    function plus(find, label, extra)
    {
        if(readonly) {
            return '';
        }
        return '<button type="button" class="rb-plus" data-find="' + find + '"' + (extra || '') + ' aria-label="' + _.escape(label) + '" title="' + _.escape(label) + '">+</button>';
    }

    /**
     * The Ring-bearer, Ring, sites and map come straight out (Ketura, 2026-11-03):
     * their + finds a replacement in a click, so there's no keeping the line.
     */
    function removes_at_once(slot)
    {
        return slot.key.indexOf('rb:') === 0 || app.deck.role_for_card(slot.card) !== 'draw';
    }

    function x_or_trash(slot)
    {
        if(readonly) {
            return '';
        }
        var name = _.escape(slot.card.label);
        if(removes_at_once(slot)) {
            return '<button type="button" class="rb-x rb-x-now" aria-label="' + t('deckbuilder.trash', {card: name}) + '" title="' + t('deckbuilder.remove_now') + '">' + ICONS.x + '</button>';
        }
        if(slot.pending) {
            return '<button type="button" class="rb-trash" aria-label="' + t('deckbuilder.trash', {card: name}) + '" title="' + t('deckbuilder.trash_hint') + '">' + ICONS.trash + '</button>';
        }
        return '<button type="button" class="rb-x" aria-label="' + t('deckbuilder.remove', {card: name}) + '" title="' + t('deckbuilder.remove_hint') + '">' + ICONS.x + '</button>';
    }

    /** X 1 2 3 4: the numbers only for draw-deck cards */
    function qty_control(slot, numbers)
    {
        // reading: "4x Card", which pastes straight into Gemp's import (Ketura, 2026-11-04)
        if(readonly) {
            return numbers ? '<span class="rb-qty-text">' + slot.qty + 'x</span>' : '';
        }
        var html = '<div class="rb-qty" role="group" aria-label="' + t('deckbuilder.copies_of', {card: _.escape(slot.card.label)}) + '">' + x_or_trash(slot);
        if(numbers) {
            var max = app.deck.max_quantity(slot.card), limit = Math.max(4, max);
            for(var n = 1; n <= limit; n++) {
                // every count up to the current one is filled, so counts compare at a glance (Ketura, 2026-11-03)
                var on = !slot.pending && slot.qty === n,
                    filled = !slot.pending && n <= slot.qty,
                    disabled = n > max;
                html += '<button type="button" class="rb-n' + (on ? ' active' : '') + (filled ? ' is-filled' : '') + '" data-qty="' + n + '" aria-pressed="' + on + '"'
                    + (disabled ? ' disabled title="' + t('deckbuilder.over_limit') + '"' : '')
                    + ' aria-label="' + Translator.transChoice('deckbuilder.n_copies', n, {count: n}) + '">' + n + '</button>';
            }
        }
        return html + '</div>';
    }

    /** Starting fellowship (Ketura, 2026-11-03): an S on draw-deck companions, grey or yellow */
    function start_button(slot)
    {
        if(slot.card.type_code !== 'companion' || slot.key.indexOf('rb:') === 0) {
            return '';
        }
        var on = app.deck.is_starting(slot.card.code);
        if(readonly) {
            // only the ones that start, and out of the way of a copy and paste
            return on ? '<span class="rb-start active rb-start-mark" title="' + t('deckbuilder.starting_title') + '" aria-label="' + t('deckbuilder.starting_title') + '"></span>' : '';
        }
        var label = t(on ? 'deckbuilder.starting_off' : 'deckbuilder.starting_on', {card: _.escape(slot.card.label)});
        return '<button type="button" class="rb-start' + (on ? ' active' : '') + '" data-start="' + slot.card.code + '" aria-pressed="' + on
            + '" aria-label="' + label + '" title="' + t('deckbuilder.starting_title') + '">S</button>';
    }

    function name_html(card)
    {
        return '<a class="rb-name card card-tip" href="' + card.url + '" data-code="' + card.code + '">'
            + (card.is_unique ? '&bull;' : '') + _.escape(card.name)
            + (card.subtitle ? '<span class="rb-sub">, ' + _.escape(card.subtitle) + '</span>' : '')
            + '</a>'
            + (card.kind === 'errata' ? ' <span class="rb-pc" title="' + t('card.kind.errata_short') + '">PC</span>' : '');
    }

    /** In the reader the whole row previews its card (Ketura, 2026-11-04); the deckbuilder's rows are full of buttons */
    function row_hover(card)
    {
        return readonly ? ' data-hover-code="' + card.code + '"' : '';
    }

    function classes(slot, problems)
    {
        return (slot.pending ? ' is-pending' : '') + (!slot.pending && problems[slot.card.code] ? ' is-problem' : '');
    }

    function line(slot, problems, options)
    {
        options = options || {};
        // the S sits between the culture icon and the name; other Free Peoples lines keep its
        // space, so the names line up (Ketura, 2026-11-03)
        var start = options.numbers ? start_button(slot) : '';
        if(options.numbers && !start && slot.card.side !== 'shadow' && !readonly) {
            start = '<span class="rb-start-space" aria-hidden="true"></span>';
        }
        return '<div class="rb-line' + classes(slot, problems) + '" data-key="' + slot.key + '" data-code="' + slot.card.code + '"' + row_hover(slot.card) + '>'
            + qty_control(slot, options.numbers)
            + (options.culture ? app.data.cardIconHtml(slot.card) : '')
            + start
            + name_html(slot.card)
            + annotation_mark(slot.card)
            + '</div>';
    }

    /** Four segments under a tile, one dark per copy, so counts compare at a glance (Ketura, 2026-11-04) */
    function copies_strip(qty)
    {
        var html = '<div class="rb-copies" aria-hidden="true">';
        for(var n = 1; n <= Math.max(4, qty); n++) {
            html += '<span' + (n <= qty ? ' class="is-on"' : '') + '></span>';
        }
        return html + '</div>';
    }

    function tile(slot, problems, options)
    {
        options = options || {};
        var card = slot.card;
        return '<div class="rb-tile' + classes(slot, problems) + (options.overlay ? ' rb-tile-overlay' : '') + '" data-key="' + slot.key + '" data-code="' + card.code + '">'
            + '<div class="rb-tile-img" data-hover-code="' + card.code + '" data-hover-grow="1">'
            + (card.image_url ? '<img src="' + card.image_url + '" alt="' + _.escape(card.label) + '" loading="lazy">' : '<span class="rb-tile-noimg">' + _.escape(card.label) + '</span>')
            + (options.number ? '<span class="rb-site-num">' + options.number + '</span>' : '')
            + (options.numbers && !readonly ? start_button(slot) : '')
            // the reader: the count, the S just right of it (Ketura, 2026-11-04)
            + (readonly && options.numbers ? '<span class="rb-tile-badges"><span class="rb-tile-count">' + slot.qty + 'x</span>' + start_button(slot) + '</span>' : '')
            + (readonly ? annotation_mark(card) : '')
            + (options.overlay ? '<div class="rb-tile-x">' + x_or_trash(slot) + '</div>' : '')
            + '</div>'
            + (readonly && options.numbers ? copies_strip(slot.qty) : '')
            + (options.overlay || readonly ? '' : qty_control(slot, options.numbers))
            + '</div>';
    }

    /* ---- the Ring-bearer, Ring, map ---- */

    function single_slot(role, title, find, find_label, problems)
    {
        var slots = app.deck.get_cards(role),
            v = view(),
            html = '<div class="rb-slot rb-slot-' + role + '"><div class="rb-slot-head"><h3>' + title + '</h3></div>';
        // empty: the + stands where the card's X would be (Ketura, 2026-11-03)
        if(!slots.length && readonly) {
            return html + '<div class="rb-empty">&mdash;</div></div>';
        }
        if(!slots.length) {
            if(v === 'list' || sites_collapsed()) {
                return html + '<div class="rb-line rb-slot-empty">' + plus(find, find_label) + '<span class="rb-choose">' + t('deckbuilder.choose_' + role) + '</span></div></div>';
            }
            return html + '<div class="rb-thumbs rb-thumbs-single' + (v === 'half' ? ' is-half' : '') + '"><div class="rb-tile rb-tile-empty rb-tile-empty-card">'
                + plus(find, find_label) + '</div></div></div>';
        }
        // collapsed, the Ring-bearer, Ring and map are one line each, like the list view
        if(v === 'list' || sites_collapsed()) {
            html += _.map(slots, function (s) { return line(s, problems); }).join('');
        } else {
            html += '<div class="rb-thumbs rb-thumbs-single' + (v === 'half' ? ' is-half' : '') + '">'
                + _.map(slots, function (s) { return tile(s, problems, {overlay: true}); }).join('') + '</div>';
        }
        return html + '</div>';
    }

    /* ---- the Adventure Deck ---- */

    /** Collapsed sections, remembered per browser: top (Ring-bearer, Ring, sites), free_peoples, shadow */
    function collapsed(section)
    {
        return app.config.get(prefix + 'deck-collapsed-' + section) === '1';
    }

    function sites_collapsed()
    {
        return collapsed('top');
    }

    /** A section header that is one big toggle, chevron and all (Ketura, 2026-11-03) */
    function section_toggle(section, label_html, extra_class)
    {
        var shut = collapsed(section);
        return '<button type="button" class="rb-section-toggle' + (extra_class ? ' ' + extra_class : '') + '" data-collapse="' + section + '" aria-expanded="' + !shut + '">'
            + '<span class="rb-section-label">' + label_html + '</span>'
            + '<span class="rb-collapse" title="' + t(shut ? 'deckbuilder.expand' : 'deckbuilder.collapse') + '">' + (shut ? ICONS.chevron_down : ICONS.chevron_up) + '</span></button>';
    }

    function adventure_deck(problems)
    {
        var sites = app.deck.get_cards('site'),
            format = app.deck.get_format(),
            numbered = !format || format.numbered_sites,
            live = _.reject(sites, 'pending'),
            count = live.length,
            v = view(),
            // collapsed, the sites are list lines in three columns (Ketura, 2026-11-03)
            shut = sites_collapsed(),
            html = '<div class="rb-slot rb-sites"><div class="rb-slot-head"><h3>' + t('deckbuilder.adventure_deck', {count: count}) + '</h3>'
                + (count < 9 ? plus('sites', t('deckbuilder.find_sites')) : '') + '</div>';

        var rows = [];
        if(numbered) {
            for(var n = 1; n <= 9; n++) {
                var here = _.filter(sites, function (s) { return s.card.site_number === n; });
                if(here.length) {
                    _.each(here, function (s) { rows.push({n: n, slot: s}); });
                } else {
                    rows.push({n: n, slot: null});
                }
            }
            _.each(_.filter(sites, function (s) { return !s.card.site_number || s.card.site_number > 9; }), function (s) {
                rows.push({n: '', slot: s});
            });
        } else {
            rows = _.map(sites, function (s) { return {n: '', slot: s}; });
        }

        if(v === 'list' || shut || sites_fallback) {
            html += '<div class="rb-site-list' + (shut ? ' is-columns' : '') + '">' + _.map(rows, function (row) {
                if(!row.slot) {
                    return '<div class="rb-line rb-site-empty"><span class="rb-site-n">' + row.n + '</span>'
                        + plus('site', t('deckbuilder.find_site', {number: row.n}), ' data-site="' + row.n + '"')
                        + '<span class="rb-choose">' + (readonly ? '&mdash;' : t('deckbuilder.choose_site', {number: row.n})) + '</span></div>';
                }
                return '<div class="rb-line' + classes(row.slot, problems) + '" data-key="' + row.slot.key + '" data-code="' + row.slot.card.code + '"' + row_hover(row.slot.card) + '>'
                    + '<span class="rb-site-n">' + row.n + '</span>' + x_or_trash(row.slot) + name_html(row.slot.card) + '</div>';
            }).join('') + (!rows.length ? '<div class="rb-empty">&mdash;</div>' : '') + '</div>';
        } else {
            html += '<div class="rb-thumbs rb-thumbs-sites">' + _.map(rows, function (row) {
                if(!row.slot) {
                    return '<div class="rb-tile rb-tile-site rb-tile-empty">' + plus('site', t('deckbuilder.find_site', {number: row.n}), ' data-site="' + row.n + '"')
                        + '<span class="rb-site-num">' + row.n + '</span></div>';
                }
                return tile(row.slot, problems, {overlay: true, number: row.n}).replace('rb-tile', 'rb-tile rb-tile-site');
            }).join('') + '</div>';
        }
        return html + '</div>';
    }

    /* ---- the draw deck ---- */

    function type_rank(card)
    {
        var i = TYPE_ORDER.indexOf(card.type_code);
        return i === -1 ? 99 : i;
    }

    /** Starting companions before the rest; otherwise no different */
    function start_rank(slot)
    {
        return app.deck.is_starting(slot.card.code) ? 0 : 1;
    }

    function by_cost(a, b)
    {
        return (a.card.twilight || 0) - (b.card.twilight || 0) || a.card.label.localeCompare(b.card.label);
    }

    function groups_for(slots)
    {
        var g = grouping(), groups;
        if(g === 'culture') {
            groups = _.map(_.groupBy(slots, function (s) { return s.card.culture_code || ''; }), function (list, code) {
                return {key: code, label: list[0].card.culture_name || t('deck.no_culture'), icon: code, slots: list};
            });
            groups = _.sortBy(groups, 'label');
            _.each(groups, function (gr) {
                gr.slots.sort(function (a, b) { return type_rank(a.card) - type_rank(b.card) || start_rank(a) - start_rank(b) || by_cost(a, b); });
            });
        } else if(g === 'type') {
            groups = _.map(_.groupBy(slots, function (s) { return s.card.type_code; }), function (list, code) {
                return {key: code, label: list[0].card.type_name, slots: list, rank: type_rank(list[0].card)};
            });
            groups = _.sortBy(groups, 'rank');
            _.each(groups, function (gr) {
                gr.slots.sort(function (a, b) { return start_rank(a) - start_rank(b) || (a.card.culture_name || '').localeCompare(b.card.culture_name || '') || by_cost(a, b); });
            });
        } else {
            slots = slots.slice().sort(function (a, b) {
                return type_rank(a.card) - type_rank(b.card) || start_rank(a) - start_rank(b) || (a.card.culture_name || '').localeCompare(b.card.culture_name || '') || by_cost(a, b);
            });
            groups = [{key: 'all', label: null, slots: slots}];
        }
        return groups;
    }

    function count(slots)
    {
        return _.reduce(slots, function (sum, s) { return sum + (s.pending ? 0 : s.qty); }, 0);
    }

    function side_html(side, slots, problems)
    {
        var v = view(),
            g = grouping(),
            shut = collapsed(side),
            html = '<section class="rb-side rb-side-' + side + (shut ? ' is-collapsed' : '') + '"><h3 class="rb-side-head">'
                + section_toggle(side, _.escape(t('side.' + side)) + ' (' + count(slots) + ')') + '</h3>';
        if(shut) {
            return html + '</section>';
        }
        if(!slots.length) {
            return html + '<div class="rb-empty">&mdash;</div></section>';
        }
        html += '<div class="rb-groups' + (v === 'list' ? ' view-list-groups' : '') + '">';
        _.each(groups_for(slots), function (group) {
            html += '<div class="rb-group">';
            if(group.label !== null) {
                html += '<div class="rb-group-head">' + (g === 'culture' ? app.data.cultureIconHtml(group.icon, group.label) : '')
                    + '<span>' + _.escape(group.label) + '</span> <span class="rb-count">(' + count(group.slots) + ')</span></div>';
            }
            if(v === 'list') {
                // as wide as the longest line and a little more, so the empty space beside the
                // list isn't a field of invisible rows (Ketura, 2026-11-03)
                html += '<div class="rb-lines">' + _.map(group.slots, function (s) { return line(s, problems, {numbers: true, culture: g !== 'culture'}); }).join('') + '</div>';
            } else {
                html += '<div class="rb-thumbs' + (v === 'half' ? ' is-half' : '') + '">'
                    + _.map(group.slots, function (s) { return tile(s, problems, {numbers: true}); }).join('') + '</div>';
            }
            html += '</div>';
        });
        return html + '</div></section>';
    }

    /* ---- render ---- */

    editor.render = function render()
    {
        if(!container) {
            return;
        }
        var problems = app.deck.get_problem_codes(),
            format = app.deck.get_format(),
            draw = app.deck.get_cards('draw'),
            v = view(),
            fp = _.filter(draw, function (s) { return s.card.side !== 'shadow'; }),
            shadow = _.filter(draw, function (s) { return s.card.side === 'shadow'; }),
            rb = app.deck.get_cards('ring_bearer'),
            ring = app.deck.get_cards('ring'),
            map = app.deck.get_cards('map'),
            totals = [t('deckbuilder.draw_deck', {count: count(draw)}),
                t('deckbuilder.adventure_deck', {count: _.reject(app.deck.get_cards('site'), 'pending').length})]
                .concat(_.reject(rb, 'pending').length ? [t('deck.ring_bearer')] : [])
                .concat(_.reject(ring, 'pending').length ? [t('deck.ring_short')] : [])
                .concat(_.reject(map, 'pending').length ? [t('deck.map')] : []),
            // the totals head the deck and collapse its top part (Ketura, 2026-11-03)
            html = '<h2 class="rb-deck-head">' + section_toggle('top', _.map(totals, _.escape).join(', ')) + '</h2>'
                + '<div class="rb-deck-top view-' + v + (sites_collapsed() ? ' is-collapsed' : '') + '"><div class="rb-deck-top-left">'
                + single_slot('ring_bearer', t('deck.ring_bearer'), 'ring_bearer', t('deckbuilder.find_ring_bearer'), problems)
                + single_slot('ring', t('deck.ring_short'), 'ring', t('deckbuilder.find_ring'), problems);
        if(app.deck.get_cards('map').length || (format && format.uses_maps)) {
            html += single_slot('map', t('deck.map'), 'map', t('deckbuilder.find_map'), problems);
        }
        html += '</div><div class="rb-deck-top-right">' + adventure_deck(problems) + '</div></div>'
            + '<div class="rb-sides">' + side_html('free_peoples', fp, problems) + side_html('shadow', shadow, problems) + '</div>';
        $(container).removeClass('deck-loading').html(html);
        if(toolbar) {
            toolbar.html(display_options());
            pin_option_widths();
        }
        fit_sites();
    };

    /**
     * The view and grouping buttons show only their icons, at the width their word gives them
     * (Ketura, 2026-11-04): measured with every word showing and no animation, then pinned.
     */
    function pin_option_widths()
    {
        var buttons = toolbar.find('.rb-opt');
        toolbar.addClass('rb-no-anim rb-measuring');
        var widths = buttons.map(function () { return this.getBoundingClientRect().width; }).get();
        // a hidden toolbar measures nothing: leave its buttons to size themselves
        buttons.each(function (i) { this.style.width = widths[i] ? Math.ceil(widths[i]) + 'px' : ''; });
        toolbar.removeClass('rb-measuring');
        void toolbar[0].offsetWidth;
        toolbar.removeClass('rb-no-anim');
    }

    /** A clicked view or grouping says its name for two seconds, then the word slides away */
    function flash_label(code)
    {
        flash = code;
        clearTimeout(flash_timer);
        flash_timer = setTimeout(function () {
            flash = null;
            toolbar && toolbar.find('.rb-opt.show-label').removeClass('show-label');
        }, 2000);
    }

    /**
     * Thumbnails of the sites that would need a sideways scroll to see are shown as list
     * lines instead (Ketura, 2026-11-04); a resize tries the thumbnails again.
     */
    function fit_sites()
    {
        var thumbs = $(container).find('.rb-thumbs-sites')[0];
        if(!sites_fallback && thumbs && thumbs.scrollWidth > thumbs.clientWidth + 1) {
            sites_fallback = true;
            editor.render();
        }
    }

    function refit()
    {
        if(sites_fallback && view() !== 'list' && !sites_collapsed()) {
            sites_fallback = false;
            editor.render();
        }
    }

    /**
     * A selection across the reader's deck copies as one line per card, "4x Card", the way
     * Gemp's import reads them (Ketura, 2026-11-04). The page's own layout would put the count
     * and the name on lines of their own. Headings are left out; a selection inside one line
     * copies as it is.
     */
    function copy_lines(event)
    {
        var selection = window.getSelection(),
            clipboard = event.originalEvent && event.originalEvent.clipboardData;
        if(!clipboard || !selection || selection.isCollapsed) {
            return;
        }
        var lines = [],
            content = app.deck.get_content();
        container.find('.rb-line[data-key], .rb-tile[data-key]').each(function () {
            if(!selection.containsNode(this, true)) {
                return;
            }
            var key = $(this).data('key') + '',
                card = app.data.cards.findById($(this).data('code') + ''),
                role = key.indexOf('rb:') === 0 ? 'ring_bearer' : (card ? app.deck.role_for_card(card) : null);
            if(!card) {
                return;
            }
            if(role === 'ring_bearer' || role === 'ring' || role === 'map') {
                lines.push({ring_bearer: 'Ring-bearer: ', ring: 'Ring: ', map: 'Map: '}[role] + card.label);
            } else {
                lines.push((content[key] || 1) + 'x ' + card.label);
            }
        });
        if(lines.length < 2) {
            return;
        }
        clipboard.setData('text/plain', lines.join('\n'));
        clipboard.setData('text/html', _.map(lines, _.escape).join('<br>'));
        event.preventDefault();
    }

    /* ---- events ---- */

    /**
     * options.readonly: the reader's deck (Ketura, 2026-11-04): counts as "4x", no controls,
     * and its view, grouping and collapsed sections kept apart from the deckbuilder's
     */
    editor.setup = function setup(selector, toolbar_selector, options)
    {
        container = $(selector);
        toolbar = toolbar_selector ? $(toolbar_selector) : null;
        readonly = !!(options && options.readonly);
        prefix = readonly ? 'reader-' : '';
        container.toggleClass('rb-readonly', readonly);
        $(toolbar || container).on('click', '.rb-opt-view', function () {
            app.config.set(prefix + 'deck-view', $(this).data('view'));
            flash_label($(this).data('view'));
            sites_fallback = false;
            editor.render();
        });
        $(toolbar || container).on('click', '.rb-opt-group', function () {
            app.config.set(prefix + 'deck-group', $(this).data('group'));
            flash_label($(this).data('group'));
            editor.render();
        });
        container.on('click', '[data-collapse]', function () {
            var section = $(this).data('collapse');
            app.config.set(prefix + 'deck-collapsed-' + section, collapsed(section) ? '0' : '1');
            sites_fallback = false;
            editor.render();
        });
        $(window).on('resize', _.debounce(refit, 200));
        if(readonly) {
            container.on('copy', copy_lines);
            $(document).on('deck.changed', editor.render);
            return;
        }
        container.on('click', '.rb-n:not([disabled])', function () {
            var code = $(this).closest('[data-code]').data('code') + '';
            app.deck.set_quantity(code, parseInt($(this).data('qty'), 10));
        });
        container.on('click', '.rb-start', function (event) {
            event.stopPropagation();
            app.deck.toggle_starting($(this).data('start') + '');
        });
        container.on('click', '.rb-x-now', function () {
            app.deck.remove($(this).closest('[data-key]').data('key') + '');
        });
        container.on('click', '.rb-x:not(.rb-x-now)', function () {
            app.deck.set_pending($(this).closest('[data-key]').data('key') + '', true);
        });
        container.on('click', '.rb-trash', function () {
            app.deck.remove($(this).closest('[data-key]').data('key') + '');
        });
        container.on('click', '[data-find]', function () {
            $(document).trigger('deckbuilder.find', [{slot: $(this).data('find'), site: $(this).data('site') || null}]);
        });
        $(document).on('deck.changed deck.validated', editor.render);
    };

})(app.deck_editor = {}, jQuery);
