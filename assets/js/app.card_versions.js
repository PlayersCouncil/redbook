/* global app, _, Translator */
/**
 * A card's versions in the browser (Ketura, 2026-11-08), as App\Services\CardVersions has them
 * on the server, for cards a deck takes any version of ("any Frodo", "Legolas (any)"):
 * - most cards: every card with the same title, compared folded (accents dropped, lower
 *   case, apostrophes dropped, "&" as "and", other runs of non-letters one space);
 * - a site: every site of its path with its number ("any site 3"); a Shadows site, every
 *   Shadows site ("any site").
 * Reprints, same title and subtitle, are one version.
 */
(function app_card_versions(versions)
{
    var SITE_BLOCK_BY_SET = {
            '1': 'Fellowship', '2': 'Fellowship', '3': 'Fellowship', '101': 'Fellowship',
            '4': 'Towers', '5': 'Towers', '6': 'Towers', '102': 'Towers',
            '7': 'King', '8': 'King', '9': 'King', '10': 'King', '103': 'King'
        },
        index = null,
        index_size = 0,
        // the two versions a split tile shows, picked once per page load
        pairs = {};

    versions.fold = function fold(text)
    {
        text = (text || '') + '';
        if(text.normalize) {
            text = text.normalize('NFKD');
        }
        return text.replace(/[̀-ͯ]/g, '').toLowerCase()
            .replace(/['’]/g, '').replace(/&/g, ' and ')
            .replace(/[^\p{L}\p{N}]+/gu, ' ').trim();
    };

    function block(card)
    {
        if(card.site_block) {
            return card.site_block;
        }
        var set = card.pack_code;
        if(card.kind === 'errata' && card.errataOf_code) {
            set = card.errataOf_code.split('_')[0];
        }
        return SITE_BLOCK_BY_SET[set] || '';
    }

    versions.group = function group(card)
    {
        if(card.type_code === 'site') {
            var b = block(card);
            return b === 'Shadows' || !card.site_number ? 'site|Shadows' : 'site|' + b + '|' + card.site_number;
        }
        return 'title|' + versions.fold(card.name);
    };

    function build()
    {
        if(index && index_size === app.data.cards.count()) {
            return;
        }
        var all = app.data.cards.find();
        index = _.groupBy(all, versions.group);
        index_size = all.length;
        _.each(index, function (list) {
            list.sort(function (a, b) { return a.code.localeCompare(b.code, undefined, {numeric: true}); });
        });
    }

    versions.members = function members(card)
    {
        build();
        return index[versions.group(card)] || [card];
    };

    function legal(card, format)
    {
        var sets = format && format.sets && format.sets.length ? format.sets : null;
        return !sets || sets.indexOf(card.pack_code) !== -1;
    }

    /** One card per version legal in the format (all versions without one) */
    versions.versions = function list(card, format)
    {
        var seen = {}, out = [], site = card.type_code === 'site';
        _.each(versions.members(card), function (member) {
            if(!legal(member, format)) {
                return;
            }
            var key = versions.fold(member.name) + (site ? '' : '|' + versions.fold(member.subtitle));
            if(!seen[key]) {
                seen[key] = true;
                out.push(member);
            }
        });
        return out;
    };

    /** Some other version is legal in the format */
    versions.can_be_generic = function can_be_generic(card, format)
    {
        return versions.versions(card, format).length > 1;
    };

    /** The one card a generic slot stands for: its own version if legal, else the format's first */
    versions.stand_in = function stand_in(card, format)
    {
        if(legal(card, format)) {
            return card;
        }
        // the lowest non-zero set first: set 0 is promos from many formats (Ketura, 2026-11-08)
        var members = _.sortBy(versions.members(card), function (member) { return member.pack_code === '0' ? 1 : 0; });
        return _.find(members, function (member) { return legal(member, format); }) || card;
    };

    /** "Legolas (any)", "Any site 3", "Any site" */
    versions.label = function label(card)
    {
        if(card.type_code === 'site') {
            return card.site_number ? Translator.trans('deckbuilder.any.site_n', {n: card.site_number}) : Translator.trans('deckbuilder.any.site');
        }
        return Translator.trans('deckbuilder.any.card', {name: card.name});
    };

    /** Two different versions for a split tile, picked at random once per page load */
    versions.pair = function pair(card, format)
    {
        var key = versions.group(card) + '@' + (format ? format.code : '');
        if(!pairs[key]) {
            var list = _.shuffle(versions.versions(card, format));
            if(list.length < 2) {
                list = _.shuffle(versions.versions(card, null));
            }
            pairs[key] = list.length >= 2 ? [list[0], list[1]] : [card, card];
        }
        return pairs[key];
    };

})(app.card_versions = {});
