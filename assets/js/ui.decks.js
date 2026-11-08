/* global moment, _, app, Routing, Translator */

/**
 * My Decks (Ketura, 2026-11-07): the decklists page's search (ui.decklist_search.js, loaded
 * first), plus the deck actions and what can be done to the decks ticked. The results are
 * swapped as the filters change, so the handlers sit on #browse-results.
 */
(function ui_decks(ui, $)
{
    var tag_row_tpl = _.template('<button type="button" class="rb-tag-chip rb-tag-link" data-tag="<%- tag %>" title="<%- title %>"><%- tag %></button>');

    ui.decks = [];

    ui.confirm_delete = function confirm_delete(event)
    {
        var tr = $(this).closest('tr');
        var deck_id = tr.data('id');
        var deck_name = tr.find('.deck-name').text();
        $('#delete-deck-name').text(deck_name);
        $('#delete-deck-id').val(deck_id);
        $('#deleteModal').modal('show');
    };

    ui.confirm_delete_all = function confirm_delete_all(ids)
    {
        $('#delete-deck-list-id').val(ids.join('-'));
        $('#deleteListModal').modal('show');
    };

    ui.set_tags = function set_tags(id, tags)
    {
        var elt = $('tr[data-id=' + id + ']');
        var div = elt.find('.rb-row-tags').empty();
        tags.forEach(function (tag)
        {
            div.append($(tag_row_tpl({tag: tag, title: Translator.trans('decklist.browse.tag_search')})));
        });
    };

    ui.tag_add = function tag_add(ids)
    {
        $('#tag_add_ids').val(ids);
        $('#tagAddModal').modal('show');
        setTimeout(function ()
        {
            $('#tag_add_tags').focus();
        }, 500);
    };

    ui.tag_add_process = function tag_add_process(event)
    {
        event.preventDefault();
        var ids = $('#tag_add_ids').val().split(/,/);
        var tags = $('#tag_add_tags').val().trim().split(/\s+/);
        if(!ids.length || !tags.length)
            return;
        ui.tag_process_any('tag_add', {ids: ids, tags: tags});
    };

    ui.tag_remove = function tag_remove(ids)
    {
        $('#tag_remove_ids').val(ids);
        $('#tagRemoveModal').modal('show');
        setTimeout(function ()
        {
            $('#tag_remove_tags').focus();
        }, 500);
    };

    ui.download_selected = function(ids)
    {
        var $form = $('#download-deck-list-form');
        var $input = $('#download-deck-list-id');
        $input.val(ids.join('-'));
        $form.submit();
    };

    ui.tag_remove_process = function tag_remove_process(event)
    {
        event.preventDefault();
        var ids = $('#tag_remove_ids').val().split(/,/);
        var tags = $('#tag_remove_tags').val().split(/\s+/);
        if(!ids.length || !tags.length)
            return;
        ui.tag_process_any('tag_remove', {ids: ids, tags: tags});
    };

    ui.tag_clear = function tag_clear(ids)
    {
        $('#tag_clear_ids').val(ids);
        $('#tagClearModal').modal('show');
    };

    ui.tag_clear_process = function tag_clear_process(event)
    {
        event.preventDefault();
        var ids = $('#tag_clear_ids').val().split(/,/);
        if(!ids.length)
            return;
        ui.tag_process_any('tag_clear', {ids: ids});
    };

    ui.tag_process_any = function tag_process_any(route, data)
    {
        $.ajax(Routing.generate(route), {
            type: 'POST',
            data: data,
            dataType: 'json',
            success: function (data, textStatus, jqXHR)
            {
                var response = jqXHR.responseJSON;
                if(!response.success) {
                    alert('An error occured while updating the tags.');
                    return;
                }
                $.each(response.tags, function (id, tags)
                {
                    ui.set_tags(id, tags);
                });
            },
            error: function (jqXHR, textStatus, errorThrown)
            {
                console.log('[' + moment().format('YYYY-MM-DD HH:mm:ss') + '] Error on ' + this.url, textStatus, errorThrown);
                alert('An error occured while updating the tags.');
            }
        });
    };

    ui.do_diff = function do_diff(ids)
    {
        location.href = Routing.generate('decks_diff', {deck1_uuid: ids[0], deck2_uuid: ids[1]});
    };

    ui.handle_deck_selection_change = function handle_deck_selection_change()
    {
        var $boxes = $('#decks').find('input[type=checkbox]'), $checked = $boxes.filter(':checked');
        var $button = $('#btn-group-selection button');
        $button.toggleClass('btn-primary', $checked.length > 0).toggleClass('btn-default', !$checked.length);
        $button.find('.rb-selected-count').text($checked.length ? '(' + $checked.length + ')' : '');
        $('.rb-my-decks-table .toggle_all').prop('checked', $boxes.length > 0 && $checked.length === $boxes.length);
    };

    ui.do_action_selection = function do_action_selection(event)
    {
        event.stopPropagation();
        var action_id = $(this).attr('id');
        var ids = $('#decks input:checked').map(function (index, elt) {
            return $(elt).closest('tr').data('id');
        }).get();
        var uuids = $('#decks input:checked').map(function(index, elt) {
           return $(elt).closest('tr').data('uuid');
        });
        if(!action_id || !ids.length || !uuids.length) {
            return;
        }
        switch(action_id) {
            case 'btn-compare':
                ui.do_diff(uuids);
                break;
            case 'btn-tag-add':
                ui.tag_add(ids);
                break;
            case 'btn-tag-remove-one':
                ui.tag_remove(ids);
                break;
            case 'btn-tag-remove-all':
                ui.tag_clear(ids);
                break;
            case 'btn-delete-selected':
                ui.confirm_delete_all(ids);
                break;
            case 'btn-download-selected':
                ui.download_selected(ids);
                $(".selected-decks-dropdown-toggle").dropdown("toggle");
        }
        return false;
    };

    ui.on_dom_loaded = function on_dom_loaded()
    {
        ui.browse_setup();
        var results = $('#browse-results');
        results.on('click', '.toggle_all', function ()
        {
            $('#decks input[type=checkbox]').prop('checked', this.checked);
            ui.handle_deck_selection_change();
        });
        results.on('click', '#decks input[type=checkbox]', ui.handle_deck_selection_change);
        // a click anywhere in the box's cell ticks it
        results.on('click', 'td.rb-col-pick', function (event)
        {
            if(event.target === this) {
                $(this).find('input').trigger('click');
            }
        });
        results.on('click', 'button.btn-delete-deck', ui.confirm_delete);
        results.on('click', '#btn-group-selection a[id]', ui.do_action_selection);
        $(document).on('browse:loaded', ui.handle_deck_selection_change);
    };

    ui.on_data_loaded = function on_data_loaded()
    {
        ui.browse_data_loaded();
    };

    ui.on_all_loaded = function on_all_loaded()
    {
    };

})(app.ui, jQuery);
