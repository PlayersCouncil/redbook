/* global app, _, moment, Translator, Routing */
/**
 * The History tab (Ketura, 2026-11-03): one timeline, newest first, of the deck's saved
 * versions (with what changed, and a Restore) and its published versions (with their
 * likes, favourites and comments). An archive deck's first version reads "Imported from
 * archive". Unsaved work is a draft in the browser, so it isn't listed here.
 */
(function app_deck_history(deck_history, $)
{
    var tbody,
        snapshots_init = [],
        snapshots = [];

    function t(key, params)
    {
        return Translator.trans(key, params || {});
    }

    function card_link(key, qty, sign)
    {
        var is_rb = key.indexOf('rb:') === 0,
            code = is_rb ? key.substr(3) : key,
            card = app.data.cards && app.data.cards.findById(code);
        if(!card) {
            return null;
        }
        return sign + ' ' + (is_rb ? t('deck.ring_bearer') + ':' : qty) + ' <a href="' + card.url + '" class="card-tip" data-code="' + code + '">' + _.escape(card.label) + '</a>';
    }

    function saved_row(snapshot, index, page)
    {
        var list = [];
        if(snapshot.variation) {
            _.each(snapshot.variation[0], function (qty, key) { list.push(card_link(key, qty, '+')); });
            _.each(snapshot.variation[1], function (qty, key) { list.push(card_link(key, qty, '&minus;')); });
            list = _.compact(list);
        } else {
            list.push(t(page.imported_from_archive ? 'deckbuilder.history.imported' : 'decks.history.firstversion'));
        }
        return '<tr class="rb-history-saved"><td>' + snapshot.when.calendar() + '</td><td>' + _.escape(snapshot.version || '') + '</td><td>'
            + (list.length ? list.join('<br>') : '<span class="text-muted">' + t('deckbuilder.history.no_card_changes') + '</span>')
            + '</td><td><a role="button" href="#" data-index="' + index + '">' + t('decks.history.revert') + '</a></td></tr>';
    }

    function published_row(p)
    {
        return '<tr class="rb-history-published"><td>' + moment(p.date_creation).calendar() + '</td><td>' + (p.version ? 'v' + p.version : '') + '</td><td>'
            + '<span class="fas fa-share" aria-hidden="true"></span> ' + t('deckbuilder.history.published') + ' <a href="' + p.url + '">' + _.escape(p.name) + '</a>'
            + (p.review === 'unverified' ? ' <span class="label label-warning">' + t('decklist.review.unverified') + '</span>' : '')
            + '<span class="rb-history-social">'
            + '<span title="' + t('deckbuilder.history.likes') + '"><span class="fas fa-heart social-icon-like"></span> ' + (p.likes || 0) + '</span>'
            + '<span title="' + t('deckbuilder.history.favorites') + '"><span class="fas fa-star social-icon-favorite"></span> ' + (p.favorites || 0) + '</span>'
            + '<span title="' + t('deckbuilder.history.comments') + '"><span class="fas fa-comment social-icon-comment"></span> ' + (p.comments || 0) + '</span>'
            + '</span></td><td></td></tr>';
    }

    deck_history.render = function render()
    {
        if(!tbody || !app.data.cards) {
            return;
        }
        var page = app.deckbuilder_page || {},
            rows = _.map(snapshots, function (s, i) { return {when: s.when, html: saved_row(s, i, page), order: 0}; })
                .concat(_.map(page.published_versions || [], function (p) { return {when: moment(p.date_creation), html: published_row(p), order: 1}; }));
        // newest first; a version published at the moment it was saved sits above that save
        rows.sort(function (a, b) { return b.when.valueOf() - a.when.valueOf() || b.order - a.order; });
        tbody.html(_.pluck(rows, 'html').join(''));
    };

    deck_history.load_snapshot = function load_snapshot()
    {
        var snapshot = snapshots[$(this).data('index')];
        if(snapshot && snapshot.content) {
            app.deck.set_content(snapshot.content);
        }
        return false;
    };

    deck_history.init = function init(data)
    {
        snapshots_init = data || [];
    };

    deck_history.setup = function setup(container)
    {
        tbody = $(container).find('tbody').on('click', 'a[role=button]', deck_history.load_snapshot);
        snapshots = _.map(_.filter(snapshots_init, function (s) { return s.is_saved !== false; }), function (s) {
            return _.extend({}, s, {when: s.date_creation ? moment(s.date_creation) : moment()});
        });
        // the rows name cards, so they are drawn once the card data is in (ui.on_all_loaded)
    };

    // kept for callers of the old module
    deck_history.notify_change = function () {};
    deck_history.get_unsaved_edits = function () { return []; };
    deck_history.is_changed_since_last_autosave = function () { return false; };

})(app.deck_history = {}, jQuery);
