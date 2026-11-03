/* global app, _, Translator, Highcharts */
/**
 * The deckbuilder's Stats tab (Ketura, 2026-11-03): test draws and the deck's charts.
 *
 * Test draws come from the draw deck less the starting fellowship (one copy of each
 * companion marked S); the Ring-bearer was never in it. Opening draw deals 8 afresh,
 * Mulligan 6, each sorted Free Peoples then Shadow; Draw 1/2/8 add on the right as they
 * come. The odds line is the chance of holding at least one copy of a card, by how many
 * copies the deck runs, in the cards drawn so far.
 *
 * Charts, one per row, with Free Peoples and Shadow always split (never side by side):
 * the twilight curve (Free Peoples up, Shadow down), card types and cultures (Free
 * Peoples left, Shadow right). Free Peoples are light tan, Shadow a mid purple. The
 * type chart folds companions, allies and minions into Characters and marks half the
 * Shadow side: at least half of it should be minions.
 *
 * The run-out chart estimates how many cards each player has left in their draw deck
 * site by site, from movement patterns (S single move, D double move) and how many
 * cards each plays per turn (Free Peoples) and per move (Shadow). The model: both
 * draw 8; the Free Peoples player plays their cards once a turn, the Shadow player
 * once per move; when the fellowship stays, both reconcile (draw back up to 8); when
 * it moves again only the Shadow player does. No discards, no card draw from effects.
 */
