/* global app, _, Translator, Routing */
/**
 * The deck being viewed or edited, in the browser (phase3/03).
 *
 * Content is a map of key => quantity: the key is the card's blueprint ID,
 * except the Ring-bearer's, which is "rb:" + its blueprint ID (the same companion
 * may also be in the draw deck). Every other card's part of the deck follows from
 * its type, as on the server (App\Classes\DeckContent). A card any version will do for
 * (Ketura, 2026-11-08) is "any:" + the version it was made from, after any "rb:".
 */
(function app_deck(deck, $)
{
    var RB = 'rb:',
        ANY = 'any:',
        data = {},
        content = {},
        // keys X'd in the deckbuilder: still shown (a Trash button deletes them), but not part of the deck
        pending = {},
        validation = null,
        validation_available = true,
        validate_timer = null,
        validate_request = null;

    deck.ROLES = ['ring_bearer', 'ring', 'site', 'draw', 'map'];

    /** @return {{code: String, rb: Boolean, any: Boolean}} */
    deck.parse_key = function parse_key(key)
    {
        key = key + '';
        var rb = key.indexOf(RB) === 0;
        if(rb) {
            key = key.substr(RB.length);
        }
        var any = key.indexOf(ANY) === 0;
        return {code: any ? key.substr(ANY.length) : key, rb: rb, any: any};
    };

    deck.make_key = function make_key(code, rb, any)
    {
        return (rb ? RB : '') + (any ? ANY : '') + code;
    };

    /** The deck holds the card in some form: as itself, the Ring-bearer, or any version made from it */
    deck.holds = function holds(code)
    {
        return !!(content[code] || content[RB + code] || content[ANY + code] || content[RB + ANY + code]);
    };

    /**
     * @param {Object} json the deck or decklist as its jsonSerialize() gives it
     * @memberOf deck
     */
    deck.init = function init(json)
    {
        data = json || {};
        content = _.extend({}, data.slots || {});
        pending = {};
        validation = data.validation || null;
        validation_available = !!data.validated_at || !data.id;
    };

    deck.get_id = function () { return data.id; };
    deck.get_uuid = function () { return data.uuid; };
    deck.get_version = function () { return data.version; };
    deck.is_shared = function () { return !!data.shared; };
    deck.get_name = function () { return data.name; };
    deck.set_name = function (name) { data.name = name; };
    deck.get_description_md = function () { return data.description_md; };
    deck.set_description_md = function (md) { data.description_md = md; };
    deck.get_tags = function () { return data.tags; };
    deck.set_tags = function (tags) { data.tags = tags; };
    deck.get_historic = function () { return data.historic || null; };
    deck.set_historic = function (historic) { data.historic = historic; };
    deck.get_effective_date = function () { return data.effective_date; };

    /* ---- notes (Ketura, 2026-11-03): the bid, starting notes, card annotations ---- */
    /* its own thumbnail (Ketura, 2026-11-09): a card's code, '' for the cultures; a framing for it */
    deck.get_thumbnail = function () { return data.thumbnail || ''; };
    deck.get_thumbnail_crop = function () { return data.thumbnail_crop || null; };
    deck.set_thumbnail = function (code, crop) { data.thumbnail = code || ''; data.thumbnail_crop = code && crop && crop.code === code ? crop : null; };
    deck.get_starting_bid = function () { return data.starting_bid || ''; };
    deck.set_starting_bid = function (bid) { data.starting_bid = bid; };
    deck.get_starting_notes = function () { return data.starting_notes || ''; };
    deck.set_starting_notes = function (md) { data.starting_notes = md; };
    deck.get_annotations = function () { return _.map(data.annotations || [], _.clone); };
    deck.set_annotations = function (rows) { data.annotations = _.map(rows || [], _.clone); };

    /* ---- the starting fellowship (Ketura, 2026-11-03): optional marks on companions ---- */
    deck.get_starting = function () { return (data.starting || []).slice(); };
    deck.is_starting = function (code) { return _.contains(data.starting || [], code); };
    deck.toggle_starting = function toggle_starting(code)
    {
        data.starting = deck.is_starting(code) ? _.without(data.starting, code) : (data.starting || []).concat([code]);
        deck.changed();
    };
    deck.get_format_code = function () { return data.target_format; };
    deck.set_format_code = function (code) { data.target_format = code; };
    deck.get_format = function () { return app.data.findFormat(data.target_format); };

    /** Everything the deckbuilder edits, for the browser draft (Ketura, 2026-11-02) */
    deck.get_state = function get_state()
    {
        return {
            name: data.name,
            target_format: data.target_format,
            description_md: data.description_md,
            tags: data.tags,
            historic: data.historic || null,
            starting: (data.starting || []).slice().sort(),
            starting_bid: data.starting_bid || '',
            starting_notes: data.starting_notes || '',
            annotations: _.map(data.annotations || [], _.clone),
            thumbnail: data.thumbnail || '',
            thumbnail_crop: data.thumbnail_crop || null,
            content: _.extend({}, content),
            pending: _.keys(pending)
        };
    };

    deck.set_state = function set_state(state)
    {
        data.name = state.name;
        data.target_format = state.target_format;
        data.description_md = state.description_md;
        data.tags = state.tags;
        data.historic = state.historic || null;
        data.starting = (state.starting || []).slice();
        data.starting_bid = state.starting_bid || '';
        data.starting_notes = state.starting_notes || '';
        data.annotations = _.map(state.annotations || [], _.clone);
        data.thumbnail = state.thumbnail || '';
        data.thumbnail_crop = state.thumbnail_crop || null;
        content = _.extend({}, state.content || {});
        pending = {};
        _.each(state.pending || [], function (key) {
            if(content[key]) {
                pending[key] = true;
            }
        });
    };

    /* ---- X then Trash (Ketura, 2026-11-02): an X'd card stays listed but leaves the deck ---- */

    deck.is_pending = function is_pending(key)
    {
        return !!pending[key];
    };

    deck.set_pending = function set_pending(key, on)
    {
        if(on && content[key]) {
            pending[key] = true;
        } else {
            delete pending[key];
        }
        deck.changed();
    };

    /** Trash: deletes the line for good */
    deck.remove = function remove(key)
    {
        delete pending[key];
        delete content[key];
        deck.changed();
    };

    /** Cards the deck actually holds: what is saved and validated */
    function live_content()
    {
        return _.omit(content, _.keys(pending));
    }

    deck.role_for_card = function role_for_card(card)
    {
        switch(card.type_code) {
            case 'the_one_ring': return 'ring';
            case 'site': return 'site';
            case 'map': return 'map';
            default: return 'draw';
        }
    };

    /** @return {Object} a copy of the content map, without X'd cards */
    deck.get_content = function get_content()
    {
        return live_content();
    };

    deck.get_json = function get_json()
    {
        return JSON.stringify(live_content());
    };

    deck.set_content = function set_content(new_content)
    {
        content = {};
        pending = {};
        _.each(new_content || {}, function (qty, key) {
            qty = parseInt(qty, 10);
            if(qty > 0) {
                content[key] = qty;
            }
        });
        deck.changed();
    };

    /** Copies of a card outside the Ring-bearer slot (0 while it is X'd) */
    deck.get_quantity = function get_quantity(code)
    {
        return pending[code] ? 0 : (content[code] || 0);
    };

    /** Copies as listed, X'd or not */
    deck.get_listed_quantity = function get_listed_quantity(code)
    {
        return content[code] || 0;
    };

    /**
     * The most copies the format allows: 1 for the Ring, sites and maps, else the
     * format's limit lists (R-listed 1, limit2, limit3) under its maximum.
     */
    deck.max_quantity = function max_quantity(card)
    {
        if(deck.role_for_card(card) !== 'draw') {
            return 1;
        }
        var format = deck.get_format(),
            max = format ? format.maximum_same_name || 4 : 4,
            limit = format ? app.data.format_limit(format, card.code) : null;
        if(limit === 'restricted') { return 1; }
        if(limit === 'limit2') { return Math.min(max, 2); }
        if(limit === 'limit3') { return Math.min(max, 3); }
        return max;
    };

    /**
     * Sets a card's copies. The One Ring and a map replace any other Ring or map.
     */
    deck.set_quantity = function set_quantity(code, qty)
    {
        // a content key works too: "any:1_50" (Ketura, 2026-11-08)
        var card = app.data.cards.findById(deck.parse_key(code).code);
        if(!card) {
            return;
        }
        var role = deck.role_for_card(card);
        if((role === 'ring' || role === 'map') && qty > 0) {
            _.each(deck.get_cards(role), function (other) {
                if(other.key !== code) {
                    delete content[other.key];
                }
            });
            qty = 1;
        }
        delete pending[code];
        if(qty > 0) {
            content[code] = qty;
        } else {
            delete content[code];
        }
        deck.changed();
    };

    /** Adds one copy, up to the format's limit; replaces the Ring, a map, or the site with the same number */
    deck.add_one = function add_one(code)
    {
        var card = app.data.cards.findById(code);
        if(!card) {
            return;
        }
        var role = deck.role_for_card(card),
            format = deck.get_format();
        if(role === 'site' && card.site_number && format && format.numbered_sites) {
            _.each(deck.get_cards('site'), function (other) {
                if(other.card.site_number === card.site_number && other.card.code !== code) {
                    delete content[other.key];
                    delete pending[other.key];
                }
            });
        }
        if(role !== 'draw') {
            deck.set_quantity(code, 1);
            return;
        }
        deck.set_quantity(code, Math.min(deck.max_quantity(card), deck.get_quantity(code) + 1));
    };

    deck.get_ring_bearer_code = function get_ring_bearer_code()
    {
        var key = _.find(_.keys(content), function (k) { return k.indexOf(RB) === 0; });
        return key ? deck.parse_key(key).code : null;
    };

    /**
     * Any version of a card, or that card again (Ketura, 2026-11-08): the line keeps its
     * copies, joining any line already standing for the same
     */
    deck.set_generic = function set_generic(key, on)
    {
        var parsed = deck.parse_key(key),
            qty = content[key];
        if(!qty || parsed.any === !!on) {
            return;
        }
        var card = app.data.cards.findById(parsed.code),
            next = deck.make_key(parsed.code, parsed.rb, on);
        if(on && card) {
            // one line per version group: another "any" line of the same group takes these copies
            var group = app.card_versions.group(card),
                same = _.find(_.keys(content), function (k) {
                    var p = deck.parse_key(k), other = p.any && p.rb === parsed.rb && app.data.cards.findById(p.code);
                    return other && app.card_versions.group(other) === group;
                });
            if(same) {
                next = same;
            }
        }
        delete content[key];
        delete pending[key];
        var single = parsed.rb || (card && deck.role_for_card(card) !== 'draw');
        content[next] = single ? 1 : Math.min(99, (content[next] || 0) + qty);
        deck.changed();
    };

    /** @param {String|null} code a companion's blueprint ID, or null to clear */
    deck.set_ring_bearer = function set_ring_bearer(code)
    {
        _.each(_.keys(content), function (k) {
            if(k.indexOf(RB) === 0) {
                delete content[k];
                delete pending[k];
            }
        });
        if(code) {
            content[RB + code] = 1;
        }
        deck.changed();
    };

    /**
     * @param {String} role ring_bearer, ring, site, draw or map
     * @return {Array} [{key, card, qty}] sorted for display
     */
    deck.get_cards = function get_cards(role)
    {
        var list = [];
        _.each(content, function (qty, key) {
            var parsed = deck.parse_key(key),
                card = app.data.cards.findById(parsed.code);
            if(!card) {
                return;
            }
            var card_role = parsed.rb ? 'ring_bearer' : deck.role_for_card(card);
            if(card_role === role) {
                // any version: labelled "Legolas (any)", and standing for one card where one is needed
                list.push({key: key, card: card, qty: qty, pending: !!pending[key], generic: parsed.any,
                    label: parsed.any ? app.card_versions.label(card) : card.label,
                    stand_in: parsed.any ? app.card_versions.stand_in(card, deck.get_format()) : card});
            }
        });
        return list.sort(function (a, b) {
            if(role === 'site') {
                return (a.card.site_number || 99) - (b.card.site_number || 99) || a.card.label.localeCompare(b.card.label);
            }
            return (a.card.culture_name || '').localeCompare(b.card.culture_name || '')
                || (a.card.type_name || '').localeCompare(b.card.type_name || '')
                || (a.card.twilight || 0) - (b.card.twilight || 0)
                || a.card.label.localeCompare(b.card.label);
        });
    };

    /** The draw deck as cards with an "indeck" count (for the charts and the draw simulator) */
    deck.get_draw_deck = function get_draw_deck()
    {
        return _.map(_.reject(deck.get_cards('draw'), 'pending'), function (slot) {
            return _.extend({}, slot.stand_in, {indeck: slot.qty});
        });
    };

    function count(slots, side)
    {
        return _.reduce(slots, function (sum, slot) {
            return sum + (!slot.pending && (!side || slot.card.side === side) ? slot.qty : 0);
        }, 0);
    }

    deck.get_draw_deck_size = function () { return count(deck.get_cards('draw')); };
    deck.get_free_peoples_count = function () { return count(deck.get_cards('draw'), 'free_peoples'); };
    deck.get_shadow_count = function () { return count(deck.get_cards('draw'), 'shadow'); };

    /** Cultures of the draw deck: [{code, name, count}] largest first */
    deck.get_cultures = function get_cultures()
    {
        var cultures = {};
        _.each(_.reject(deck.get_cards('draw'), 'pending'), function (slot) {
            var code = slot.card.culture_code;
            if(code) {
                cultures[code] = cultures[code] || {code: code, name: slot.card.culture_name, count: 0};
                cultures[code].count += slot.qty;
            }
        });
        return _.sortBy(_.values(cultures), function (c) { return -c.count; });
    };

    /** Called after every change: redraws, and asks Gemp again shortly after */
    deck.changed = function changed()
    {
        $(document).trigger('deck.changed');
        deck.schedule_validation();
    };

    /* ---- validation (decision 0019) ---- */

    deck.get_validation = function () { return validation; };

    deck.schedule_validation = function schedule_validation()
    {
        if(!data.id || !$('#deck-validation').length) {
            return;
        }
        clearTimeout(validate_timer);
        if(!data.target_format) {
            // no format chosen yet: nothing for Gemp to check against
            validation = null;
            deck.display_validation('#deck-validation');
            return;
        }
        validate_timer = setTimeout(deck.validate, 900);
        $('#deck-validation').addClass('deck-validation-pending');
    };

    deck.validate = function validate()
    {
        if(validate_request) {
            validate_request.abort();
        }
        if(!data.target_format) {
            validation = null;
            deck.display_validation('#deck-validation');
            return;
        }
        validate_request = $.ajax(Routing.generate('deck_validate'), {
            type: 'POST',
            data: {content: deck.get_json(), target_format: data.target_format},
            dataType: 'json',
            success: function (response) {
                validation_available = response.available;
                validation = response.validation;
                deck.display_validation('#deck-validation');
            },
            complete: function () {
                validate_request = null;
                $('#deck-validation').removeClass('deck-validation-pending');
            }
        });
    };

    deck.is_valid = function is_valid()
    {
        return !!validation && (validation.valid || validation.valid_with_errata === true);
    };

    /** The problems that matter: with errata applied when the format has errata */
    deck.get_problems = function get_problems()
    {
        if(!validation || deck.is_valid()) {
            return [];
        }
        return validation.problems_with_errata || validation.problems || [];
    };

    function plain(name)
    {
        return (name || '').replace(/[••]/g, '').trim().toLowerCase();
    }

    /**
     * Cards Gemp's problems name. Gemp writes a card's full name after the last ": "
     * ("Card is X-listed: •Aragorn, Ranger of the North"); a card matches by its full
     * label or, for the copies check (which names by title), by its title.
     * @return {Object} card code => true
     */
    deck.get_problem_codes = function get_problem_codes()
    {
        var names = {};
        _.each(deck.get_problems(), function (problem) {
            var at = problem.lastIndexOf(': ');
            if(at !== -1) {
                names[plain(problem.substr(at + 2).replace(/\.$/, ''))] = true;
            }
        });
        var codes = {};
        if(_.isEmpty(names)) {
            return codes;
        }
        _.each(content, function (qty, key) {
            var code = key.indexOf(RB) === 0 ? key.substr(RB.length) : key,
                card = app.data.cards.findById(code);
            if(card && (names[plain(card.label)] || names[plain(card.name)])) {
                codes[code] = true;
            }
        });
        return codes;
    };

    deck.display_validation = function display_validation(container)
    {
        var html;
        if($(container).hasClass('rb-validation')) {
            return display_validation_box(container);
        }
        if(!validation_available || !validation) {
            html = '<div class="alert alert-warning">' + Translator.trans('deck.validation.unavailable') + '</div>';
        } else if(deck.is_valid()) {
            html = '<div class="alert alert-success"><span class="fas fa-check"></span> '
                + Translator.trans('deck.validation.valid', {format: _.escape(validation.format_name)})
                + (!validation.valid ? '<br><small>' + Translator.trans('deck.validation.valid_with_errata') + '</small>' : '')
                + '</div>';
        } else {
            var problems = validation.problems_with_errata || validation.problems || [];
            html = '<div class="alert alert-danger"><strong>'
                + Translator.trans('deck.validation.problems', {format: _.escape(validation.format_name)})
                + '</strong><ul class="deck-problems">'
                + _.map(problems, function (p) { return '<li>' + _.escape(p) + '</li>'; }).join('')
                + '</ul></div>';
        }
        $(container).html(html);
    };

    /**
     * The deckbuilder's Gemp box (Ketura, 2026-11-02): red and scrollable (about
     * three lines) while there are problems, one green line once the deck is valid.
     * Gemp's sentences are shown as they are until Gemp returns problem codes.
     */
    function display_validation_box(container)
    {
        var box = $(container).removeClass('is-none is-bad is-good is-unavailable'),
            html;
        if(!data.target_format) {
            box.addClass('is-none');
            html = '<div class="rb-validation-line">' + Translator.trans('deckbuilder.validation.no_format') + '</div>';
        } else if(!validation_available || !validation) {
            box.addClass('is-unavailable');
            html = '<div class="rb-validation-line">' + Translator.trans('deck.validation.unavailable') + '</div>';
        } else if(deck.is_valid()) {
            box.addClass('is-good');
            html = '<div class="rb-validation-line"><span class="fas fa-check"></span> '
                + Translator.trans('deckbuilder.validation.valid', {format: _.escape(validation.format_name)}) + '</div>';
        } else {
            var problems = deck.get_problems();
            box.addClass('is-bad');
            html = '<div class="rb-validation-head">' + Translator.transChoice('deckbuilder.validation.problems', problems.length, {count: problems.length, format: _.escape(validation.format_name)}) + '</div>'
                + '<ul class="rb-validation-list">' + _.map(problems, function (p) { return '<li>' + _.escape(p) + '</li>'; }).join('') + '</ul>';
        }
        box.html(html);
        $(document).trigger('deck.validated');
    }

    /* ---- display ---- */

    function card_line(slot, options)
    {
        var card = slot.card,
            format = deck.get_format(),
            limit = format ? app.data.format_limit(format, card.code) : null,
            html = '<div class="deck-card deck-card-' + (card.side || 'none') + '" data-key="' + slot.key + '">';
        if(options.qty !== false) {
            html += '<span class="deck-card-qty">' + slot.qty + 'x</span> ';
        }
        html += '<a href="' + card.url + '" class="card card-tip" data-code="' + card.code + '">'
            + (card.is_unique ? '&bull;' : '') + _.escape(card.label) + '</a>';
        if(card.kind === 'errata') {
            html += ' <span class="label label-info" title="' + Translator.trans('card.kind.errata_short') + '">PC</span>';
        }
        if(limit) {
            html += ' <span class="label label-' + (limit === 'banned' ? 'danger' : 'warning') + ' deck-card-limit">'
                + Translator.trans('formats.list.' + limit) + '</span>';
        }
        if(options.editable) {
            html += ' <span class="deck-card-edit">'
                + '<a href="#" class="deck-card-minus" title="-1"><span class="fas fa-minus-circle"></span></a>'
                + (options.plus !== false ? ' <a href="#" class="deck-card-plus" title="+1"><span class="fas fa-plus-circle"></span></a>' : '')
                + '</span>';
        }
        return html + '</div>';
    }

    function section(title, slots, options)
    {
        var html = '<h5 class="deck-section-title">' + title + '</h5>';
        if(!slots.length) {
            return html + '<div class="text-muted">&mdash;</div>';
        }
        return html + _.map(slots, function (slot) { return card_line(slot, options); }).join('');
    }

    function sites_html(options)
    {
        var sites = deck.get_cards('site'),
            format = deck.get_format(),
            total = count(sites),
            html = '<h5 class="deck-section-title">' + Translator.trans('deck.adventure_deck') + ' (' + total + ')</h5>';
        if(format && format.numbered_sites) {
            for(var n = 1; n <= 9; n++) {
                var here = _.filter(sites, function (s) { return s.card.site_number === n; });
                html += '<div class="deck-site-number"><span class="site-number">' + n + '</span>'
                    + (here.length ? _.map(here, function (s) { return card_line(s, _.extend({}, options, {qty: false, plus: false})); }).join('') : '<span class="text-muted">&mdash;</span>')
                    + '</div>';
            }
            var unnumbered = _.filter(sites, function (s) { return !s.card.site_number || s.card.site_number > 9; });
            html += _.map(unnumbered, function (s) { return card_line(s, _.extend({}, options, {qty: false, plus: false})); }).join('');
            return html;
        }
        if(!sites.length) {
            return html + '<div class="text-muted">&mdash;</div>';
        }
        return html + _.map(sites, function (s) { return card_line(s, _.extend({}, options, {qty: s.qty > 1, plus: false})); }).join('');
    }

    function side_html(side, options)
    {
        var slots = _.filter(deck.get_cards('draw'), function (s) {
                return side === 'shadow' ? s.card.side === 'shadow' : s.card.side !== 'shadow';
            }),
            html = '<h5 class="deck-section-title">' + Translator.trans('side.' + side) + ' (' + count(slots) + ')</h5>';
        if(!slots.length) {
            return html + '<div class="text-muted">&mdash;</div>';
        }
        _.each(_.groupBy(slots, function (s) { return s.card.culture_name || ''; }), function (culture_slots, culture) {
            html += '<h6 class="deck-culture">' + _.escape(culture || Translator.trans('deck.no_culture')) + ' (' + count(culture_slots) + ')</h6>';
            html += _.map(culture_slots, function (s) { return card_line(s, options); }).join('');
        });
        return html;
    }

    /**
     * Renders the deck into the container.
     * @param {Object} options {editable: bool} adds +/- controls on each card
     */
    deck.display = function display(container, options)
    {
        options = options || {};
        var format = deck.get_format(),
            rb = deck.get_cards('ring_bearer'),
            map = deck.get_cards('map'),
            html = '<p class="deck-summary"><strong>' + Translator.trans('deck.draw_deck') + ':</strong> '
                + deck.get_draw_deck_size()
                + ' (' + Translator.trans('side.free_peoples') + ' ' + deck.get_free_peoples_count()
                + ', ' + Translator.trans('side.shadow') + ' ' + deck.get_shadow_count() + ')'
                + (format ? ' &middot; ' + _.escape(format.name) : '')
                + '</p>';

        html += '<div class="row"><div class="col-sm-6">'
            + section(Translator.trans('deck.ring_bearer'), rb, _.extend({}, options, {qty: false, plus: false}))
            + section(Translator.trans('deck.ring'), deck.get_cards('ring'), _.extend({}, options, {qty: false, plus: false}));
        if(map.length || (format && format.uses_maps)) {
            html += section(Translator.trans('deck.map'), map, _.extend({}, options, {qty: false, plus: false}));
        }
        html += '</div><div class="col-sm-6">' + sites_html(options) + '</div></div>';
        html += '<div class="row"><div class="col-sm-6 deck-side">' + side_html('free_peoples', options) + '</div>'
            + '<div class="col-sm-6 deck-side">' + side_html('shadow', options) + '</div></div>';

        $(container).removeClass('deck-loading').html(html);
    };

    /** Text export in the format DeckImportService reads back */
    deck.get_export = function get_export(type)
    {
        var gemp = deck.get_gemp_string();
        if(type === 'gemp') {
            return gemp;
        }
        // the plain text is the list (Ketura, 2026-11-04): the same as the server's text download
        return deck.get_list();
    };

    /**
     * The deck as a list for people and for Gemp's import (Ketura, 2026-11-04): the format by
     * its name, "2x Card" lines, no blueprint codes and no Gemp string.
     */
    deck.get_list = function get_list()
    {
        var line = function (s) { return s.qty + 'x ' + s.label; },
            live = function (role) { return _.reject(deck.get_cards(role), 'pending'); },
            rb = live('ring_bearer'),
            ring = live('ring'),
            map = live('map'),
            draw = live('draw'),
            format = deck.get_format(),
            lines = [data.name || '', 'Format: ' + (format ? format.name : (data.target_format || '')), ''];
        if(rb.length) { lines.push('Ring-bearer: ' + rb[0].label); }
        if(ring.length) { lines.push('Ring: ' + ring[0].label); }
        lines.push('', 'Adventure Deck');
        lines = lines.concat(_.map(live('site'), line));
        lines.push('', 'Free Peoples');
        lines = lines.concat(_.map(_.filter(draw, function (s) { return s.card.side !== 'shadow'; }), line));
        lines.push('', 'Shadow');
        lines = lines.concat(_.map(_.filter(draw, function (s) { return s.card.side === 'shadow'; }), line));
        if(map.length) { lines.push('', 'Map: ' + map[0].card.label); }
        return lines.join('\n');
    };

    /** Gemp's deck string: ringBearer|ring|site,...|card,...[|map] */
    deck.get_gemp_string = function get_gemp_string()
    {
        var codes = function (role) {
            var list = [];
            _.each(_.reject(deck.get_cards(role), 'pending'), function (s) {
                for(var i = 0; i < s.qty; i++) { list.push(s.stand_in.code); }
            });
            return list;
        };
        var parts = [codes('ring_bearer')[0] || '', codes('ring')[0] || '', codes('site').join(','), codes('draw').join(',')],
            map = codes('map');
        if(map.length) {
            parts.push(map[0]);
        }
        return parts.join('|');
    };

    /** "2x Name" labels for a content map's keys, for the history */
    deck.get_card_labels = function get_card_labels(keys_and_qty)
    {
        return _.map(keys_and_qty, function (qty, key) {
            var is_rb = key.indexOf(RB) === 0,
                card = app.data.cards.findById(is_rb ? key.substr(RB.length) : key);
            return (is_rb ? Translator.trans('deck.ring_bearer') + ': ' : qty + 'x ')
                + (card ? card.label : key);
        });
    };

})(app.deck = {}, jQuery);
