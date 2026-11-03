/* global app, _, Routing */
/**
 * [[card]] links in markdown, in the browser (Ketura, 2026-11-03): previews, and pages
 * that render markdown here. The server does the same when a text is saved, in
 * src/Services/CardLinker.php, which lists the forms; change the two together.
 *
 *   [[Aragorn, Heir to the White City]] [[Aragorn HTTWC]] [[Heir to the White City]]
 *   [[HTTWC]] [[3_38]] [[3R38]] [[3_38.1]] [[HTTWC|the king]]
 */
(function app_cardlinks(cardlinks, $)
{
    var PATTERN = /\[\[([^\[\]|\n]{1,100})(?:\|([^\[\]\n]{1,100}))?\]\]/g,
        CODE = /(```[\s\S]*?```|~~~[\s\S]*?~~~|`[^`\n]*`)/,
        cards = null, tiers, by_coll, by_setnum, loaded_from = null;

    function normalize(s)
    {
        return (s || '').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '')
            .replace(/[•·'’‘"“”]/g, '').replace(/[^\p{L}\p{N}]+/gu, ' ').trim();
    }

    function initials(s)
    {
        var words = normalize(s).split(' ');
        return words.length > 1 ? _.map(words, function (w) { return w.charAt(0); }).join('') : '';
    }

    function set_num(code)
    {
        var parts = code.split('_');
        return [parseInt(parts[0], 10), parseInt(parts[1], 10)];
    }

    function load()
    {
        if(!app.data || !app.data.cards) {
            return false;
        }
        var all = app.data.cards.find();
        if(cards && loaded_from === all.length) {
            return true;
        }
        cards = {};
        tiers = [{}, {}, {}, {}, {}];
        by_coll = {};
        by_setnum = {};
        _.each(all, function (card) {
            cards[card.code] = {code: card.code, label: card.label, url: card.url, revisions: card.revisions || [], errata: null, errata_of: card.errataOf_code || null};
        });
        _.each(all, function (card) {
            if(card.errataOf_code && cards[card.errataOf_code]) {
                cards[card.errataOf_code].errata = card.code;
            }
            if(card.kind === 'errata') {
                return;
            }
            var sn = set_num(card.code);
            (by_setnum[sn[0] + '_' + sn[1]] = by_setnum[sn[0] + '_' + sn[1]] || []).push(card.code);
            if(card.coll_info) {
                var coll = card.coll_info.replace(/\s+/g, '').toUpperCase();
                by_coll[coll] = by_coll[coll] || card.code;
            }
            var name = normalize(card.name),
                sub = card.subtitle ? normalize(card.subtitle) : '',
                ini = card.subtitle ? initials(card.subtitle) : '';
            _.each([sub ? name + ' ' + sub : name, ini ? name + ' ' + ini : '', sub, ini, name], function (key, tier) {
                if(key) {
                    (tiers[tier][key] = tiers[tier][key] || []).push(card.code);
                }
            });
        });
        loaded_from = all.length;
        return true;
    }

    function found(code)
    {
        return {state: 'card', code: code, label: cards[code].label, href: cards[code].url};
    }

    function pick(codes, target)
    {
        codes = _.uniq(codes || []);
        if(!codes.length) {
            return {state: 'missing'};
        }
        codes.sort(function (a, b) {
            var x = set_num(a), y = set_num(b);
            return x[0] - y[0] || x[1] - y[1];
        });
        var titles = _.uniq(_.map(codes, function (c) { return normalize(cards[c].label); }));
        if(titles.length === 1) {
            return found(codes[0]);
        }
        return {state: 'ambiguous', count: titles.length, href: Routing.generate('cards_find', {q: target})};
    }

    function revision(code, n)
    {
        var base = cards[code].errata_of || code,
            card = cards[base];
        if(n === 0) {
            return _.extend(found(base), {label: card.label + ' (rev. 0)', href: card.url + '#v0'});
        }
        var rev = _.findWhere(card.revisions, {n: n});
        if(!rev) {
            return {state: 'missing'};
        }
        var page = rev.pc && card.errata ? card.errata : base;
        return {state: 'card', code: page, label: card.label + ' (rev. ' + n + ')', image: rev.image, href: cards[page].url + '#v' + n};
    }

    /** What a [[target]] points at: {state: card|ambiguous|missing, ...} */
    cardlinks.resolve = function resolve(target)
    {
        if(!load()) {
            return {state: 'missing'};
        }
        target = $.trim(target);
        var m = target.match(/^(\d+)_(\d+)(?:\.(\d+))?$/);
        if(m) {
            var code = cards[m[1] + '_' + parseInt(m[2], 10)] ? m[1] + '_' + parseInt(m[2], 10) : m[1] + '_' + m[2];
            if(!cards[code]) {
                return {state: 'missing'};
            }
            return m[3] !== undefined ? revision(code, parseInt(m[3], 10)) : found(code);
        }
        m = target.match(/^(\d+)\s*([A-Za-z]+)\s*(\d+)$/);
        if(m) {
            var coll = m[1] + m[2].toUpperCase() + parseInt(m[3], 10);
            if(by_coll[coll]) {
                return found(by_coll[coll]);
            }
            return pick(by_setnum[m[1] + '_' + parseInt(m[3], 10)], target);
        }
        var key = normalize(target);
        for(var i = 0; i < tiers.length; i++) {
            if(tiers[i][key]) {
                return pick(tiers[i][key], target);
            }
        }
        return {state: 'missing'};
    };

    cardlinks.html = function html(target, label)
    {
        var f = cardlinks.resolve(target),
            e = _.escape;
        if(f.state === 'card') {
            return '<a href="' + e(f.href) + '" class="card-tip rb-cardlink" data-code="' + e(f.code) + '"'
                + (f.image ? ' data-image="' + e(f.image) + '"' : '') + '>' + e(label || f.label) + '</a>';
        }
        if(f.state === 'ambiguous') {
            return '<a href="' + e(f.href) + '" class="rb-cardlink is-ambiguous" title="' + e(f.count + ' cards match') + '">' + e(label || target) + '</a>';
        }
        return '<span class="rb-cardlink is-missing" title="No card matches">[[' + e(target) + ']]</span>';
    };

    /** Markdown to HTML, with the [[links]] outside code turned into card links */
    cardlinks.markdown = function markdown(text)
    {
        var links = {}, n = 0,
            parts = (text || '').split(CODE);
        for(var i = 0; i < parts.length; i += 2) {
            parts[i] = parts[i].replace(PATTERN, function (all, target, label) {
                var token = 'rbcardlink' + (n++) + 'x';
                links[token] = cardlinks.html(target, label ? $.trim(label) : null);
                return token;
            });
        }
        var html = typeof marked === 'function' ? marked(parts.join('')) : _.escape(parts.join(''));
        return n ? html.replace(/rbcardlink\d+x/g, function (token) { return links[token] || token; }) : html;
    };

})(app.cardlinks = {}, jQuery);
