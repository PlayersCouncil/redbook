/* global app, Translator, Routing, _ */

/*
 * The decklists page's actions (Ketura, 2026-11-11), beside its search (ui.decklist_search):
 * on each row Favourite, Clone, Download and Add to Library; and, for someone logged in, tick
 * boxes with a bar of what can be done to the ticked: into or out of the favourites, into a
 * library. The list is the same for everyone, so whose favourites they are comes from
 * /decklists/favorites/state once the page (or a new page of it) is in. On the My Favorites
 * tab, a decklist taken out leaves the list.
 */
(function ($)
{
    var t = function (key, params) { return Translator.trans(key, params || {}); },
        token = null,
        update_selection = function () {};

    function table() { return $('#decklists-table'); }
    function ids_on_page() { return table().find('tbody tr[data-id]').map(function () { return $(this).data('id'); }).get(); }
    function picked() { return table().find('tbody td.rb-col-pick input:checked').closest('tr').map(function () { return $(this).data('id'); }).get(); }
    function favorites_tab() { return table().is('[data-favorites-tab]'); }

    function mark(favorites)
    {
        table().find('[data-favorite]').each(function () {
            var on = _.contains(favorites, $(this).data('favorite'));
            $(this).attr('aria-pressed', on ? 'true' : 'false').toggleClass('is-on', on)
                .attr('data-tip', t(on ? 'decklist.browse.do.unfavorite' : 'decklist.browse.do.favorite'))
                .attr('aria-label', t(on ? 'decklist.browse.do.unfavorite' : 'decklist.browse.do.favorite'));
        });
    }

    /** Whose favourites these are, for the rows on the page */
    function refresh()
    {
        var ids = ids_on_page();
        if(!ids.length) {
            return;
        }
        $.getJSON(Routing.generate('decklists_favorites_state'), {ids: ids}).done(function (data) {
            if(data) {
                token = data.token;
                mark(data.favorites);
            }
        });
    }

    /** Into the favourites (add) or out (remove); the list again on the My Favorites tab */
    function set_favorites(ids, add)
    {
        if(!ids.length || !token) {
            return $.Deferred().reject().promise();
        }
        return $.post(Routing.generate(add ? 'decklists_favorite_add' : 'decklists_unfavorite'), {ids: ids, token: token}).done(function () {
            table().find('[data-favorite]').each(function () {
                if(_.contains(ids, $(this).data('favorite'))) {
                    $(this).attr('aria-pressed', add ? 'true' : 'false').toggleClass('is-on', add)
                        .attr('data-tip', t(add ? 'decklist.browse.do.unfavorite' : 'decklist.browse.do.favorite'))
                        .attr('aria-label', t(add ? 'decklist.browse.do.unfavorite' : 'decklist.browse.do.favorite'));
                }
            });
            if(!add && favorites_tab()) {
                app.ui.browse_reload();
            }
        }).fail(function () {
            app.ui.insert_alert_message && app.ui.insert_alert_message('danger', t('decks.browse.unfavorite_failed'));
        });
    }

    $(function ()
    {
        if(!$('#decklist-selection').length) {
            return;
        }
        var results = $('#browse-results');
        // Favourite on a row: logged out, the login page
        results.on('click', '[data-favorite]', function (event) {
            event.preventDefault();
            if(!token) {
                window.location = Routing.generate('fos_user_security_login');
                return;
            }
            var button = $(this);
            if(button.hasClass('processing')) {
                return;
            }
            button.addClass('processing');
            set_favorites([button.data('favorite')], button.attr('aria-pressed') !== 'true').always(function () { button.removeClass('processing'); });
        });

        app.user.loaded.done(function () {
            $('body').addClass('rb-can-pick');
            update_selection = app.ui.browse_selection({
                table: '#decklists-table', section: '#decklist-selection', all: '.rb-pick-all', hide: true,
                count: function (n) { return Translator.transChoice('decklist.browse.picked', n, {count: n}); }
            });
            $('#decklist-selection').on('click', '[data-pick-act]', function () {
                var ids = picked();
                switch($(this).data('pick-act')) {
                    case 'favorite': return set_favorites(ids, true);
                    case 'unfavorite': return set_favorites(ids, false);
                    case 'library': return app.library_picker && app.library_picker.open(this, ids);
                    case 'clear':
                        table().find('td.rb-col-pick input').prop('checked', false);
                        return update_selection();
                }
            });
            refresh();
            $(document).on('browse:loaded', refresh);
        });
    });

})(jQuery);
