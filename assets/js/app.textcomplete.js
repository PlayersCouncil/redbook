/* global _, app, Translator */

(function app_textcomplete(textcomplete, $)
{

    var icons = 'baratheon greyjoy intrigue lannister martell military thenightswatch power stark targaryen tyrell unique plot attachment location character event agenda neutral'.split(' ');

    /** Lower case without accents or the unique dot, for matching what's typed */
    textcomplete.fold = function fold(s)
    {
        return (s || '').normalize('NFD').replace(/[\u0300-\u036f]/g, '').replace(/\u2022/g, '').toLowerCase();
    };

    /**
     * Cards whose name holds what's typed, those beginning with it first, then those with a
     * word beginning with it, then the rest; alphabetical within each (Ketura, 2026-11-07)
     */
    textcomplete.rank = function rank(cards, typed, first)
    {
        var term = textcomplete.fold(typed);
        if(!term) {
            return [];
        }
        var scored = [];
        _.each(cards, function (card) {
            var label = textcomplete.fold(card.label), at = label.indexOf(term);
            if(at === -1) {
                return;
            }
            var score = at === 0 ? 0 : new RegExp('(^|[^a-z0-9])' + term.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')).test(label) ? 1 : 2;
            scored.push({card: card, score: score, extra: first ? first(card) : 0});
        });
        scored.sort(function (a, b) {
            return a.score - b.score || a.extra - b.extra || a.card.label.localeCompare(b.card.label);
        });
        return _.pluck(scored, 'card');
    };

    /** The text, escaped, with the first stretch matching what's typed in bold (Ketura, 2026-11-07) */
    textcomplete.highlight = function highlight(text, typed)
    {
        var term = textcomplete.fold(typed);
        if(!term) {
            return _.escape(text);
        }
        // fold the text a character at a time, so the match maps back onto the original
        var folded = '', map = [];
        for(var i = 0; i < text.length; i++) {
            var f = textcomplete.fold(text[i]);
            for(var j = 0; j < f.length; j++) {
                folded += f[j];
                map.push(i);
            }
        }
        var at = folded.indexOf(term);
        if(at === -1) {
            return _.escape(text);
        }
        var start = map[at], end = map[at + term.length - 1] + 1;
        return _.escape(text.slice(0, start)) + '<b>' + _.escape(text.slice(start, end)) + '</b>' + _.escape(text.slice(end));
    };

    /**
     * options: cards, icons, users
     */
    textcomplete.setup = function setup(textarea, options)
    {

        options = _.extend({cards: true, icons: true, users: false}, options);

        var actions = [];

        if(options.cards) {
            var typed = '';
            actions.push({
                match: /\B#([\-+\w]*)$/,
                search: function (term, callback)
                {
                    // names beginning with it first, the match in bold (Ketura, 2026-11-07)
                    typed = term;
                    callback(term ? textcomplete.rank(app.data.cards.find({kind: 'card'}), term).slice(0, 10) : []);
                },
                template: function (value)
                {
                    return textcomplete.highlight(value.label, typed);
                },
                replace: function (value)
                {
                    // a [[card]] link for text that resolves them (Ketura, 2026-11-07)
                    if(options.card_syntax === 'wiki') {
                        return '[[' + value.label + ']]';
                    }
                    return '[' + value.label + ']('
                            + Routing.generate('cards_zoom', {card_code: value.code})
                            + ')';
                },
                index: 1
            });
        }

        if(options.icons) {
            actions.push({
                match: /\$([\-+\w]*)$/,
                search: function (term, callback)
                {
                    var regexp = new RegExp(term, 'i');
                    callback(_.filter(icons,
                            function (symbol)
                            {
                                return regexp.test(Translator.trans('icon.' + symbol));
                            }
                    ));
                },
                template: function (value)
                {
                    return Translator.trans('icon.' + value);
                },
                replace: function (value)
                {
                    return '<span class="icon-' + value + '"></span>';
                },
                index: 1
            });
        }

        if(options.users) {
            actions.push({
                match: /\B@([\-+\w]*)$/,
                search: function (term, callback)
                {
                    var regexp = new RegExp('^' + term, 'i');
                    callback($.grep(options.users, function (user)
                    {
                        return regexp.test(user);
                    }));
                },
                template: function (value)
                {
                    return value;
                },
                replace: function (value)
                {
                    return '`@' + value + '`';
                },
                index: 1
            });
        }

        $(textarea).textcomplete(actions);

    };

})(app.textcomplete = {}, jQuery);