(function app_deck_stats(stats, $)
{
    var FP = '#D9C49A',
        FP_LIGHT = '#EFE5CF',
        SHADOW = '#8B6AA8',
        YOU = '#1D5FAD',
        THEM = '#B4572E',
        library = [],
        hand = [],
        sorted_count = 0,
        drawn = 0,
        runout = {fp_play: 3, shadow_play: 4, you: 'DSDSD'.split(''), them: 'DSDSD'.split(''), first: 'you', their_deck: null};

    function t(key, params)
    {
        return Translator.trans(key, params || {});
    }

    function visible()
    {
        return $('#tab-pane-stats').hasClass('active');
    }

    /* ---- the cards a test draw comes from ---- */

    function draw_pile()
    {
        var starting = app.deck.get_starting(),
            pile = [];
        _.each(app.deck.get_draw_deck(), function (card) {
            var copies = card.indeck - (_.contains(starting, card.code) ? 1 : 0);
            for(var i = 0; i < copies; i++) {
                pile.push(card);
            }
        });
        return pile;
    }

    function shuffle(list)
    {
        for(var i = list.length - 1; i > 0; i--) {
            var j = Math.floor(Math.random() * (i + 1)), tmp = list[i];
            list[i] = list[j];
            list[j] = tmp;
        }
        return list;
    }

    function deal(count, sort)
    {
        var cards = library.splice(0, count);
        drawn += cards.length;
        if(sort) {
            // Free Peoples on the left, Shadow on the right
            hand = _.filter(cards, function (c) { return c.side !== 'shadow'; }).concat(_.filter(cards, function (c) { return c.side === 'shadow'; }));
            sorted_count = hand.length;
        } else {
            hand = hand.concat(cards);
        }
    }

    function reset()
    {
        library = shuffle(draw_pile());
        hand = [];
        sorted_count = 0;
        drawn = 0;
    }

    function render_hand()
    {
        var html = '',
            card_html = function (card) {
                return '<div class="rb-draw-card" data-hover-code="' + card.code + '" data-hover-grow="1">'
                    + (card.image_url ? '<img src="' + card.image_url + '" alt="' + _.escape(card.label) + '">' : '<span>' + _.escape(card.label) + '</span>') + '</div>';
            },
            sorted = hand.slice(0, sorted_count),
            fp = _.filter(sorted, function (c) { return c.side !== 'shadow'; }),
            shadow = _.filter(sorted, function (c) { return c.side === 'shadow'; }),
            extra = hand.slice(sorted_count);
        if(fp.length) {
            html += '<div class="rb-draw-group is-fp">' + _.map(fp, card_html).join('') + '</div>';
        }
        if(shadow.length) {
            html += '<div class="rb-draw-group is-shadow">' + _.map(shadow, card_html).join('') + '</div>';
        }
        if(extra.length) {
            html += '<div class="rb-draw-group is-extra">' + _.map(extra, card_html).join('') + '</div>';
        }
        $('#draw-hand').html(html);
        var fp_n = _.filter(hand, function (c) { return c.side !== 'shadow'; }).length;
        $('#draw-status').text(hand.length
            ? t('deckbuilder.statstab.hand_status', {count: hand.length, fp: fp_n, shadow: hand.length - fp_n, left: library.length})
            : t('deckbuilder.statstab.pile_status', {count: library.length}));
        render_odds();
    }

    function render_odds()
    {
        var size = library.length + drawn,
            n = drawn || Math.min(8, size);
        if(!size || !app.hypergeometric) {
            $('#draw-odds').empty();
            return;
        }
        var parts = _.map([1, 2, 3, 4], function (copies) {
            var p = copies <= size ? app.hypergeometric.get_cumul(1, size, copies, n) : 0;
            return '<span><strong>' + Math.round(100 * p) + '%</strong> ' + Translator.transChoice('deckbuilder.statstab.odds_copies', copies, {count: copies}) + '</span>';
        });
        $('#draw-odds').html('<span class="rb-odds-label">' + t(drawn ? 'deckbuilder.statstab.odds_drawn' : 'deckbuilder.statstab.odds_opening', {count: n, size: size}) + '</span>' + parts.join(''));
    }

    stats.draw = function draw(what)
    {
        if(what === 'opening' || what === 'mulligan') {
            reset();
            deal(what === 'opening' ? 8 : 6, true);
        } else if(what === 'reset') {
            reset();
        } else {
            if(!library.length && !hand.length) {
                reset();
            }
            deal(parseInt(what, 10) || 1, false);
        }
        render_hand();
    };

    /* ---- charts ---- */

    function abs_label() { return Math.abs(this.value); }

    function base(type, height)
    {
        return {
            chart: {type: type, height: height, backgroundColor: 'transparent', style: {fontFamily: 'inherit'}},
            title: {text: null},
            credits: {enabled: false},
            legend: {enabled: true, itemStyle: {fontWeight: 'normal'}},
            plotOptions: {series: {stacking: 'normal', borderWidth: 0, groupPadding: 0.08, pointPadding: 0.04, animation: false}},
            tooltip: {formatter: function () { return '<b>' + this.series.name + '</b><br>' + this.x + ': ' + Math.abs(this.y); }}
        };
    }

    function chart_twilight(draw)
    {
        var max = _.max([4].concat(_.map(draw, function (c) { return c.twilight || 0; }))),
            costs = _.range(0, max + 1),
            count = function (side) {
                return _.map(costs, function (cost) {
                    var n = _.reduce(draw, function (sum, c) { return sum + ((c.side === 'shadow') === (side === 'shadow') && (c.twilight || 0) === cost ? c.indeck : 0); }, 0);
                    return side === 'shadow' ? -n : n;
                });
            };
        $('#stats-chart-twilight').highcharts(_.extend(base('column', 280), {
            xAxis: {categories: costs, title: {text: t('deckbuilder.statstab.twilight_axis')}},
            yAxis: {title: {text: null}, allowDecimals: false, labels: {formatter: abs_label}, plotLines: [{value: 0, width: 1, color: '#999'}]},
            series: [
                {name: t('side.free_peoples'), color: FP, data: count('free_peoples')},
                {name: t('side.shadow'), color: SHADOW, data: count('shadow')}
            ]
        }));
    }

    function chart_types(draw)
    {
        var ORDER = ['characters', 'follower', 'possession', 'artifact', 'condition', 'event'],
            rows = {},
            totals = {fp: 0, shadow: 0};
        _.each(ORDER, function (key) { rows[key] = {companion: 0, ally: 0, fp: 0, minion: 0, shadow: 0}; });
        _.each(draw, function (c) {
            var shadow = c.side === 'shadow',
                key = _.contains(['companion', 'ally', 'minion'], c.type_code) ? 'characters' : c.type_code;
            if(!rows[key]) {
                rows[key] = {companion: 0, ally: 0, fp: 0, minion: 0, shadow: 0};
                ORDER.push(key);
            }
            if(c.type_code === 'companion' || c.type_code === 'ally' || c.type_code === 'minion') {
                rows[key][c.type_code] += c.indeck;
            } else {
                rows[key][shadow ? 'shadow' : 'fp'] += c.indeck;
            }
            totals[shadow ? 'shadow' : 'fp'] += c.indeck;
        });
        var keys = _.filter(ORDER, function (key) { var r = rows[key]; return key === 'characters' || r.companion + r.ally + r.fp + r.minion + r.shadow > 0; }),
            label = function (key) { return key === 'characters' ? t('deckbuilder.statstab.characters') : t('deckbuilder.statstab.type.' + key); },
            series = function (field, negative) { return _.map(keys, function (key) { return negative ? -rows[key][field] : rows[key][field]; }); },
            half = totals.shadow / 2;
        $('#stats-chart-types').highcharts(_.extend(base('bar', 60 + keys.length * 44), {
            xAxis: {categories: _.map(keys, label), title: {text: null}},
            yAxis: {title: {text: null}, allowDecimals: false, labels: {formatter: abs_label},
                plotLines: [{value: 0, width: 1, color: '#999'}].concat(half ? [{
                    // at least half of the Shadow side should be minions (Ketura, 2026-11-03)
                    value: half, width: 2, color: '#4B2F66', dashStyle: 'Dot', zIndex: 5,
                    label: {text: t('deckbuilder.statstab.half_shadow', {count: half}), style: {color: '#4B2F66', fontSize: '11px'}, align: 'right', rotation: 0, y: 12, x: -4}
                }] : [])},
            series: [
                {name: t('deckbuilder.statstab.companions'), color: FP, data: series('companion', true), stack: 'fp'},
                {name: t('deckbuilder.statstab.allies'), color: FP_LIGHT, borderColor: FP, borderWidth: 1, data: series('ally', true), stack: 'fp'},
                {name: t('deckbuilder.statstab.other_fp'), color: '#C4AC78', data: series('fp', true), stack: 'fp', showInLegend: false},
                {name: t('deckbuilder.statstab.minions'), color: SHADOW, data: series('minion', false), stack: 'shadow'},
                {name: t('deckbuilder.statstab.other_shadow'), color: '#A88DC0', data: series('shadow', false), stack: 'shadow', showInLegend: false}
            ]
        }));
    }

    function chart_cultures(draw)
    {
        var cultures = {};
        _.each(draw, function (c) {
            var code = c.culture_code || 'none';
            cultures[code] = cultures[code] || {name: c.culture_name || t('deck.no_culture'), fp: 0, shadow: 0};
            cultures[code][c.side === 'shadow' ? 'shadow' : 'fp'] += c.indeck;
        });
        var list = _.sortBy(_.values(cultures), function (c) { return -(c.fp + c.shadow); });
        $('#stats-chart-cultures').highcharts(_.extend(base('bar', 60 + Math.max(1, list.length) * 34), {
            xAxis: {categories: _.pluck(list, 'name'), title: {text: null}},
            yAxis: {title: {text: null}, allowDecimals: false, labels: {formatter: abs_label}, plotLines: [{value: 0, width: 1, color: '#999'}]},
            series: [
                {name: t('side.free_peoples'), color: FP, data: _.map(list, function (c) { return -c.fp; })},
                {name: t('side.shadow'), color: SHADOW, data: _.map(list, function (c) { return c.shadow; })}
            ]
        }));
    }

    /* ---- running out: the deck-size simulator ---- */

    /** S and D to a pattern of exactly 8 moves: a D that would overshoot becomes an S, short patterns get S added */
    function normalize(tokens)
    {
        var out = [], moves = 0;
        _.each(tokens, function (token) {
            if(moves >= 8) {
                return;
            }
            var step = token === 'D' && moves + 2 <= 8 ? 'D' : 'S';
            out.push(step);
            moves += step === 'D' ? 2 : 1;
        });
        while(moves < 8) {
            out.push('S');
            moves++;
        }
        return out;
    }

    function simulate(your_deck)
    {
        var players = {
                you: {deck: your_deck - 8, hand: 8, site: 1, pattern: runout.you.slice(), turn: 0, points: [[1, Math.max(0, your_deck - 8)]]},
                them: {deck: (runout.their_deck || your_deck) - 8, hand: 8, site: 1, pattern: runout.them.slice(), turn: 0, points: [[1, Math.max(0, (runout.their_deck || your_deck) - 8)]]}
            },
            play = function (p, n) { p.hand = Math.max(0, p.hand - n); },
            reconcile = function (p) {
                var want = 8 - p.hand,
                    got = Math.min(want, Math.max(0, p.deck));
                p.deck -= got;
                p.hand += got;
            },
            fp_key = runout.first,
            guard = 0;
        while(guard++ < 40) {
            var fp = players[fp_key],
                shadow = players[fp_key === 'you' ? 'them' : 'you'],
                token = fp.pattern[fp.turn];
            if(!token) {
                break;
            }
            play(fp, runout.fp_play);
            var moves = token === 'D' ? 2 : 1;
            for(var m = 1; m <= moves; m++) {
                fp.site += 1;
                play(shadow, runout.shadow_play);
                if(m < moves) {
                    // moving again: only the Shadow player reconciles
                    reconcile(shadow);
                } else {
                    reconcile(fp);
                    reconcile(shadow);
                }
                fp.points.push([fp.site, Math.max(0, fp.deck)]);
            }
            fp.turn++;
            if(fp.site >= 9) {
                // the game ends when a fellowship survives site 9
                break;
            }
            fp_key = fp_key === 'you' ? 'them' : 'you';
        }
        return players;
    }

    function pattern_html(who)
    {
        var tokens = runout[who];
        return '<div class="rb-pattern" data-pattern="' + who + '">' + _.map(tokens, function (token, i) {
            return '<div class="rb-pattern-turn" role="group" aria-label="' + t('deckbuilder.statstab.turn_n', {count: i + 1}) + '">'
                + '<button type="button" data-turn="' + i + '" data-step="S"' + (token === 'S' ? ' class="active" aria-pressed="true"' : ' aria-pressed="false"') + ' title="' + t('deckbuilder.statstab.single') + '">S</button>'
                + '<button type="button" data-turn="' + i + '" data-step="D"' + (token === 'D' ? ' class="active" aria-pressed="true"' : ' aria-pressed="false"') + ' title="' + t('deckbuilder.statstab.double') + '">D</button></div>';
        }).join('') + '<span class="rb-pattern-text">' + tokens.join('') + '</span></div>';
    }

    function render_runout_inputs(your_deck)
    {
        $('#runout-inputs').html(
            '<div class="rb-runout-row">'
            + '<label>' + t('deckbuilder.statstab.fp_play') + ' <input type="number" min="0" max="8" data-runout="fp_play" value="' + runout.fp_play + '"></label>'
            + '<label>' + t('deckbuilder.statstab.shadow_play') + ' <input type="number" min="0" max="8" data-runout="shadow_play" value="' + runout.shadow_play + '"></label>'
            + '<label>' + t('deckbuilder.statstab.their_deck') + ' <input type="number" min="8" max="200" data-runout="their_deck" value="' + (runout.their_deck || your_deck) + '"></label>'
            + '<span class="rb-seg rb-first" role="group" aria-label="' + t('deckbuilder.statstab.first') + '"><span>' + t('deckbuilder.statstab.first') + '</span>'
            + '<button type="button" data-first="you"' + (runout.first === 'you' ? ' class="active"' : '') + '>' + t('deckbuilder.statstab.you') + '</button>'
            + '<button type="button" data-first="them"' + (runout.first === 'them' ? ' class="active"' : '') + '>' + t('deckbuilder.statstab.them') + '</button></span>'
            + '</div>'
            + '<div class="rb-runout-row"><span class="rb-runout-who" style="color:' + YOU + '">' + t('deckbuilder.statstab.your_moves') + '</span>' + pattern_html('you') + '</div>'
            + '<div class="rb-runout-row"><span class="rb-runout-who" style="color:' + THEM + '">' + t('deckbuilder.statstab.their_moves') + '</span>' + pattern_html('them') + '</div>');
    }

    function chart_runout(your_deck)
    {
        var result = simulate(your_deck),
            series = [
                {name: t('deckbuilder.statstab.you'), color: YOU, data: result.you.points},
                {name: t('deckbuilder.statstab.them'), color: THEM, data: result.them.points, dashStyle: 'ShortDash'}
            ];
        $('#stats-chart-runout').highcharts({
            chart: {type: 'line', height: 300, backgroundColor: 'transparent', style: {fontFamily: 'inherit'}},
            title: {text: null},
            credits: {enabled: false},
            xAxis: {min: 1, max: 9, tickInterval: 1, title: {text: t('deckbuilder.statstab.site')}},
            yAxis: {min: 0, allowDecimals: false, title: {text: t('deckbuilder.statstab.cards_left')}},
            tooltip: {formatter: function () { return '<b>' + this.series.name + '</b><br>' + t('deckbuilder.statstab.site') + ' ' + this.x + ': ' + this.y; }},
            plotOptions: {series: {animation: false, marker: {radius: 3}, step: false}},
            series: series
        });
        var say = function (who, p) {
            var end = p.points[p.points.length - 1];
            return p.deck <= 0
                ? t('deckbuilder.statstab.runs_out', {who: who, site: _.find(p.points, function (pt) { return pt[1] === 0; })[0]})
                : t('deckbuilder.statstab.cards_at', {who: who, count: end[1], site: end[0]});
        };
        $('#runout-summary').text(say(t('deckbuilder.statstab.you'), result.you) + ' ' + say(t('deckbuilder.statstab.them'), result.them));
    }

    /* ---- refresh ---- */

    stats.refresh = function refresh()
    {
        if(!visible() || typeof $.fn.highcharts !== 'function') {
            return;
        }
        var draw = app.deck.get_draw_deck(),
            pile = draw_pile().length;
        chart_twilight(draw);
        chart_types(draw);
        chart_cultures(draw);
        render_runout_inputs(pile);
        chart_runout(pile);
        if(!hand.length && !drawn) {
            reset();
            render_hand();
        }
    };

    stats.setup = function setup()
    {
        var root = $('#deck-stats');
        root.on('click', '[data-draw]', function () { stats.draw($(this).data('draw') + ''); });
        root.on('click', '[data-turn]', function () {
            var who = $(this).closest('[data-pattern]').data('pattern'),
                tokens = runout[who].slice();
            tokens[$(this).data('turn')] = $(this).data('step');
            runout[who] = normalize(tokens);
            stats.refresh();
        });
        root.on('click', '[data-first]', function () {
            runout.first = $(this).data('first');
            stats.refresh();
        });
        root.on('change', '[data-runout]', function () {
            var value = parseInt($(this).val(), 10);
            runout[$(this).data('runout')] = isNaN(value) ? null : value;
            stats.refresh();
        });
        $('a[href="#tab-pane-stats"]').on('shown.bs.tab', function () {
            stats.refresh();
        });
        $(document).on('deck.changed', _.debounce(function () {
            // a different deck: earlier test draws no longer apply
            hand = [];
            drawn = 0;
            sorted_count = 0;
            stats.refresh();
        }, 400));
    };

})(app.deck_stats = {}, jQuery);
