/* global app, _, moment, Translator, Routing */
/**
 * The History tab (Ketura, 2026-11-03): one timeline, newest first, of the deck's saved
 * revisions (with what changed, and a Restore) and its published versions (with their
 * likes, favourites and comments, and the edits to their details, 2.0.1). The revisions
 * since the last publication sit under a heading saying they're private (2026-11-08). An archive deck's first version reads "Imported from
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
        /*
         * What else the save changed (Ketura, 2026-11-08); a historian's correction says who,
         * and whether the archive decklist took it; one carried over from the decklist says so
         */
        var edit = '', edit_info = snapshot.edit;
        if(edit_info) {
            var parts = _.map(_.without(edit_info.changed || [], 'cards'), function (part) { return t('deckbuilder.history.part.' + part); }),
                what = parts.length ? _.escape(parts.join(', ')) : '';
            if(snapshot.editor) {
                edit = '<div class="rb-history-edit"><span class="fas fa-user-edit" aria-hidden="true"></span> '
                    + t('deckbuilder.history.edited_by', {name: _.escape(snapshot.editor)}) + (what ? ': ' + what : '')
                    + (edit_info.synced ? ' <span class="label label-success">' + t('deckbuilder.history.synced') + '</span>' : '')
                    + '</div>';
            } else if(edit_info.from) {
                edit = '<div class="rb-history-edit">' + t('deckbuilder.history.from_decklist', {version: _.escape(edit_info.from)}) + (what ? ': ' + what : '') + '</div>';
            } else if(what) {
                edit = '<div class="rb-history-changed">' + t('deckbuilder.history.changed', {parts: what}) + '</div>';
            }
            if(!_.contains(edit_info.changed || [], 'cards')) {
                list = [];
            }
        }
        return '<tr class="rb-history-saved' + (snapshot.editor ? ' is-edit' : '') + '"><td>' + snapshot.when.calendar() + '</td><td>' + _.escape(snapshot.version || '') + '</td><td>' + edit
            + (list.length ? list.join('<br>') : (edit ? '' : '<span class="text-muted">' + t('deckbuilder.history.no_card_changes') + '</span>'))
            + '</td><td><a role="button" href="#" data-index="' + index + '">' + t('decks.history.revert') + '</a></td></tr>';
    }

    function published_row(p)
    {
        return '<tr class="rb-history-published"><td>' + moment(p.date_creation).calendar() + '</td><td>' + _.escape(p.version || '') + '</td><td>'
            + '<span class="fas fa-share" aria-hidden="true"></span> ' + t('deckbuilder.history.published') + ' <a href="' + p.url + '">' + _.escape(p.name) + '</a>'
            + (p.review === 'unverified' ? ' <span class="label label-warning">' + t('decklist.review.unverified') + '</span>' : '')
            + '<span class="rb-history-social">'
            + '<span title="' + t('deckbuilder.history.likes') + '"><span class="fas fa-heart social-icon-like"></span> ' + (p.likes || 0) + '</span>'
            + '<span title="' + t('deckbuilder.history.favorites') + '"><span class="fas fa-star social-icon-favorite"></span> ' + (p.favorites || 0) + '</span>'
            + '<span title="' + t('deckbuilder.history.comments') + '"><span class="fas fa-comment social-icon-comment"></span> ' + (p.comments || 0) + '</span>'
            + '</span></td><td></td></tr>';
    }

    /** An edit to a published decklist's details: 2.0.1 */
    function patch_row(e)
    {
        var parts = _.map(e.changed || [], function (part) { return t('deckbuilder.history.part.' + part); });
        return '<tr class="rb-history-published rb-history-patch"><td>' + moment(e.date_creation).calendar() + '</td><td>' + _.escape(e.version) + '</td><td>'
            + t('deckbuilder.history.patch') + (parts.length ? ': ' + _.escape(parts.join(', ')) : '') + '</td><td></td></tr>';
    }

    /** Above the revisions since the last publication: they're private until the next one */
    function group_row(page)
    {
        var text = page.next_publication
            ? t('deckbuilder.history.unpublished', {version: page.next_publication})
            : t('deckbuilder.history.unpublished_first');
        return '<tr class="rb-history-group"><td colspan="4"><span class="fas fa-lock" aria-hidden="true"></span> ' + _.escape(text) + '</td></tr>';
    }

    deck_history.render = function render()
    {
        if(!tbody || !app.data.cards) {
            return;
        }
        var page = app.deckbuilder_page || {},
            published = page.published_versions || [],
            // a publication's detail edits (2.0.1) right under it, newest first, rather than
            // among the private revisions made since
            rows = _.map(snapshots, function (s, i) { return {when: s.when, html: saved_row(s, i, page), order: 0, saved: !!s.variation || !!s.edit}; })
                .concat(_.map(published, function (p) {
                    var edits = _.sortBy(p.edits || [], function (e) { return -moment(e.date_creation).valueOf(); });
                    return {when: moment(p.date_creation), html: published_row(p) + _.map(edits, patch_row).join(''), order: 1};
                }));
        // newest first; a version published at the moment it was saved sits above that save
        rows.sort(function (a, b) { return b.when.valueOf() - a.when.valueOf() || b.order - a.order; });
        // the revisions since the last publication, under a heading of their own (Ketura, 2026-11-08)
        var first_published = _.findIndex(rows, function (r) { return r.order === 1; }),
            unpublished = first_published === -1 ? rows.length : first_published;
        if(_.some(rows.slice(0, unpublished), function (r) { return r.order === 0 && r.saved; })) {
            rows.unshift({html: group_row(page)});
        }
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
        tbody = $(container).find('tbody').off('click').on('click', 'a[role=button]', deck_history.load_snapshot);
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
