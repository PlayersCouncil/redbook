/* global app, _, Translator */
/**
 * Deck charts (phase3/03): twilight curve split Free Peoples / Shadow, cultures,
 * card types, companion strength and vitality. Draw deck only.
 */
(function app_deck_charts(deck_charts, $)
{
    var SIDE_COLORS = {free_peoples: '#3a7bbf', shadow: '#8b1a1a'};

    function column(container, title, categories, series, stacked)
    {
        $(container).highcharts({
            chart: {type: 'column'},
            title: {text: title},
            credits: {enabled: false},
            xAxis: {categories: categories},
            yAxis: {title: {text: null}, allowDecimals: false, min: 0},
            legend: {enabled: series.length > 1},
            plotOptions: {column: {stacking: stacked ? 'normal' : null, borderWidth: 0, groupPadding: 0.1}},
            tooltip: {shared: true},
            series: series
        });
    }

    deck_charts.chart_twilight = function chart_twilight()
    {
        var draw = app.deck.get_draw_deck(),
            max = _.max([0].concat(_.map(draw, function (c) { return c.twilight || 0; }))),
            categories = _.range(0, max + 1),
            series = _.map(['free_peoples', 'shadow'], function (side) {
                var counts = _.map(categories, function () { return 0; });
                _.each(draw, function (card) {
                    if((card.side || 'free_peoples') === side && card.twilight !== null && card.twilight !== undefined) {
                        counts[card.twilight] += card.indeck;
                    }
                });
                return {name: Translator.trans('side.' + side), color: SIDE_COLORS[side], data: counts};
            });
        column('#deck-chart-twilight', Translator.trans('decks.charts.twilight'), categories, series, true);
    };

    deck_charts.chart_culture = function chart_culture()
    {
        var cultures = app.deck.get_cultures();
        column('#deck-chart-culture', Translator.trans('decks.charts.cultures'),
            _.pluck(cultures, 'name'),
            [{name: Translator.trans('decks.charts.cards'), color: '#8a6d3b', data: _.pluck(cultures, 'count')}]);
    };

    deck_charts.chart_type = function chart_type()
    {
        var types = {};
        _.each(app.deck.get_draw_deck(), function (card) {
            var side = card.side || 'free_peoples';
            types[card.type_name] = types[card.type_name] || {free_peoples: 0, shadow: 0};
            types[card.type_name][side] += card.indeck;
        });
        var names = _.keys(types).sort();
        column('#deck-chart-type', Translator.trans('decks.charts.types'), names, _.map(['free_peoples', 'shadow'], function (side) {
            return {name: Translator.trans('side.' + side), color: SIDE_COLORS[side], data: _.map(names, function (n) { return types[n][side]; })};
        }), true);
    };

    deck_charts.chart_companions = function chart_companions()
    {
        var companions = _.filter(app.deck.get_draw_deck(), function (card) { return card.type_code === 'companion'; });
        _.each(app.deck.get_cards('ring_bearer'), function (slot) {
            companions.unshift(_.extend({}, slot.card, {indeck: 1}));
        });
        column('#deck-chart-companions', Translator.trans('decks.charts.companions'),
            _.map(companions, function (c) { return c.name; }),
            [
                {name: Translator.trans('card.info.strength'), color: '#c9302c', data: _.map(companions, function (c) { return c.strength || 0; })},
                {name: Translator.trans('card.info.vitality'), color: '#5cb85c', data: _.map(companions, function (c) { return c.vitality || 0; })}
            ]);
    };

    deck_charts.setup = function setup()
    {
        if(!$('#deck-chart-twilight').length) {
            return;
        }
        deck_charts.chart_twilight();
        deck_charts.chart_culture();
        deck_charts.chart_type();
        deck_charts.chart_companions();
    };

})(app.deck_charts = {}, jQuery);
