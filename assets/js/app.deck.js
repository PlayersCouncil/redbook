/* global app, _, Translator, Routing */
/**
 * The deck being viewed or edited, in the browser (phase3/03).
 *
 * Content is a map of key => quantity: the key is the card's blueprint ID,
 * except the Ring-bearer's, which is "rb:" + its blueprint ID (the same companion
 * may also be in the draw deck). Every other card's part of the deck follows from
 * its type, as on the server (App\Classes\DeckContent).
 */
(function app_deck(deck, $)
{
    var RB = 'rb:',
        data = {},
        content = {},
        validation = null,
        validation_available = true,
        validate_timer = null,
        validate_request = null;

    deck.ROLES = ['ring_bearer', 'ring', 'site', 'draw', 'map'];

    /**
     * @param {Object} json the deck or decklist as its jsonSerialize() gives it
     * @memberOf deck
     */
    deck.init = function init(json)
    {
        data = json || {};
        content = _.extend({}, data.slots || {});
        validation = data.validation || null;
        validation_available = !!data.validated_at || !data.id;
    };

    deck.get_id = function () { return data.id; };
    deck.get_uuid = function () { return data.uuid; };
    deck.get_name = function () { return data.name; };
    deck.get_description_md = function () { return data.description_md; };
    deck.get_tags = function () { return data.tags; };
    deck.get_effective_date = function () { return data.effective_date; };
    deck.get_format_code = function () { return data.target_format; };
    deck.set_format_code = function (code) { data.target_format = code; };
    deck.get_format = function () { return app.data.findFormat(data.target_format); };

    deck.role_for_card = function role_for_card(card)
    {
        switch(card.type_code) {
            case 'the_one_ring': return 'ring';
            case 'site': return 'site';
            case 'map': return 'map';
            default: return 'draw';
        }
    };

    /** @return {Object} a copy of the content map */
    deck.get_content = function get_content()
    {
        return _.extend({}, content);
    };

    deck.get_json = function get_json()
    {
        return JSON.stringify(content);
    };

    deck.set_content = function set_content(new_content)
    {
        content = {};
        _.each(new_content || {}, function (qty, key) {
            qty = parseInt(qty, 10);
            if(qty > 0) {
                content[key] = qty;
            }
        });
        deck.changed();
    };

    /** Copies of a card outside the Ring-bearer slot */
    deck.get_quantity = function get_quantity(code)
    {
        return content[code] || 0;
    };

    /**
     * Sets a card's copies. The One Ring and a map replace any other Ring or map.
     */
    deck.set_quantity = function set_quantity(code, qty)
    {
        var card = app.data.cards.findById(code);
        if(!card) {
            return;
        }
        var role = deck.role_for_card(card);
        if((role === 'ring' || role === 'map') && qty > 0) {
            _.each(deck.get_cards(role), function (other) {
                delete content[other.card.code];
            });
            qty = 1;
        }
        if(qty > 0) {
            content[code] = qty;
        } else {
            delete content[code];
        }
        deck.changed();
    };

    deck.get_ring_bearer_code = function get_ring_bearer_code()
    {
        var key = _.find(_.keys(content), function (k) { return k.indexOf(RB) === 0; });
        return key ? key.substr(RB.length) : null;
    };

    /** @param {String|null} code a companion's blueprint ID, or null to clear */
    deck.set_ring_bearer = function set_ring_bearer(code)
    {
        _.each(_.keys(content), function (k) {
            if(k.indexOf(RB) === 0) {
                delete content[k];
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
            var is_rb = key.indexOf(RB) === 0,
                code = is_rb ? key.substr(RB.length) : key,
                card = app.data.cards.findById(code);
            if(!card) {
                return;
            }
            var card_role = is_rb ? 'ring_bearer' : deck.role_for_card(card);
            if(card_role === role) {
                list.push({key: key, card: card, qty: qty});
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
        return _.map(deck.get_cards('draw'), function (slot) {
            return _.extend({}, slot.card, {indeck: slot.qty});
        });
    };

    function count(slots, side)
    {
        return _.reduce(slots, function (sum, slot) {
            return sum + (!side || slot.card.side === side ? slot.qty : 0);
        }, 0);
    }

    deck.get_draw_deck_size = function () { return count(deck.get_cards('draw')); };
    deck.get_free_peoples_count = function () { return count(deck.get_cards('draw'), 'free_peoples'); };
    deck.get_shadow_count = function () { return count(deck.get_cards('draw'), 'shadow'); };

    /** Cultures of the draw deck: [{code, name, count}] largest first */
    deck.get_cultures = function get_cultures()
    {
        var cultures = {};
        _.each(deck.get_cards('draw'), function (slot) {
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
        validate_timer = setTimeout(deck.validate, 900);
        $('#deck-validation').addClass('deck-validation-pending');
    };

    deck.validate = function validate()
    {
        if(validate_request) {
            validate_request.abort();
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

    deck.display_validation = function display_validation(container)
    {
        var html;
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
        var line = function (s) { return s.qty + 'x ' + s.card.label + ' (' + s.card.code + ')'; },
            rb = deck.get_cards('ring_bearer'),
            ring = deck.get_cards('ring'),
            map = deck.get_cards('map'),
            draw = deck.get_cards('draw'),
            lines = [data.name || '', 'Format: ' + (data.target_format || ''), ''];
        if(rb.length) { lines.push('Ring-bearer: ' + rb[0].card.label + ' (' + rb[0].card.code + ')'); }
        if(ring.length) { lines.push('Ring: ' + ring[0].card.label + ' (' + ring[0].card.code + ')'); }
        lines.push('', 'Adventure deck');
        lines = lines.concat(_.map(deck.get_cards('site'), line));
        lines.push('', 'Free Peoples');
        lines = lines.concat(_.map(_.filter(draw, function (s) { return s.card.side !== 'shadow'; }), line));
        lines.push('', 'Shadow');
        lines = lines.concat(_.map(_.filter(draw, function (s) { return s.card.side === 'shadow'; }), line));
        if(map.length) { lines.push('', 'Map: ' + map[0].card.label + ' (' + map[0].card.code + ')'); }
        lines.push('', 'Gemp: ' + gemp);
        return lines.join('\n');
    };

    /** Gemp's deck string: ringBearer|ring|site,...|card,...[|map] */
    deck.get_gemp_string = function get_gemp_string()
    {
        var codes = function (role) {
            var list = [];
            _.each(deck.get_cards(role), function (s) {
                for(var i = 0; i < s.qty; i++) { list.push(s.card.code); }
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
