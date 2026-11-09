/* global _, app, Translator */

/**
 * My Libraries and the public list (Ketura, 2026-11-08): the decklists page's search
 * (ui.decklist_search.js, loaded first); on My Libraries, ticking libraries and deleting them.
 */
(function ui_libraries(ui, $)
{
    var pending = [];

    function selection_changed()
    {
        var boxes = $('#decks input[type=checkbox]'), n = boxes.filter(':checked').length;
        var section = $('#btn-group-selection').toggleClass('is-off', !n);
        section.find('.rb-selected-count').text(n ? '(' + n + ')' : '');
        section.find('button').prop('disabled', !n);
        $('.rb-library-table .toggle_all').prop('checked', boxes.length > 0 && n === boxes.length);
    }

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
        results.on('click', '.toggle_all', function ()
        {
            $('#decks input[type=checkbox]').prop('checked', this.checked);
            selection_changed();
        });
        results.on('click', '#decks input[type=checkbox]', selection_changed);
        results.on('click', 'td.rb-col-pick', function (event)
        {
            if(event.target === this) {
                $(this).find('input').trigger('click');
            }
        });
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
        $(document).on('browse:loaded', selection_changed);
    };

    ui.on_data_loaded = function on_data_loaded()
    {
        ui.browse_data_loaded();
    };

    ui.on_all_loaded = function on_all_loaded()
    {
    };
})(app.ui, jQuery);
