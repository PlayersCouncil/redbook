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
 * Charts, one per row, with Free Peoples left of zero and Shadow right of it on the same
 * line (never side by side), the axis reaching just past the longest bar both ways:
 * twilight cost and card types. Free Peoples are light tan, Shadow a mid purple. The
 * type chart folds companions, allies and minions into Characters and marks half the
 * Shadow side: at least half of it should be minions. Pointing at a row lights the whole
 * row and shows each of its bars' counts (Ketura, 2026-11-08). The cultures are a pie:
 * Free Peoples the left half, Shadow the right, each culture in its card colour.
 *
 * The run-out chart estimates how many cards each player has left in their draw deck
 * site by site, from movement patterns (S single move, D double move) and how many
 * cards each plays per turn (Free Peoples) and per move (Shadow). The model: both
 * draw 8; the Free Peoples player plays their cards once a turn, the Shadow player
 * once per move, ramping up to the full number by site 4 (half at site 2); when the
 * fellowship stays, both reconcile (draw back up to 8); when it moves again only the
 * Shadow player does. No discards, no card draw from effects.
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
        runout = {fp_play: 3, shadow_play: 4, you: 'DSDSD'.split(''), them: 'DSDSD'.split(''), first: 'you', their_deck: null},
        RUNOUT_KEY = 'stats-runout';

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

    function log_choose(n, k)
    {
        if(k < 0 || k > n) {
            return -Infinity;
        }
        var sum = 0;
        for(var i = 1; i <= k; i++) {
            sum += Math.log(n - k + i) - Math.log(i);
        }
        return sum;
    }

    /**
     * The chance of drawing exactly these cards, in any order (Ketura, 2026-11-03): with
     * K copies of a card in the pile and k of them in hand, it's the product of C(K, k)
     * over the cards drawn, out of C(pile, hand size)
     */
    function hand_chance()
    {
        var size = library.length + drawn;
        if(!hand.length || !size) {
            return null;
        }
        var held = _.countBy(hand, 'code'),
            pile = _.countBy(draw_pile(), 'code'),
            log_p = -log_choose(size, hand.length);
        _.each(held, function (k, code) {
            log_p += log_choose(pile[code] || k, k);
        });
        return Math.exp(log_p);
    }

    function render_chance()
    {
        var p = hand_chance();
        if(p === null) {
            $('#draw-chance').empty();
            return;
        }
        var percent = p * 100,
            one_in = Math.round(1 / p).toLocaleString();
        // tiny chances read better as "1 in 111,591,867" than as a percentage
        $('#draw-chance').html('<span class="rb-odds-label">' + t('deckbuilder.statstab.hand_chance') + '</span> '
            + (percent >= 0.01
                ? '<strong>' + (percent >= 1 ? percent.toFixed(1) : percent.toPrecision(2)) + '%</strong> <span class="rb-hint">' + t('deckbuilder.statstab.one_in', {count: one_in}) + '</span>'
                : '<strong>' + t('deckbuilder.statstab.one_in_short', {count: one_in}) + '</strong>'));
    }

    function render_odds()
    {
        render_chance();
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

    /**
     * A split chart's value axis: zero in the middle and both sides reaching just past
     * the longest bar, so no side is padded out to the next round number (Ketura, 2026-11-03)
     */
    function split_axis(values, extra)
    {
        var top = _.max([1].concat(_.map(values, Math.abs))),
            edge = top + Math.max(1, Math.ceil(top * 0.04));
        return _.extend({title: {text: null}, min: -edge, max: edge, startOnTick: false, endOnTick: false, allowDecimals: false,
            tickPositioner: function () {
                var step = edge > 30 ? 10 : edge > 12 ? 5 : edge > 6 ? 2 : 1,
                    ticks = [];
                for(var v = -Math.floor(edge / step) * step; v <= edge; v += step) {
                    ticks.push(v);
                }
                return ticks;
            },
            labels: {formatter: abs_label}, plotLines: [{value: 0, width: 1, color: '#999', zIndex: 4}]}, extra || {});
    }

    /** the sums of each row's stack, both sides, for split_axis */
    function stack_totals(series)
    {
        var fp = [], shadow = [];
        _.each(series, function (s) {
            _.each(s.data, function (v, i) {
                v = v && typeof v === 'object' ? v.y : v;
                var list = v < 0 ? fp : shadow;
                list[i] = (list[i] || 0) + v;
            });
        });
        return _.compact(fp.concat(shadow));
    }

    function base(type, height)
    {
        return {
            chart: {type: type, height: height, backgroundColor: 'transparent', style: {fontFamily: 'inherit'}, spacingLeft: 0, spacingRight: 2},
            title: {text: null},
            credits: {enabled: false},
            legend: {enabled: true, itemStyle: {fontWeight: 'normal'}},
            // one stack per row: Free Peoples to the left of zero, Shadow to the right, on the same line
            // no tooltip on the bar charts: the hovered bar shows its count, centred on it (Ketura, 2026-11-03)
            tooltip: {enabled: false},
            plotOptions: {series: {stacking: 'normal', borderWidth: 0, groupPadding: 0.1, pointPadding: 0.04, animation: false,
                states: {hover: {enabled: false}, inactive: {enabled: false}}, enableMouseTracking: false}}
        };
    }

    /**
     * A bar chart's rows light up under the pointer, the whole row, with each bar in it showing
     * its count on its middle (Ketura, 2026-11-08). The row is read off the pointer's height.
     */
    function row_hover(chart)
    {
        var axis = chart.xAxis[0],
            current = null,
            labels = [];
        function clear()
        {
            _.each(labels, function (label) { label.destroy(); });
            labels = [];
            axis.removePlotBand('rb-row');
            current = null;
        }
        $(chart.container).on('mousemove', function (event) {
            var e = {chartY: event.pageY - $(chart.container).offset().top};
            if(e.chartY < chart.plotTop || e.chartY > chart.plotTop + chart.plotHeight) {
                clear();
                return;
            }
            var row = Math.round(axis.toValue(e.chartY));
            if(row === current || row < axis.min || row > axis.max) {
                return;
            }
            clear();
            current = row;
            axis.addPlotBand({id: 'rb-row', from: row - 0.5, to: row + 0.5, color: 'rgba(246, 222, 150, .55)', zIndex: 0});
            var box = chart.container.getBoundingClientRect();
            _.each(chart.series, function (series) {
                var point = series.points && series.points[row];
                if(!point || !point.y || !point.graphic) {
                    return;
                }
                var bar = point.graphic.element.getBoundingClientRect();
                labels.push(chart.renderer.text(String(Math.abs(point.y)), bar.left - box.left + bar.width / 2, bar.top - box.top + bar.height / 2 + 4)
                    .attr({align: 'center', zIndex: 9, 'class': 'rb-bar-count'})
                    .css({fontWeight: '700', fontSize: '12px', color: '#1E1B16', pointerEvents: 'none'})
                    .add());
            });
        }).on('mouseleave', clear);
    }

    /** a bar chart, with its row hover */
    function bar_chart(selector, options)
    {
        $(selector).highcharts(options, row_hover);
    }

    /**
     * Twilight cost. A run of two or more costs no card has, below a Balrog's or Sauron's, is
     * one row of "…" (Ketura, 2026-11-08)
     */
    function chart_twilight(draw)
    {
        var max = _.max([0].concat(_.map(draw, function (c) { return c.twilight || 0; }))),
            at = function (side, cost) {
                return _.reduce(draw, function (sum, c) { return sum + ((c.side === 'shadow') === (side === 'shadow') && (c.twilight || 0) === cost ? c.indeck : 0); }, 0);
            },
            rows = [];
        for(var cost = 0; cost <= max; cost++) {
            var fp = at('free_peoples', cost), sh = at('shadow', cost);
            if(!fp && !sh) {
                var end = cost;
                while(end + 1 <= max && !at('free_peoples', end + 1) && !at('shadow', end + 1)) {
                    end++;
                }
                if(end > cost) {
                    rows.push({label: '…', fp: 0, shadow: 0});
                    cost = end;
                    continue;
                }
            }
            rows.push({label: String(cost), fp: fp, shadow: sh});
        }
        var series = [
                {name: t('side.free_peoples'), color: FP, data: _.map(rows, function (r) { return -r.fp; })},
                {name: t('side.shadow'), color: SHADOW, data: _.map(rows, function (r) { return r.shadow; })}
            ];
        bar_chart('#stats-chart-twilight', _.extend(base('bar', 70 + rows.length * 26), {
            xAxis: {categories: _.pluck(rows, 'label'), title: {text: t('deckbuilder.statstab.twilight_axis')}, reversed: true},
            yAxis: split_axis(stack_totals(series)),
            series: series
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
            values = function (field, negative) { return _.map(keys, function (key) { return negative ? -rows[key][field] : rows[key][field]; }); },
            half = totals.shadow / 2,
            series = [
                // companions match the other Free Peoples cards; allies are the odd one out (Ketura, 2026-11-03)
                {name: t('deckbuilder.statstab.companions'), color: '#C4AC78', data: values('companion', true)},
                {name: t('deckbuilder.statstab.allies'), color: FP, data: values('ally', true)},
                {name: t('deckbuilder.statstab.other_fp'), color: '#C4AC78', data: values('fp', true), showInLegend: false},
                {name: t('deckbuilder.statstab.minions'), color: SHADOW, data: values('minion', false)},
                {name: t('deckbuilder.statstab.other_shadow'), color: '#A88DC0', data: values('shadow', false), showInLegend: false}
            ];
        bar_chart('#stats-chart-types', _.extend(base('bar', 60 + keys.length * 40), {
            xAxis: {categories: _.map(keys, label), title: {text: null}},
            // the dotted line: at least half of the Shadow side should be minions (Ketura, 2026-11-03)
            yAxis: split_axis(stack_totals(series).concat([half]), half ? {plotLines: [
                {value: 0, width: 1, color: '#999', zIndex: 4},
                {value: half, width: 2, color: '#4B2F66', dashStyle: 'Dot', zIndex: 5}
            ]} : {}),
            series: series
        }));
    }

    /**
     * Cultures (Ketura, 2026-11-08): a pie in two halves, Free Peoples always the left and
     * Shadow the right, each half shared among its side's cultures, the biggest at the top on
     * both sides, in each culture's card colour (base 1). As wide as the column allows. Each
     * slice carries its culture's symbol, in the symbol's colour, over its count (a slice too
     * thin for them goes without); pointing at a slice writes its culture's name above or
     * below the pie, with a line to a thin slice. A culture on both sides (Gollum) is in both.
     */
    function chart_cultures(draw)
    {
        var cultures = {};
        _.each(draw, function (c) {
            var code = c.culture_code || 'none';
            cultures[code] = cultures[code] || {code: code, name: c.culture_name || t('deck.no_culture'), fp: 0, shadow: 0};
            cultures[code][c.side === 'shadow' ? 'shadow' : 'fp'] += c.indeck;
        });
        var all = _.values(cultures),
            box = $('#stats-chart-cultures'),
            width = Math.max(200, box.width()),
            band = 28,
            size = width - 10,
            THIN = 0.22,
            // a half runs clockwise: Shadow from the top down, Free Peoples from the bottom up
            slices = function (side) {
                return _.map(_.sortBy(_.filter(all, function (c) { return c[side]; }), function (c) { return side === 'shadow' ? -c[side] : c[side]; }), function (c) {
                    return {name: c.name, y: c[side], code: c.code, color: app.cultures.color(c.code, 'base1')};
                });
            },
            thin = function (point) {
                return !point.shapeArgs || point.shapeArgs.end - point.shapeArgs.start < THIN;
            },
            hover = null,
            show_name = function () {
                var point = this, chart = point.series.chart, center = point.series.center,
                    r = center[2] / 2, a = point.angle,
                    cx = chart.plotLeft + center[0], cy = chart.plotTop + center[1],
                    up = Math.sin(a) < 0,
                    x = cx + Math.cos(a) * r,
                    y = up ? cy - r - 10 : cy + r + 20,
                    align = x < 70 ? 'left' : (x > chart.chartWidth - 70 ? 'right' : 'center'),
                    tx = align === 'left' ? 4 : (align === 'right' ? chart.chartWidth - 4 : x);
                hide_name();
                hover = chart.renderer.g().attr({zIndex: 10}).add();
                if(thin(point)) {
                    chart.renderer.path(['M', cx + Math.cos(a) * (r + 2), cy + Math.sin(a) * (r + 2), 'L', x, up ? y + 4 : y - 14])
                        .attr({stroke: '#5A544A', 'stroke-width': 1}).add(hover);
                }
                chart.renderer.text(_.escape(point.name) + (thin(point) ? ' · ' + point.y : ''), tx, y)
                    .attr({align: align}).css({fontSize: '13px', fontWeight: '700', color: '#2A261F'}).add(hover);
            },
            hide_name = function () {
                if(hover) {
                    hover.destroy();
                    hover = null;
                }
            },
            half = function (name, data, start) {
                return {name: name, data: data, startAngle: start, endAngle: start + 180, center: ['50%', '50%'], size: size,
                    point: {events: {mouseOver: show_name, mouseOut: hide_name}},
                    dataLabels: {enabled: true, useHTML: true, distance: -Math.round(size * 0.2), crop: false, overflow: 'none',
                        formatter: function () {
                            if(thin(this.point)) {
                                return null;
                            }
                            var code = this.point.code, file = code === 'uruk_hai' ? 'uruk' : code,
                                icon = code === 'none' ? '' : '<span class="rb-pie-icon" style="--icon:url(/images/cultures/' + file + '.svg)"></span>';
                            return '<span class="rb-pie-label" style="color:' + app.cultures.color(code, 'symbol') + '">' + icon + '<span class="rb-pie-count">' + this.y + '</span></span>';
                        }}};
            };
        box.highcharts({
            chart: {type: 'pie', width: width, height: size + band * 2, backgroundColor: 'transparent', style: {fontFamily: 'inherit'}, spacing: [band, 0, band, 0],
                events: {load: function () { $(this.container).on('mouseleave', hide_name); }}},
            title: {text: null},
            credits: {enabled: false},
            legend: {enabled: false},
            tooltip: {enabled: false},
            plotOptions: {pie: {animation: false, borderColor: '#fff', borderWidth: 1, states: {hover: {brightness: 0.06, halo: false}}}},
            series: [
                // the Shadow half from the top round to the bottom on the right; Free Peoples from the bottom round to the top on the left
                half(t('side.shadow'), slices('shadow'), 0),
                half(t('side.free_peoples'), slices('fp'), 180)
            ]
        });
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

    /**
     * Shadow ramps up (Ketura, 2026-11-03): the full number from site 4 on, about half at
     * site 2, three quarters at site 3, as the twilight pool grows
     */
    function shadow_plays(site)
    {
        return Math.round(runout.shadow_play * Math.min(1, site / 4));
    }

    function simulate(your_deck)
    {
        var players = {
                you: {deck: your_deck - 8, hand: 8, site: 1, pattern: runout.you.slice(), turn: 0, points: [[1, Math.max(0, your_deck - 8)]]},
                them: {deck: (runout.their_deck || your_deck) - 8, hand: 8, site: 1, pattern: runout.them.slice(), turn: 0, points: [[1, Math.max(0, (runout.their_deck || your_deck) - 8)]]}
            },
            play = function (p, n) { p.hand = Math.max(0, p.hand - n); },
            // the site where a deck first runs dry, as the Free Peoples or the Shadow player
            reconcile = function (p, site) {
                var want = 8 - p.hand,
                    got = Math.min(want, Math.max(0, p.deck));
                p.deck -= got;
                p.hand += got;
                if(p.deck <= 0 && !p.out) {
                    p.out = site;
                }
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
                play(shadow, shadow_plays(fp.site));
                if(m < moves) {
                    // moving again: only the Shadow player reconciles
                    reconcile(shadow, fp.site);
                } else {
                    reconcile(fp, fp.site);
                    reconcile(shadow, fp.site);
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
            + '<div class="rb-runout-row rb-runout-moves"><span class="rb-runout-who" style="color:' + YOU + '">' + t('deckbuilder.statstab.your_moves') + '</span>' + pattern_html('you') + '</div>'
            + '<div class="rb-runout-row rb-runout-moves"><span class="rb-runout-who" style="color:' + THEM + '">' + t('deckbuilder.statstab.their_moves') + '</span>' + pattern_html('them') + '</div>');
    }

    /**
     * The run-out chart. Each line ends with a grey guide back to the axis saying how many
     * cards remain; when the two ends are close, yours is the one labelled and drawn on top.
     */
    function chart_runout(your_deck)
    {
        var result = simulate(your_deck),
            you_end = _.last(result.you.points),
            them_end = _.last(result.them.points),
            top = Math.max(result.you.points[0][1], result.them.points[0][1], 1),
            // the two cliffs' labels would meet: yours is the one labelled
            close = Math.abs(you_end[0] - them_end[0]) <= 2 && Math.abs(you_end[1] - them_end[1]) < top * 0.12,
            /**
             * The cliff (Ketura, 2026-11-03): from the final dot straight down to the site axis,
             * saying how many cards that player is left holding in the draw deck, unused
             */
            guide = function (end, color, labelled, z) {
                if(!end[1]) {
                    return null;
                }
                return {
                    type: 'line', data: [{x: end[0], y: end[1]}, {x: end[0], y: end[1] / 2, dataLabels: {enabled: labelled}}, {x: end[0], y: 0}],
                    color: '#8A8A8A', lineWidth: 2, dashStyle: 'Dash', marker: {enabled: false}, enableMouseTracking: false,
                    showInLegend: false, zIndex: z,
                    dataLabels: {enabled: false, align: 'right', verticalAlign: 'middle', x: -8, y: 0, crop: false, overflow: 'none',
                        style: {color: color, fontWeight: '700', fontSize: '12px', textShadow: '0 0 3px #fff, 0 0 2px #fff'},
                        formatter: function () { return Translator.transChoice('deckbuilder.statstab.cards_remain', end[1], {count: end[1]}); }}
                };
            },
            series = _.compact([
                guide(them_end, THEM, !close, 1),
                guide(you_end, YOU, true, 2),
                {name: t('deckbuilder.statstab.them'), color: THEM, data: result.them.points, dashStyle: 'ShortDash', zIndex: 3},
                {name: t('deckbuilder.statstab.you'), color: YOU, data: result.you.points, zIndex: 4, lineWidth: 3}
            ]);
        $('#stats-chart-runout').highcharts({
            chart: {type: 'line', height: 300, backgroundColor: 'transparent', style: {fontFamily: 'inherit'}, spacingLeft: 0, spacingRight: 6},
            title: {text: null},
            credits: {enabled: false},
            legend: {reversed: true},
            xAxis: {min: 1, max: 9, tickInterval: 1, title: {text: t('deckbuilder.statstab.site')}},
            yAxis: {min: 0, allowDecimals: false, title: {text: t('deckbuilder.statstab.cards_left')}},
            tooltip: {formatter: function () { return '<b>' + this.series.name + '</b><br>' + t('deckbuilder.statstab.site') + ' ' + this.x + ': ' + this.y; }},
            plotOptions: {series: {animation: false, marker: {radius: 3}, step: false}},
            series: series
        });
    }

    /** The run-out settings are kept in this browser, so a setup someone likes stays (Ketura, 2026-11-03) */
    function load_runout()
    {
        var saved = app.config && app.config.get(RUNOUT_KEY);
        if(!saved || typeof saved !== 'object') {
            return;
        }
        _.each(['fp_play', 'shadow_play', 'their_deck'], function (key) {
            if(saved[key] === null || (typeof saved[key] === 'number' && saved[key] >= 0 && saved[key] <= 200)) {
                runout[key] = saved[key];
            }
        });
        _.each(['you', 'them'], function (who) {
            if(typeof saved[who] === 'string' && /^[SD]+$/.test(saved[who])) {
                runout[who] = normalize(saved[who].split(''));
            }
        });
        if(saved.first === 'you' || saved.first === 'them') {
            runout.first = saved.first;
        }
    }

    function save_runout()
    {
        if(app.config) {
            app.config.set(RUNOUT_KEY, {fp_play: runout.fp_play, shadow_play: runout.shadow_play, their_deck: runout.their_deck,
                you: runout.you.join(''), them: runout.them.join(''), first: runout.first});
        }
    }

    /** Redraws the run-out part only, keeping the pane where it was scrolled to (Ketura, 2026-11-03) */
    function refresh_runout()
    {
        save_runout();
        var pane = $('#deck-stats').parents().filter(function () { return this.scrollHeight > this.clientHeight && /(auto|scroll)/.test($(this).css('overflow-y')); }).first(),
            scrolled = pane.scrollTop(),
            focused = document.activeElement && $(document.activeElement).closest('#runout-inputs').length
                ? $(document.activeElement).attr('data-runout') || null : null,
            pile = draw_pile().length;
        render_runout_inputs(pile);
        chart_runout(pile);
        pane.scrollTop(scrolled);
        if(focused) {
            $('#runout-inputs [data-runout="' + focused + '"]').focus();
        }
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
        load_runout();
        root.on('click', '[data-draw]', function () { stats.draw($(this).data('draw') + ''); });
        root.on('click', '[data-turn]', function () {
            var who = $(this).closest('[data-pattern]').data('pattern'),
                tokens = runout[who].slice();
            tokens[$(this).data('turn')] = $(this).data('step');
            runout[who] = normalize(tokens);
            refresh_runout();
        });
        root.on('click', '[data-first]', function () {
            runout.first = $(this).data('first');
            refresh_runout();
        });
        root.on('change', '[data-runout]', function () {
            var value = parseInt($(this).val(), 10);
            runout[$(this).data('runout')] = isNaN(value) ? null : value;
            refresh_runout();
        });
        $('a[href="#tab-pane-stats"]').on('shown.bs.tab', function () {
            stats.refresh();
        });
        // the pie is sized to its column: drawn again when that changes
        $(window).on('resize', _.debounce(function () {
            if(visible() && typeof $.fn.highcharts === 'function') {
                chart_cultures(app.deck.get_draw_deck());
            }
        }, 200));
        $(document).on('deck.changed', _.debounce(function () {
            // a different deck: earlier test draws no longer apply
            hand = [];
            drawn = 0;
            sorted_count = 0;
            stats.refresh();
        }, 400));
    };

})(app.deck_stats = {}, jQuery);
