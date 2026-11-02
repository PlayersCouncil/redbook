/* global app, Translator */
/** A saved deck's page: the deck is rendered by the server; charts, draw simulator and exports here */
(function ui_deckview(ui, $)
{
    ui.do_action_deck = function do_action_deck(event)
    {
        switch($(this).attr('id')) {
            case 'btn-delete':
                $('#delete-deck-name').text(app.deck.get_name());
                $('#delete-deck-id').val(app.deck.get_id());
                $('#deleteModal').modal('show');
                break;
            case 'btn-print':
                window.print();
                break;
            case 'btn-export-plaintext':
                ui.export_plaintext(app.deck);
                break;
            case 'btn-export-gemp':
                ui.export_gemp(app.deck);
                break;
            default:
                return;
        }
        event.preventDefault();
    };

    ui.on_dom_loaded = function on_dom_loaded()
    {
        $('#btn-group-deck').on('click', 'button[id],a[id]', ui.do_action_deck);
        app.draw_simulator && app.draw_simulator.on_dom_loaded();
    };

    ui.on_all_loaded = function on_all_loaded()
    {
        app.markdown && app.markdown.update(app.deck.get_description_md() || Translator.trans('decks.defaultemptydesc'), '#description');
        app.draw_simulator && app.draw_simulator.reset();
        app.deck_charts && app.deck_charts.setup();
    };

})(app.ui, jQuery);
