/* global _, app, Translator */

/**
 * My Libraries and the public list (Ketura, 2026-11-08): the decklists page's search
 * (ui.decklist_search.js, loaded first); on My Libraries, ticking libraries and deleting them.
 */
(function ui_libraries(ui, $)
{
    var pending = [];

    function confirm_delete(uuids, names)
    {
        pending = uuids;
        $('#delete-library-modal .rb-delete-library-text').text(uuids.length === 1
            ? Translator.trans('library.delete.one', {name: names[0]})
            : Translator.trans('library.delete.several', {count: uuids.length}));
        $('#delete-library-modal').modal('show');
    }

    ui.on_dom_loaded = function on_dom_loaded()
    {
        ui.browse_setup();
        var results = $('#browse-results');
        // ticking rows and the With Selection section (shared: ui.browse_selection)
        ui.browse_selection();
        results.on('click', '.btn-delete-library', function ()
        {
            var tr = $(this).closest('tr');
            confirm_delete([tr.data('uuid')], [tr.find('.deck-name').text()]);
        });
        $('#browse-side').on('click', '#btn-delete-libraries', function ()
        {
            var rows = $('#decks input:checked').closest('tr');
            confirm_delete(rows.map(function () { return $(this).data('uuid'); }).get(), rows.map(function () { return $(this).find('.deck-name').text(); }).get());
        });
        $('#delete-library-confirm').on('click', function ()
        {
            var section = $('#btn-group-selection');
            $.ajax(section.data('delete'), {type: 'POST', data: {uuids: pending, token: section.data('token')}, dataType: 'json'})
                .done(function () { $('#delete-library-modal').modal('hide'); ui.browse_reload(); })
                .fail(function () { app.ui.insert_alert_message('danger', Translator.trans('library.delete.failed')); });
        });
        // a library's card opens it, as a row does on the other lists
        results.on('click', '.rb-lib-card[data-href]', function (event)
        {
            if($(event.target).closest('a, button').length || window.getSelection().toString()) {
                return;
            }
            if(event.ctrlKey || event.metaKey || event.button === 1) {
                window.open($(this).data('href'));
            } else {
                window.location = $(this).data('href');
            }
        });
    };

    ui.on_data_loaded = function on_data_loaded()
    {
        ui.browse_data_loaded();
    };

    ui.on_all_loaded = function on_all_loaded()
    {
    };
})(app.ui, jQuery);
