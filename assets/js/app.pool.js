/* global app, _, Translator */
/**
 * The deckbuilder's card filters ("orange") and card pool ("navy"), Ketura's design of
 * 2026-11-02.
 *
 * - The deck's format is the first cut: only its sets, and in a PC format the PC
 *   errata instead of the Decipher card (every other format shows Decipher). Banned
 *   cards stay visible, greyed, and can still be added (the format may be wrong).
 * - Filters reset on every visit; the "Showing" band lists every active one, each
 *   removable, with the number of cards.
 * - The pool is paged to fit the space it has. Its + is blue when the card isn't in
 *   the deck, yellow when it is, grey when banned or at its limit; companions that
 *   can bear the Ring also get a Ring-bearer button.
 * - Keyword, Mentions and Quick Search come from app.card_terms (interim, see there).
 */
(function app_pool(pool, $)
{
    var FP_CULTURES = ['dwarven', 'elven', 'gandalf', 'gondor', 'rohan', 'shire', 'gollum'],
        // Men, Orc and Uruk-hai (sets 11+) last (Ketura, 2026-11-02)
        SHADOW_CULTURES = ['dunland', 'isengard', 'moria', 'raider', 'ringwraith', 'sauron', 'men', 'orc', 'uruk_hai'],
        CHARACTER_TYPES = ['companion', 'ally', 'minion', 'follower'],
        ITEM_TYPES = ['artifact', 'possession'],
        STATS = ['twilight', 'strength', 'vitality', 'resistance', 'site_number'],
        GAP = 10,
        TILE_MIN = 118,
        ROW_H = 32,
        state,
        sort = 'name',
        page = 1,
        more_open = false,
        base_cache = {key: null, cards: []},
        options = {},
        last_page_size = 24,
        filters_el,
        pool_el,
        terms = app.card_terms;

    function t(key, params)
    {
        return Translator.trans(key, params || {});
    }

    function defaults()
    {
        var stats = {};
        _.each(STATS, function (s) { stats[s] = {op: '=', value: ''}; });
        return {
            text: '', block: '', set: '', side: '', type: '', cultures: {},
            unique: '', race: '', item_class: '', phase: '', keyword: '', signet: '', can_bear: false,
            stats: stats, quick: '',
            m_culture: '', m_type: '', m_race: '', m_item: '', m_keyword: '',
            game_text: '', lore: '', rarity: '',
            slot: null, site: null
        };
    }
    state = defaults();

    function view()
    {
        return app.config.get('pool-view') || 'thumb';
    }

    /** Lower case without accents: "anduril" finds Andúril, "nazgul" Nazgûl */
    function fold(s)
    {
        return (s || '').normalize('NFD').replace(/[̀-ͯ]/g, '').replace(/[••]/g, '').toLowerCase();
    }

    function pack(code)
    {
        return app.data.packs.findById(code) || {};
    }

    /* ---- the format's cut ---- */

    // As in FormatGuesser: the wiki has no Block for some sites, so the set stands in
    var SITE_BLOCK_BY_SET = {
        '1': 'Fellowship', '2': 'Fellowship', '3': 'Fellowship', '101': 'Fellowship',
        '4': 'Towers', '5': 'Towers', '6': 'Towers', '102': 'Towers',
        '7': 'King', '8': 'King', '9': 'King', '10': 'King', '103': 'King'
    };

    /** Fellowship, Towers, King, Shadows, or null when it can't be told */
    function site_block(card)
    {
        if(card.site_block) {
            return card.site_block;
        }
        var set = card.pack_code;
        if(card.kind === 'errata' && card.errataOf_code) {
            set = card.errataOf_code.split('_')[0];
        }
        if(SITE_BLOCK_BY_SET[set]) {
            return SITE_BLOCK_BY_SET[set];
        }
        var n = parseInt(set, 10);
        return n >= 11 && n < 50 ? 'Shadows' : null;
    }

    /**
     * The cards a deck in this format can draw on: its sets; PC errata in PC formats
     * instead of the cards they replace, Decipher cards everywhere else (Ketura, 2026-11-02).
     */
    function base_cards()
    {
        var format = app.deck.get_format(),
            key = format ? format.code + '@' + format.date_update : '-';
        if(base_cache.key === key) {
            return base_cache.cards;
        }
        var all = app.data.cards.find(),
            sets = format && format.sets && format.sets.length ? format.sets : null,
            pc = !!(format && format.pc_errata),
            replaced = {};
        if(pc) {
            _.each(all, function (card) {
                if(card.kind === 'errata' && card.errataOf_code && (!sets || sets.indexOf(card.pack_code) !== -1)) {
                    replaced[card.errataOf_code] = true;
                }
            });
        }
        var path = format ? format.sites : null,
            one_path = path === 'Fellowship' || path === 'Towers' || path === 'King' || path === 'Shadows and onwards';
        base_cache = {
            key: key,
            cards: _.filter(all, function (card) {
                if(sets && sets.indexOf(card.pack_code) === -1) {
                    return false;
                }
                // a format with one adventure path only offers that path's sites
                if(one_path && card.type_code === 'site') {
                    var block = site_block(card);
                    if(block && block !== (path === 'Shadows and onwards' ? 'Shadows' : path)) {
                        return false;
                    }
                }
                if(card.kind === 'errata') {
                    return pc;
                }
                return !replaced[card.code];
            })
        };
        build_options();
        return base_cache.cards;
    }

    /** Dropdown contents that depend on the format's cards */
    function build_options()
    {
        var cards = base_cache.cards,
            subtypes = function (types) {
                var found = {};
                _.each(cards, function (card) {
                    if(types.indexOf(card.type_code) !== -1) {
                        _.each(terms.subtypes(card), function (s) { if(s !== 'Support Area') { found[s] = true; } });
                    }
                });
                return _.keys(found).sort();
            },
            packs = _.uniq(_.pluck(cards, 'pack_code')),
            pack_rows = _.sortBy(_.map(packs, function (code) { return pack(code); }), 'position');
        options.sets = _.map(pack_rows, function (p) { return {code: p.code, name: p.name, block: p.cycle_code}; });
        var blocks = {};
        _.each(pack_rows, function (p) {
            if(p.cycle_code && !blocks[p.cycle_code]) {
                blocks[p.cycle_code] = {code: p.cycle_code, name: p.cycle_name, position: p.cycle_position};
            }
        });
        options.blocks = _.sortBy(_.values(blocks), 'position');
        options.races = subtypes(CHARACTER_TYPES);
        options.item_classes = subtypes(ITEM_TYPES);
        options.phases = subtypes(['event']);
        var types = {};
        _.each(cards, function (card) { types[card.type_code] = card.type_name; });
        options.types = _.map(['companion', 'ally', 'follower', 'minion', 'possession', 'artifact', 'condition', 'event', 'site', 'the_one_ring', 'map'],
            function (code) { return types[code] ? {code: code, name: types[code]} : null; });
        options.types = _.compact(options.types);
        var rarities = {};
        _.each(cards, function (card) { if(card.rarity_code) { rarities[card.rarity_code] = card.rarity_name; } });
        options.rarities = _.map(_.keys(rarities).sort(), function (code) { return {code: code, name: rarities[code]}; });
        var cultures = {};
        _.each(app.data.cards.find(), function (card) { if(card.culture_code) { cultures[card.culture_code] = card.culture_name; } });
        options.culture_names = cultures;
        options.signets = _.uniq(_.compact(_.pluck(cards, 'signet'))).sort();
    }

    /* ---- filtering ---- */

    function stat_value(card, stat)
    {
        if(stat === 'twilight') {
            // Twilight is the shadow number on a site (Ketura, 2026-11-02)
            return card.type_code === 'site' ? card.shadow_number : card.twilight;
        }
        return card[stat];
    }

    function stat_matches(card, stat, filter)
    {
        var raw = (filter.value || '').trim();
        if(raw === '') {
            return true;
        }
        var modifier = /^[+-]\d+$/.test(raw),
            wanted = parseInt(raw, 10),
            value = modifier ? card[stat + '_mod'] : stat_value(card, stat);
        if(value === null || value === undefined || value === '') {
            return false;
        }
        value = parseInt(value, 10);
        if(filter.op === '>=') { return value >= wanted; }
        if(filter.op === '<=') { return value <= wanted; }
        return value === wanted;
    }

    function smart_codes(text)
    {
        app.smart_filter.update(text);
        var codes = {};
        _.each(app.data.cards.find(app.smart_filter.get_query({})), function (card) { codes[card.code] = true; });
        return codes;
    }

    /** The format's cards that pass every filter */
    pool.filtered = function filtered()
    {
        var cards = base_cards(),
            s = state,
            text = s.text.trim(),
            smart = /(^|\s)\w[:<>!]/.test(text) ? smart_codes(text) : null,
            folded = fold(text),
            cultures = _.keys(_.pick(s.cultures, _.identity)),
            keyword = s.keyword,
            quick = s.quick ? terms.quick(s.quick) : null,
            game_text = fold(s.game_text),
            lore = fold(s.lore);
        return _.filter(cards, function (card) {
            if(smart ? !smart[card.code] : (folded && fold(card.label).indexOf(folded) === -1)) { return false; }
            if(s.block && pack(card.pack_code).cycle_code !== s.block) { return false; }
            if(s.set && card.pack_code !== s.set) { return false; }
            if(s.side === 'sites') {
                if(['site', 'the_one_ring', 'map'].indexOf(card.type_code) === -1) { return false; }
            } else if(s.side && card.side !== s.side) {
                return false;
            }
            if(s.type && card.type_code !== s.type) { return false; }
            if(cultures.length && cultures.indexOf(card.culture_code) === -1) { return false; }
            if(s.slot === 'ring_bearer' && !(card.type_code === 'companion' && card.can_bear_ring)) { return false; }
            if(s.slot === 'ring' && card.type_code !== 'the_one_ring') { return false; }
            if(s.slot === 'map' && card.type_code !== 'map') { return false; }
            if((s.slot === 'sites' || s.slot === 'site') && card.type_code !== 'site') { return false; }
            if(s.slot === 'site' && s.site && card.site_number !== s.site) { return false; }
            if(s.unique && card.is_unique !== (s.unique === 'yes')) { return false; }
            if(s.race && !(CHARACTER_TYPES.indexOf(card.type_code) !== -1 && _.contains(terms.subtypes(card), s.race))) { return false; }
            if(s.item_class && !(ITEM_TYPES.indexOf(card.type_code) !== -1 && _.contains(terms.subtypes(card), s.item_class))) { return false; }
            if(s.phase && !terms.hasPhase(card, s.phase)) { return false; }
            if(keyword && !terms.hasKeyword(card, keyword)) { return false; }
            if(s.signet && card.signet !== s.signet) { return false; }
            if(s.can_bear && !card.can_bear_ring) { return false; }
            for(var i = 0; i < STATS.length; i++) {
                if(!stat_matches(card, STATS[i], s.stats[STATS[i]])) { return false; }
            }
            if(quick && !quick.test(card)) { return false; }
            if(s.m_culture && !terms.usesCulture(card, options.culture_names[s.m_culture] || s.m_culture)) { return false; }
            if(s.m_type && !terms.usesType(card, _.findWhere(options.types, {code: s.m_type}).name)) { return false; }
            if(s.m_race && !terms.usesRace(card, s.m_race)) { return false; }
            if(s.m_item && !terms.usesItemClass(card, s.m_item)) { return false; }
            if(s.m_keyword && !terms.usesKeyword(card, s.m_keyword)) { return false; }
            if(game_text && fold(card.text).indexOf(game_text) === -1) { return false; }
            if(lore && fold(card.flavor).indexOf(lore) === -1) { return false; }
            if(s.rarity && card.rarity_code !== s.rarity) { return false; }
            return true;
        });
    };

    var SORTS = {
        name: function (a, b) { return a.label.localeCompare(b.label); },
        culture: function (a, b) { return (a.culture_name || '~').localeCompare(b.culture_name || '~') || a.label.localeCompare(b.label); },
        type: function (a, b) { return (a.type_name || '').localeCompare(b.type_name || '') || a.label.localeCompare(b.label); },
        twilight: function (a, b) { return num(stat_value(a, 'twilight')) - num(stat_value(b, 'twilight')) || a.label.localeCompare(b.label); },
        strength: function (a, b) { return num(b.strength, -1) - num(a.strength, -1) || a.label.localeCompare(b.label); },
        set: function (a, b) { return (pack(a.pack_code).position || 0) - (pack(b.pack_code).position || 0) || a.position - b.position; },
        site: function (a, b) {
            return num(a.site_number) - num(b.site_number) || num(a.shadow_number) - num(b.shadow_number) || a.label.localeCompare(b.label);
        }
    };

    function num(v, missing)
    {
        return v === null || v === undefined || v === '' ? (missing === undefined ? 99 : missing) : parseInt(v, 10);
    }

    /* ---- the Showing band ---- */

    function chip(label, remove)
    {
        return '<button type="button" class="rb-chip" data-remove="' + remove + '" aria-label="' + t('deckbuilder.filters.remove', {filter: _.escape(label)}) + '">'
            + _.escape(label) + ' <span aria-hidden="true">&times;</span></button>';
    }

    function active_chips()
    {
        var s = state, chips = [], name = function (list, code) { var row = _.findWhere(list || [], {code: code}); return row ? row.name : code; };
        if(s.slot === 'ring_bearer') { chips.push(chip(t('deckbuilder.filters.ring_bearers'), 'slot')); }
        if(s.slot === 'ring') { chips.push(chip(t('deck.ring'), 'slot')); }
        if(s.slot === 'map') { chips.push(chip(t('deck.map'), 'slot')); }
        if(s.slot === 'sites') { chips.push(chip(t('deckbuilder.filters.sites'), 'slot')); }
        if(s.slot === 'site') { chips.push(chip(t('deckbuilder.filters.site', {number: s.site}), 'slot')); }
        if(s.text) { chips.push(chip('"' + s.text + '"', 'text')); }
        if(s.block) { chips.push(chip(name(options.blocks, s.block), 'block')); }
        if(s.set) { chips.push(chip(name(options.sets, s.set), 'set')); }
        if(s.side) { chips.push(chip(s.side === 'sites' ? t('deckbuilder.filters.side_sites') : t('side.' + s.side), 'side')); }
        if(s.type) { chips.push(chip(name(options.types, s.type), 'type')); }
        _.each(_.keys(_.pick(s.cultures, _.identity)), function (code) { chips.push(chip(options.culture_names[code] || code, 'culture:' + code)); });
        if(s.unique) { chips.push(chip(t('deckbuilder.filters.unique_' + s.unique), 'unique')); }
        if(s.race) { chips.push(chip(s.race, 'race')); }
        if(s.item_class) { chips.push(chip(s.item_class, 'item_class')); }
        if(s.phase) { chips.push(chip(t('deckbuilder.filters.phase_chip', {phase: s.phase}), 'phase')); }
        if(s.keyword) { chips.push(chip(t('deckbuilder.filters.keyword_chip', {keyword: s.keyword}), 'keyword')); }
        if(s.signet) { chips.push(chip(t('deckbuilder.filters.signet_chip', {signet: s.signet}), 'signet')); }
        if(s.can_bear) { chips.push(chip(t('deckbuilder.filters.can_bear'), 'can_bear')); }
        _.each(STATS, function (stat) {
            var f = s.stats[stat];
            if(f.value !== '') {
                chips.push(chip(t('deckbuilder.stats.' + stat) + ' ' + ({'=': '=', '>=': '≥', '<=': '≤'}[f.op]) + ' ' + f.value, 'stat:' + stat));
            }
        });
        if(s.quick) { chips.push(chip(terms.quick(s.quick).label, 'quick')); }
        _.each({m_culture: 'culture', m_type: 'type', m_race: 'race', m_item: 'item', m_keyword: 'keyword'}, function (kind, field) {
            if(s[field]) {
                var label = field === 'm_culture' ? options.culture_names[s[field]] : (field === 'm_type' ? name(options.types, s[field]) : s[field]);
                chips.push(chip(t('deckbuilder.filters.mentions_chip', {what: label}), field));
            }
        });
        if(s.game_text) { chips.push(chip(t('deckbuilder.filters.text_chip', {text: s.game_text}), 'game_text')); }
        if(s.lore) { chips.push(chip(t('deckbuilder.filters.lore_chip', {text: s.lore}), 'lore')); }
        if(s.rarity) { chips.push(chip(name(options.rarities, s.rarity), 'rarity')); }
        return chips;
    }

    function more_count()
    {
        var s = state, n = 0;
        _.each(['unique', 'race', 'item_class', 'phase', 'keyword', 'signet', 'quick', 'm_culture', 'm_type', 'm_race', 'm_item', 'm_keyword', 'game_text', 'lore', 'rarity'], function (k) {
            if(s[k]) { n++; }
        });
        if(s.can_bear) { n++; }
        _.each(STATS, function (stat) { if(s.stats[stat].value !== '') { n++; } });
        return n;
    }

    function remove_filter(what)
    {
        if(what.indexOf('culture:') === 0) {
            delete state.cultures[what.substr(8)];
        } else if(what.indexOf('stat:') === 0) {
            state.stats[what.substr(5)].value = '';
        } else if(what === 'slot') {
            state.slot = null;
            state.site = null;
        } else if(what === 'can_bear') {
            state.can_bear = false;
        } else {
            state[what] = '';
        }
        sync_controls();
        refresh(true);
    }

    /* ---- the filters' markup ---- */

    function select(field, label, rows, any_label, extra_class)
    {
        return '<label class="rb-field' + (extra_class ? ' ' + extra_class : '') + '"><span>' + label + '</span><select data-field="' + field + '">'
            + '<option value="">' + (any_label || t('deckbuilder.filters.any')) + '</option>'
            + _.map(rows, function (r) { return '<option value="' + _.escape(r.code) + '">' + _.escape(r.name) + '</option>'; }).join('')
            + '</select></label>';
    }

    function list(values)
    {
        return _.map(values, function (v) { return {code: v, name: v}; });
    }

    function culture_button(code)
    {
        var name = options.culture_names[code] || code;
        return '<button type="button" class="rb-culture" data-culture="' + code + '" aria-pressed="false" aria-label="' + _.escape(name) + '" title="' + _.escape(name) + '">'
            + '<span class="culture-icon" aria-hidden="true" style="--icon:url(' + app.data.cultureIcon(code) + ')"></span></button>';
    }

    function filters_html()
    {
        var sides = [['', t('deckbuilder.filters.all')], ['free_peoples', t('side.free_peoples')], ['shadow', t('side.shadow')], ['sites', t('deckbuilder.filters.side_sites')]],
            stat_row = function (stat) {
                return '<label class="rb-stat"><span>' + t('deckbuilder.stats.' + stat) + '</span>'
                    + '<select data-stat-op="' + stat + '" aria-label="' + t('deckbuilder.stats.compare', {stat: t('deckbuilder.stats.' + stat)}) + '">'
                    + '<option value="=">=</option><option value=">=">≥</option><option value="<=">≤</option></select>'
                    + '<input type="text" inputmode="numeric" data-stat="' + stat + '" aria-label="' + t('deckbuilder.stats.' + stat) + '"></label>';
            },
            quick = '<option value=""></option>' + _.map(terms.QUICK, function (group) {
                return '<optgroup label="' + _.escape(group.group) + '">' + _.map(group.items, function (item) {
                    return '<option value="' + item[0] + '">' + _.escape(item[1]) + '</option>';
                }).join('') + '</optgroup>';
            }).join(''),
            culture_rows = _.map(FP_CULTURES.concat(SHADOW_CULTURES), function (code) { return {code: code, name: options.culture_names[code] || code}; });

        return '<div class="rb-search"><span class="rb-search-icon" aria-hidden="true"><svg width="16" height="16" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><circle cx="7" cy="7" r="4.5"/><path d="M10.5 10.5l3.5 3.5"/></svg></span>'
            + '<input type="search" id="filter-text" autocomplete="off" placeholder="' + t('deckbuilder.filters.search_placeholder') + '" aria-label="' + t('deckbuilder.filters.search') + '">'
            + '<a href="#" class="rb-search-tips smart-filter-help">' + t('deckbuilder.filters.search_tips') + '</a></div>'
            // the search stays put; the rest scrolls under it (Ketura, 2026-11-03)
            + '<div class="rb-filters-scroll">'
            + '<div class="rb-row rb-row-sets">' + select('block', t('deckbuilder.filters.block'), [], t('deckbuilder.filters.any_in_format'), 'rb-block')
            + select('set', t('deckbuilder.filters.set'), [], t('deckbuilder.filters.any_in_format'), 'rb-set') + '</div>'
            + '<div class="rb-row rb-row-side"><div class="rb-seg" role="group" aria-label="' + t('deckbuilder.filters.side') + '">'
            + _.map(sides, function (s) { return '<button type="button" data-side="' + s[0] + '" aria-pressed="false">' + s[1] + '</button>'; }).join('') + '</div>'
            + select('type', t('deckbuilder.filters.type'), options.types, null, 'rb-type rb-inline') + '</div>'
            + '<div class="rb-cultures" role="group" aria-label="' + t('deckbuilder.filters.cultures') + '">'
            + _.map(FP_CULTURES, culture_button).join('') + '<span class="rb-culture-sep" aria-hidden="true"></span>'
            + _.map(SHADOW_CULTURES, culture_button).join('') + '</div>'
            // More filters opens below its own button, in the Showing band
            + '<section class="rb-showing" aria-label="' + t('deckbuilder.filters.showing') + '"></section>'
            + '<div class="rb-more" hidden>'
            + '<div class="rb-more-cols"><fieldset><legend>' + t('deckbuilder.filters.is_or_has') + '</legend>'
            + '<label class="rb-field rb-inline"><span>' + t('deckbuilder.filters.unique') + '</span><select data-field="unique"><option value="">' + t('deckbuilder.filters.any') + '</option><option value="yes">' + t('deckbuilder.filters.yes') + '</option><option value="no">' + t('deckbuilder.filters.no') + '</option></select></label>'
            + select('race', t('deckbuilder.filters.race'), list(options.races), null, 'rb-inline')
            + select('item_class', t('deckbuilder.filters.item_class'), list(options.item_classes), null, 'rb-inline')
            + select('phase', t('deckbuilder.filters.phase'), list(options.phases), null, 'rb-inline')
            + select('keyword', t('deckbuilder.filters.keyword'), list(terms.KEYWORDS), null, 'rb-inline')
            + select('signet', t('deckbuilder.filters.signet'), list(options.signets), null, 'rb-inline')
            + '<label class="rb-check"><input type="checkbox" data-field="can_bear"> ' + t('deckbuilder.filters.can_bear') + '</label></fieldset>'
            + '<fieldset><legend>' + t('deckbuilder.filters.stats') + '</legend>' + _.map(STATS, stat_row).join('')
            + '<p class="rb-hint">' + t('deckbuilder.stats.hint') + '</p></fieldset></div>'
            // Quick Search: a dropdown on phones; on desktops a button opening every group at once in columns (Ketura, 2026-11-03)
            + '<div class="rb-field rb-quick"><label for="filter-quick">' + t('deckbuilder.filters.quick') + '</label>'
            + '<select id="filter-quick" data-field="quick" class="rb-quick-native">' + quick + '</select>'
            + '<button type="button" class="rb-quick-btn" aria-haspopup="true" aria-expanded="false"><span class="rb-quick-value"></span>'
            + '<span class="rb-chevron" aria-hidden="true">&#9662;</span></button></div>'
            + '<fieldset class="rb-mentions"><legend>' + t('deckbuilder.filters.mentions') + '</legend><div class="rb-more-grid">'
            + select('m_culture', t('deckbuilder.filters.culture'), culture_rows, null, 'rb-inline')
            + select('m_type', t('deckbuilder.filters.type'), options.types, null, 'rb-inline')
            + select('m_race', t('deckbuilder.filters.race'), list(options.races), null, 'rb-inline')
            + select('m_item', t('deckbuilder.filters.item_class'), list(options.item_classes), null, 'rb-inline')
            + select('m_keyword', t('deckbuilder.filters.keyword'), list(terms.USES_KEYWORDS), null, 'rb-inline')
            + '</div></fieldset>'
            + '<div class="rb-more-grid">'
            + '<label class="rb-field"><span>' + t('deckbuilder.filters.game_text') + '</span><input type="text" data-field="game_text"></label>'
            + '<label class="rb-field"><span>' + t('deckbuilder.filters.lore') + '</span><input type="text" data-field="lore"></label>'
            + select('rarity', t('deckbuilder.filters.rarity'), options.rarities)
            + '</div></div>'
            + '</div>';
    }

    /** Block and Set list only what the format allows; a chosen block narrows the sets */
    function fill_set_selects()
    {
        var block_select = filters_el.find('select[data-field=block]'),
            set_select = filters_el.find('select[data-field=set]'),
            any = '<option value="">' + t('deckbuilder.filters.any_in_format') + '</option>';
        block_select.html(any + _.map(options.blocks, function (b) { return '<option value="' + b.code + '">' + _.escape(b.name) + '</option>'; }).join(''));
        var sets = state.block ? _.where(options.sets, {block: state.block}) : options.sets;
        set_select.html(any + _.map(sets, function (p) { return '<option value="' + p.code + '">' + _.escape(p.code + ' · ' + p.name) + '</option>'; }).join(''));
        if(state.set && !_.findWhere(sets, {code: state.set})) {
            state.set = '';
        }
        block_select.val(state.block);
        set_select.val(state.set);
    }

    /** Puts the state back into the controls (after a chip is removed, or a + in the deck list) */
    function sync_controls()
    {
        if(!filters_el) {
            return;
        }
        var s = state;
        filters_el.find('#filter-text').val(s.text);
        fill_set_selects();
        filters_el.find('[data-side]').each(function () {
            var on = $(this).data('side') === s.side;
            $(this).toggleClass('active', on).attr('aria-pressed', on);
        });
        filters_el.find('select[data-field]').each(function () {
            var field = $(this).data('field');
            if(field !== 'block' && field !== 'set') {
                $(this).val(s[field] || '');
            }
        });
        quick_label();
        filters_el.find('input[data-field=game_text]').val(s.game_text);
        filters_el.find('input[data-field=lore]').val(s.lore);
        filters_el.find('input[data-field=can_bear]').prop('checked', !!s.can_bear);
        _.each(STATS, function (stat) {
            filters_el.find('[data-stat-op=' + stat + ']').val(s.stats[stat].op);
            filters_el.find('[data-stat=' + stat + ']').val(s.stats[stat].value);
        });
        filters_el.find('.rb-culture').each(function () {
            var code = $(this).data('culture'),
                on = !!s.cultures[code],
                fp = FP_CULTURES.indexOf(code) !== -1,
                dim = (s.side === 'free_peoples' && !fp && code !== 'gollum') || (s.side === 'shadow' && fp && code !== 'gollum') || s.side === 'sites';
            $(this).toggleClass('active', on).toggleClass('dim', dim && !on).attr('aria-pressed', on);
        });
    }

    /* ---- the pool's markup ---- */

    function plus_state(card)
    {
        var format = app.deck.get_format(),
            limit = format ? app.data.format_limit(format, card.code) : null,
            role = app.deck.role_for_card(card),
            qty = app.deck.get_quantity(card.code),
            max = app.deck.max_quantity(card),
            full = qty >= max,
            banned = limit === 'banned';
        if(role === 'site' && qty) {
            return {cls: 'is-off', disabled: true, title: t('deckbuilder.pool.in_deck_site')};
        }
        if(full) {
            return {cls: 'is-off', disabled: true, title: t('deckbuilder.pool.at_limit')};
        }
        if(banned) {
            return {cls: 'is-off', disabled: false, title: t('deckbuilder.pool.banned_add')};
        }
        if(qty) {
            return {cls: 'is-some', disabled: false, title: t('deckbuilder.pool.add_another', {count: qty})};
        }
        if(role === 'site' && card.site_number && format && format.numbered_sites) {
            var there = _.find(app.deck.get_cards('site'), function (s) { return !s.pending && s.card.site_number === card.site_number; });
            if(there) {
                return {cls: 'is-none', disabled: false, title: t('deckbuilder.pool.replaces', {card: there.card.label})};
            }
        }
        return {cls: 'is-none', disabled: false, title: t('deckbuilder.pool.add')};
    }

    function badges(card)
    {
        var format = app.deck.get_format(),
            limit = format ? app.data.format_limit(format, card.code) : null,
            html = '';
        if(limit === 'banned') {
            html += '<span class="rb-badge rb-badge-banned">' + t('deckbuilder.pool.banned') + '</span>';
        } else if(limit) {
            html += '<span class="rb-badge">' + t('deckbuilder.pool.max', {count: {restricted: 1, limit2: 2, limit3: 3}[limit]}) + '</span>';
        }
        if(app.deck.get_ring_bearer_code() === card.code) {
            html += '<span class="rb-badge rb-badge-rb">' + t('deck.ring_bearer') + '</span>';
        }
        if(card.type_code === 'site' && card.site_number) {
            html += '<span class="rb-badge rb-badge-site">' + t('deckbuilder.pool.site', {number: card.site_number}) + '</span>';
        }
        return html;
    }

    function buttons(card)
    {
        var p = plus_state(card),
            html = '';
        if(card.type_code === 'companion' && card.can_bear_ring) {
            var is_rb = app.deck.get_ring_bearer_code() === card.code;
            html += '<button type="button" class="rb-pool-rb' + (is_rb ? ' active' : '') + '" data-rb="' + card.code + '" aria-pressed="' + is_rb + '" title="'
                + t('deckbuilder.pool.make_rb') + '" aria-label="' + t('deckbuilder.pool.make_rb_card', {card: _.escape(card.label)}) + '">&#9711;</button>';
        }
        return html + '<button type="button" class="rb-pool-add ' + p.cls + '" data-add="' + card.code + '"' + (p.disabled ? ' disabled' : '')
            + ' title="' + _.escape(p.title) + '" aria-label="' + t('deckbuilder.pool.add_card', {card: _.escape(card.label)}) + '">+</button>';
    }

    function pool_html(cards, size)
    {
        var v = view(),
            total = cards.length,
            pages = Math.max(1, Math.ceil(total / size));
        page = Math.min(Math.max(1, page), pages);
        var from = (page - 1) * size,
            shown = cards.slice(from, from + size),
            format = app.deck.get_format(),
            banned = function (card) { return format && app.data.format_limit(format, card.code) === 'banned'; },
            body;
        if(!shown.length) {
            body = '<div class="rb-pool-empty">' + t('deckbuilder.pool.none') + ' <button type="button" class="btn btn-link rb-clear-all">' + t('deckbuilder.filters.clear_all') + '</button></div>';
        } else if(v === 'list') {
            body = '<div class="rb-pool-list">' + _.map(shown, function (card) {
                return '<div class="rb-pool-row' + (banned(card) ? ' is-banned' : '') + '" data-hover-code="' + card.code + '">'
                    + '<span class="rb-pool-buttons">' + buttons(card) + '</span>'
                    + app.data.cultureIconHtml(card.culture_code, card.culture_name)
                    + '<a class="rb-name card" href="' + card.url + '" data-code="' + card.code + '">' + (card.is_unique ? '&bull;' : '') + _.escape(card.label) + '</a>'
                    + badges(card) + '</div>';
            }).join('') + '</div>';
        } else {
            body = '<div class="rb-pool-grid' + (v === 'half' ? ' is-half' : '') + '">' + _.map(shown, function (card) {
                return '<div class="rb-pool-tile' + (banned(card) ? ' is-banned' : '') + (card.type_code === 'site' ? ' is-site' : '') + '">'
                    + '<a class="rb-pool-img card" href="' + card.url + '" data-code="' + card.code + '" data-hover-code="' + card.code + '" data-hover-grow="1">'
                    + (card.image_url ? '<img src="' + card.image_url + '" alt="' + _.escape(card.label) + '" loading="lazy">' : '<span class="rb-tile-noimg">' + _.escape(card.label) + '</span>')
                    + '</a><span class="rb-pool-badges">' + badges(card) + '</span><span class="rb-pool-buttons">' + buttons(card) + '</span></div>';
            }).join('') + '</div>';
        }
        var range = total ? t('deckbuilder.pool.range', {from: from + 1, to: from + shown.length, total: total}) : t('deckbuilder.pool.range_none'),
            pager = function (where) {
                return '<div class="rb-pager rb-pager-' + where + '"><button type="button" class="rb-page" data-page="prev" aria-label="' + t('deckbuilder.pool.prev') + '"' + (page <= 1 ? ' disabled' : '') + '>&lsaquo;</button>'
                    + '<span>' + (where === 'top' ? range : t('deckbuilder.pool.page', {page: page, pages: pages})) + '</span>'
                    + '<button type="button" class="rb-page" data-page="next" aria-label="' + t('deckbuilder.pool.next') + '"' + (page >= pages ? ' disabled' : '') + '>&rsaquo;</button></div>';
            },
            icons = app.deck_editor.ICONS,
            vbtn = function (code) {
                var label = t('deckbuilder.view.' + code);
                return '<button type="button" class="rb-opt" data-pool-view="' + code + '" aria-pressed="' + (v === code) + '"' + (v === code ? ' data-active="1"' : '') + ' aria-label="' + label + '" title="' + label + '">' + icons[code] + '</button>';
            };
        return '<div class="rb-pool-head"><label class="rb-field rb-inline"><span>' + t('deckbuilder.pool.sort') + '</span><select class="rb-sort">'
            + _.map(['name', 'culture', 'type', 'twilight', 'strength', 'set', 'site'], function (k) {
                return '<option value="' + k + '"' + (sort === k ? ' selected' : '') + '>' + t('deckbuilder.sort.' + k) + '</option>';
            }).join('') + '</select></label>'
            + '<div class="rb-pool-views" role="group" aria-label="' + t('deckbuilder.pool.view') + '">' + vbtn('list') + vbtn('thumb') + vbtn('half') + '</div>'
            + pager('top') + '</div>'
            + '<div class="rb-pool-body">' + body + '</div>' + (total > size ? pager('bottom') : '');
    }

    /** Cards per page: whatever fills the space the pool has */
    function page_size()
    {
        var body = pool_el.find('.rb-pool-body'),
            width = body.width() || pool_el.width() || 560,
            // on a desktop layout the pool has a fixed height (it scrolls on its own); on a phone it doesn't
            constrained = window.matchMedia('(min-width: 992px)').matches,
            height = constrained ? Math.max(0, pool_el.innerHeight() - pool_el.find('.rb-pool-head').outerHeight(true) - 16) : 0,
            v = view();
        if(v === 'list') {
            var cols = width >= 520 ? 2 : 1;
            return cols * (height ? Math.max(6, Math.floor(height / ROW_H)) : 15);
        }
        var columns = Math.max(2, Math.floor((width + GAP) / (TILE_MIN + GAP))),
            tile_w = (width - GAP * (columns - 1)) / columns,
            tile_h = tile_w * (v === 'half' ? 0.873 : 497 / 357),
            rows = height ? Math.max(1, Math.floor((height + GAP) / (tile_h + GAP))) : 4;
        return columns * rows;
    }

    /**
     * Cards per page from the grid as drawn: its real column count, and as many
     * rows of its real tile height as fit the pool's height. Desktop only (the pool
     * has a fixed height there); null when there's nothing to measure.
     */
    function fitted_size()
    {
        if(!window.matchMedia('(min-width: 992px)').matches) {
            return null;
        }
        var grid = pool_el.find('.rb-pool-grid, .rb-pool-list').first(),
            first = grid.children().first();
        if(!grid.length || !first.length) {
            return null;
        }
        grid.css('grid-template-columns', '');
        var style = window.getComputedStyle(grid[0]),
            columns = style.gridTemplateColumns.split(' ').filter(function (c) { return c && c !== 'none'; }).length || 1,
            row_gap = parseFloat(style.rowGap) || 0,
            col_gap = parseFloat(style.columnGap) || 0,
            item_w = first.outerWidth(),
            item_h = first.outerHeight(),
            space = pool_el.innerHeight() - pool_el.find('.rb-pool-head').outerHeight(true)
                - (parseFloat(pool_el.css('padding-top')) || 0) - (parseFloat(pool_el.css('padding-bottom')) || 0) - 4;
        if(!item_h || space <= 0) {
            return null;
        }
        var exact = (space + row_gap) / (item_h + row_gap),
            rows = Math.max(1, Math.floor(exact)),
            shrink = null;
        // most of another row left over: take it, and shrink the pictures a hair to fit (Ketura, 2026-11-03)
        if(grid.hasClass('rb-pool-grid') && exact - rows >= 0.75) {
            rows += 1;
            var tile_h = (space - row_gap * (rows - 1)) / rows;
            shrink = {width: Math.floor(item_w * tile_h / item_h), columns: columns, gap: col_gap};
        }
        return {size: columns * rows, shrink: shrink};
    }

    /** The list view reads down the columns, not across the rows (Ketura, 2026-11-03) */
    function list_in_columns()
    {
        var list = pool_el.find('.rb-pool-list');
        if(!list.length) {
            return;
        }
        list.css({'grid-auto-flow': '', 'grid-template-rows': ''});
        var columns = window.getComputedStyle(list[0]).gridTemplateColumns.split(' ').filter(function (c) { return c && c !== 'none'; }).length || 1,
            rows = Math.ceil(list.children().length / columns);
        if(columns > 1 && rows > 0) {
            list.css({'grid-auto-flow': 'column', 'grid-template-rows': 'repeat(' + rows + ', auto)'});
        }
    }

    /** Narrower tile columns, centred, when a page takes the extra row */
    function apply_fit(fitted)
    {
        list_in_columns();
        var grid = pool_el.find('.rb-pool-grid');
        if(grid.length && fitted && fitted.shrink) {
            grid.css({'grid-template-columns': 'repeat(' + fitted.shrink.columns + ', ' + fitted.shrink.width + 'px)', 'justify-content': 'center'});
        }
    }

    /* ---- refresh ---- */

    var refresh = pool.refresh = function refresh(reset_page)
    {
        if(!pool_el || !app.data.cards) {
            return;
        }
        if(reset_page) {
            page = 1;
        }
        var cards = pool.filtered();
        cards.sort(SORTS[sort] || SORTS.name);
        pool.last_result = cards;
        // the Showing band first: it sits above the pool, and its height decides the pool's
        var format = app.deck.get_format(),
            chips = active_chips(),
            more = more_count();
        filters_el.find('.rb-showing').html(
            // More filters sits in the band, so the filters take one row less above the pool
            '<button type="button" class="rb-more-toggle' + (more_open ? ' active' : '') + '" aria-expanded="' + more_open + '">' + t('deckbuilder.filters.more')
            + (more ? ' <span class="rb-more-count">' + more + '</span>' : '') + ' <span class="rb-chevron" aria-hidden="true">' + (more_open ? '&#9652;' : '&#9662;') + '</span></button>'
            + '<span class="rb-showing-label">' + t('deckbuilder.filters.showing') + '</span>'
            + '<span class="rb-chip rb-chip-fixed" title="' + t('deckbuilder.filters.format_hint') + '">'
            + _.escape(format ? t('deckbuilder.filters.format_sets', {format: format.name}) : t('deckbuilder.filters.all_sets')) + '</span>'
            + chips.join('')
            + (chips.length ? '<button type="button" class="rb-clear-all">' + t('deckbuilder.filters.clear_all') + '</button>' : '')
            + '<span class="rb-showing-count">' + Translator.transChoice('deckbuilder.filters.count', cards.length, {count: cards.length}) + '</span>');
        // render once to measure, then with the real page size: from the grid the browser
        // actually drew (whole rows that fill the pool; Ketura, 2026-11-03), else an estimate
        pool_el.html(pool_html(cards, last_page_size));
        var fitted = fitted_size(),
            size = fitted ? fitted.size : page_size();
        if(size !== last_page_size) {
            last_page_size = size;
            pool_el.html(pool_html(cards, size));
        }
        apply_fit(fitted);
    };

    /** The deck list's + buttons: clear the filters and show what fits the slot */
    pool.find = function find(what)
    {
        state = defaults();
        state.slot = what.slot;
        state.site = what.site ? parseInt(what.site, 10) : null;
        if(what.slot === 'sites' || what.slot === 'site') {
            state.side = 'sites';
            sort = 'site';
        } else if(what.slot === 'ring' || what.slot === 'map') {
            state.side = 'sites';
        } else if(what.slot === 'ring_bearer') {
            state.side = 'free_peoples';
            state.type = 'companion';
        }
        sync_controls();
        refresh(true);
    };

    /** A name search from elsewhere on the page (the Archive info tab's unmatched lines) */
    pool.search = function search(text)
    {
        state = defaults();
        state.text = text;
        sync_controls();
        filters_el.find('#filter-text').val(text);
        refresh(true);
    };

    /* ---- Quick Search's desktop panel ---- */

    function quick_label()
    {
        var chosen = state.quick ? terms.quick(state.quick) : null;
        filters_el.find('.rb-quick-value').text(chosen ? chosen.label : t('deckbuilder.filters.any'));
    }

    function quick_panel_html()
    {
        var item = function (value, label) {
            var on = (state.quick || '') === value;
            return '<button type="button" class="rb-quick-item' + (on ? ' active' : '') + '" data-quick="' + _.escape(value) + '" aria-pressed="' + on + '">' + _.escape(label) + '</button>';
        };
        return '<div class="rb-quick-any">' + item('', t('deckbuilder.filters.any')) + '</div>'
            + '<div class="rb-quick-groups">' + _.map(terms.QUICK, function (group) {
                return '<section class="rb-quick-group"><h4>' + _.escape(group.group) + '</h4>'
                    + _.map(group.items, function (i) { return item(i[0], i[1]); }).join('') + '</section>';
            }).join('') + '</div>';
    }

    /** The panel sits outside the scrolling filters, so nothing scrolls it away */
    var quick_panel_el = null;
    function quick_panel()
    {
        if(!quick_panel_el) {
            quick_panel_el = $('<div class="rb-quick-panel" role="dialog" aria-label="' + t('deckbuilder.filters.quick') + '" hidden></div>')
                .appendTo(filters_el.closest('.rb-builder').length ? filters_el.closest('.rb-builder') : 'body');
            quick_panel_el.on('click', '[data-quick]', function () {
                filters_el.find('select[data-field=quick]').val($(this).data('quick') + '').trigger('change');
                quick_label();
                close_quick();
                filters_el.find('.rb-quick-btn').focus();
            });
        }
        return quick_panel_el;
    }

    function close_quick()
    {
        if(quick_panel_el) {
            quick_panel_el.prop('hidden', true);
        }
        filters_el.find('.rb-quick-btn').attr('aria-expanded', 'false');
    }

    function open_quick()
    {
        quick_panel().html(quick_panel_html()).prop('hidden', false);
        place_quick();
        filters_el.find('.rb-quick-btn').attr('aria-expanded', 'true');
        var panel = quick_panel(),
            first = panel.find('.rb-quick-item.active')[0] || panel.find('.rb-quick-item')[0];
        if(first) {
            first.focus({preventScroll: true});
        }
    }

    function place_quick()
    {
        var button = filters_el.find('.rb-quick-btn'),
            panel = quick_panel(),
            rect = button[0].getBoundingClientRect(),
            width = Math.min(900, window.innerWidth - 16),
            left = Math.max(8, Math.min(rect.left, window.innerWidth - width - 8)),
            top = rect.bottom + 4,
            room = window.innerHeight - top - 8;
        // against the window, so the scrolling filters can't cut it off
        panel.css({position: 'fixed', left: left + 'px', top: top + 'px', width: width + 'px', 'max-height': Math.max(220, room) + 'px'});
        if(room < 220) {
            panel.css({top: Math.max(8, rect.top - 4 - panel.outerHeight()) + 'px', 'max-height': (rect.top - 12) + 'px'});
        }
    }

    /* ---- events ---- */

    function on_filter_change()
    {
        refresh(true);
    }

    pool.setup = function setup(filters_selector, pool_selector)
    {
        filters_el = $(filters_selector);
        pool_el = $(pool_selector);
    };

    pool.on_data_loaded = function on_data_loaded()
    {
        base_cards();
        filters_el.html(filters_html());
        app.smart_filter.setup_help(filters_el.find('.smart-filter-help'));
        sync_controls();

        filters_el.on('input', '#filter-text', _.debounce(function () {
            state.text = $(this).val();
            on_filter_change();
        }, 200));
        filters_el.on('keydown', '#filter-text', function (event) {
            // Enter adds the only card left (for players who know the cards)
            if(event.key === 'Enter') {
                event.preventDefault();
                state.text = $(this).val();
                refresh(true);
                if(pool.last_result && pool.last_result.length === 1) {
                    app.deck.add_one(pool.last_result[0].code);
                }
            }
        });
        filters_el.on('click', '.rb-quick-btn', function (event) {
            event.stopPropagation();
            quick_panel().prop('hidden') ? open_quick() : close_quick();
        });
        $(document).on('click', function (event) {
            if(!$(event.target).closest('.rb-quick-panel, .rb-quick-btn').length) {
                close_quick();
            }
        }).on('keydown', function (event) {
            if(event.key === 'Escape' && quick_panel_el && !quick_panel_el.prop('hidden')) {
                close_quick();
                filters_el.find('.rb-quick-btn').focus();
            }
        });
        // a pane scrolling under the open panel: it follows its button, or closes once the button is out of sight
        document.addEventListener('scroll', function (event) {
            if(!quick_panel_el || quick_panel_el.prop('hidden') || $(event.target).closest('.rb-quick-panel').length) {
                return;
            }
            var button = filters_el.find('.rb-quick-btn')[0],
                scroller = filters_el.find('.rb-filters-scroll')[0],
                rect = button.getBoundingClientRect(),
                area = scroller ? scroller.getBoundingClientRect() : {top: 0, bottom: window.innerHeight};
            if(rect.bottom < area.top || rect.top > area.bottom) {
                close_quick();
            } else {
                place_quick();
            }
        }, {passive: true, capture: true});
        $(window).on('resize', close_quick);
        filters_el.on('change', 'select[data-field]', function () {
            var field = $(this).data('field');
            state[field] = $(this).val();
            if(field === 'block') {
                fill_set_selects();
            }
            on_filter_change();
        });
        filters_el.on('input', 'input[data-field=game_text], input[data-field=lore]', _.debounce(function () {
            state[$(this).data('field')] = $(this).val().trim();
            on_filter_change();
        }, 250));
        filters_el.on('change', 'input[data-field=can_bear]', function () {
            state.can_bear = this.checked;
            on_filter_change();
        });
        filters_el.on('change', '[data-stat-op]', function () {
            state.stats[$(this).data('stat-op')].op = $(this).val();
            on_filter_change();
        });
        filters_el.on('input', '[data-stat]', _.debounce(function () {
            state.stats[$(this).data('stat')].value = $(this).val().trim();
            on_filter_change();
        }, 250));
        filters_el.on('click', '[data-side]', function () {
            var side = $(this).data('side') + '';
            state.side = state.side === side ? '' : side;
            sync_controls();
            on_filter_change();
        });
        filters_el.on('click', '.rb-culture', function () {
            var code = $(this).data('culture');
            state.cultures[code] = !state.cultures[code];
            sync_controls();
            on_filter_change();
        });
        filters_el.on('click', '.rb-more-toggle', function () {
            // opens in place: the filters' scroll stays where it was, so nothing above moves (Ketura, 2026-11-03)
            var scroller = filters_el.find('.rb-filters-scroll'),
                top = scroller.scrollTop();
            more_open = !more_open;
            filters_el.find('.rb-more').prop('hidden', !more_open);
            refresh(false);
            scroller.scrollTop(top);
        });
        filters_el.on('click', '.rb-chip[data-remove]', function () {
            remove_filter($(this).data('remove') + '');
        });
        $(document).on('click', '.rb-clear-all', function () {
            state = defaults();
            sort = 'name';
            sync_controls();
            refresh(true);
        });

        pool_el.on('change', '.rb-sort', function () {
            sort = $(this).val();
            refresh(true);
        });
        pool_el.on('click', '[data-pool-view]', function () {
            app.config.set('pool-view', $(this).data('pool-view'));
            last_page_size = 24;
            refresh(true);
        });
        pool_el.on('click', '.rb-page', function () {
            page += $(this).data('page') === 'next' ? 1 : -1;
            refresh(false);
            pool_el.scrollTop(0);
        });
        pool_el.on('click', '[data-add]', function (event) {
            event.preventDefault();
            var code = $(this).data('add') + '';
            app.deck.add_one(code);
            // a + from the deck list fills that slot and goes back to the whole pool
            if(state.slot === 'site' || state.slot === 'ring' || state.slot === 'map') {
                state.slot = null;
                state.site = null;
                state.side = '';
                sync_controls();
                refresh(false);
            }
        });
        // a click anywhere on a card (or its row in the list) is its + (Ketura, 2026-11-03);
        // the + and the Ring-bearer button keep their own clicks, and a modified click
        // (ctrl, shift, cmd) still opens the card's page
        pool_el.on('click', '.rb-pool-tile, .rb-pool-row', function (event) {
            if($(event.target).closest('button').length || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) {
                return;
            }
            event.preventDefault();
            event.stopPropagation();
            // a companion who can bear the Ring fills an empty Ring-bearer slot first (Ketura, 2026-11-03)
            var rb = $(this).find('[data-rb]');
            if(rb.length && !app.deck.get_ring_bearer_code()) {
                rb.trigger('click');
                return;
            }
            $(this).find('[data-add]').trigger('click');
        });
        pool_el.on('click', '[data-rb]', function (event) {
            event.preventDefault();
            app.deck.set_ring_bearer($(this).data('rb') + '');
            if(state.slot === 'ring_bearer') {
                state = defaults();
                sync_controls();
                refresh(true);
            }
        });

        $(document).on('deck.changed', function () {
            if(base_cache.key !== (app.deck.get_format() ? app.deck.get_format().code + '@' + app.deck.get_format().date_update : '-')) {
                base_cards();
                fill_set_selects();
                filters_el.find('select[data-field=type]').html('<option value="">' + t('deckbuilder.filters.any') + '</option>'
                    + _.map(options.types, function (r) { return '<option value="' + r.code + '">' + _.escape(r.name) + '</option>'; }).join('')).val(state.type);
                refresh(true);
            } else {
                refresh(false);
            }
        });
        $(document).on('deckbuilder.find', function (event, what) {
            pool.find(what);
        });
        $(window).on('resize', _.debounce(function () { refresh(false); }, 150));

        // "/" jumps to the search box
        $(document).on('keydown', function (event) {
            if(event.key === '/' && !$(event.target).is('input, textarea, select, [contenteditable]')) {
                event.preventDefault();
                $('a[href="#tab-pane-build"]').tab('show');
                filters_el.find('#filter-text').focus().select();
            }
        });
    };

    pool.reset = function reset()
    {
        state = defaults();
        sort = 'name';
        sync_controls();
        refresh(true);
    };

})(app.pool = {}, jQuery);
