/* global Translator, _, app */

(function ui_decklist_form(ui, $)
{
    /**
     * sets up event handlers ; dataloaded not fired yet
     * @memberOf ui
     */
    ui.setup_event_handlers = function setup_event_handlers()
    {
        // the thumbnail: the chosen card's top half, or the deck's cultures
        $('#thumbnail').on('change', function () {
            var image = $(this).find('option:selected').data('image');
            $('#thumb-card').prop('hidden', !image).find('img').attr('src', image || '');
            $('#thumb-cultures').prop('hidden', !!image);
        }).trigger('change');

        // play records: add a blank row
        $('#btn-add-play').on('click', function () {
            var row = $('#plays .play-row').last().clone();
            row.find('input').val('');
            row.find('select').val('');
            $('#plays').append(row);
        });
        $('#plays').on('click', '.btn-remove-play', function () {
            if($('#plays .play-row').length > 1) {
                $(this).closest('.play-row').remove();
            } else {
                $(this).closest('.play-row').find('input').val('');
            }
        });
    };

    /**
     * @memberOf ui
     */
    ui.refresh_deck = function refresh_deck()
    {
        app.deck.display('#decklist');
    };

    /**
     * called when the DOM is loaded
     * @memberOf ui
     */
    ui.on_dom_loaded = function on_dom_loaded()
    {
        ui.setup_event_handlers();
        $('#descriptionMd').markdown({
            // no autofocus: it scrolled the page past the name and format to the description
            autofocus: false,
            iconlibrary: 'fa',
            hiddenButtons: ['cmdHeading', 'cmdImage', 'cmdCode'],
            footer: Translator.trans('decklist.publish.editor.hint'),
            language: Translator.locale,
            additionalButtons:
                    [[{
                                name: "groupCard",
                                data: [{
                                        name: "cmdCard",
                                        title: Translator.trans('decklist.publish.editor.cardtolink'),
                                        icon: "fas fa-clone",
                                        callback: ui.on_button_card
                                    }]
                            }, {
                                name: "groupCustom",
                                data: [{
                                        name: "cmdCustom1",
                                        title: Translator.trans('decklist.publish.editor.heading', {number: 1}),
                                        icon: "fas fa-heading",
                                        callback: _.partial(ui.on_button_heading, '#')
                                    }, {
                                        name: "cmdCustom2",
                                        title: Translator.trans('decklist.publish.editor.heading', {number: 2}),
                                        icon: "fas fa-heading small",
                                        callback: _.partial(ui.on_button_heading, '##')
                                    }, {
                                        name: "cmdCustom3",
                                        title: Translator.trans('decklist.publish.editor.heading', {number: 3}),
                                        icon: "fas fa-heading smaller",
                                        callback: _.partial(ui.on_button_heading, '###')
                                    }]
                            }]]
        });
    };

    ui.on_button_heading = function ui_on_button_heading(heading, e)
    {
        // Append/remove # surround the selection
        var chunk, cursor, selected = e.getSelection(), content = e.getContent(), pointer, prevChar;

        if(selected.length === 0) {
            // Give extra word
            chunk = e.__localize('heading text');
        } else {
            chunk = selected.text + '\n';
        }

        // transform selection and set the cursor into chunked text
        if((pointer = heading.length + 2, content.substr(selected.start - pointer, pointer) === heading + ' ')
                || (pointer = heading.length + 1, content.substr(selected.start - pointer, pointer) === heading)) {
            e.setSelection(selected.start - pointer, selected.end);
            e.replaceSelection(chunk);
            cursor = selected.start - pointer;
        } else if(selected.start > 0 && (prevChar = content.substr(selected.start - 1, 1), !!prevChar && prevChar !== '\n')) {
            e.replaceSelection('\n\n' + heading + ' ' + chunk);
            cursor = selected.start + heading.length + 4;
        } else {
            // Empty string before element
            e.replaceSelection(heading + ' ' + chunk);
            cursor = selected.start + heading.length + 1;
        }

        // Set the cursor
        e.setSelection(cursor, cursor + chunk.length);
    };

    ui.on_button_card = function ui_on_button_card(e)
    {
        var button = $('button[data-handler=bootstrap-markdown-cmdCard]');
        $(button).attr('data-toggle', 'dropdown');
        $(button).next().remove();

        var menu = $('<ul class="dropdown-menu">').insertAfter(button).on('click', 'li', function (event)
        {
            var code = $(this).data('code'), name = $(this).data('name');
            var chunk = '[' + name + '](' + Routing.generate('cards_zoom', {card_code: code}) + ')';
            ui.replace_selection(e, e.getSelection(), chunk);
            $(menu).remove();
            $(button).off('click');
        });

        var cards = app.data.cards.find({label: new RegExp(e.getSelection().text, 'i'), kind: 'card'}, {'$orderBy': {label: 1}});
        if(cards.length > 10) {
            cards = cards.slice(0, 10);
        }
        cards.forEach(function (card)
        {
            menu.append('<li data-code="' + card.code + '" data-name="' + _.escape(card.label) + '"><a href="#">' + card.label + ' <small><i>' + card.pack_name + '</i></small></a></li>');
        });
        $(button).dropdown();
    };

    ui.replace_selection = function ui_replace_selection(e, selected, chunk)
    {
        e.replaceSelection(chunk);
        var cursor = selected.start;
        e.setSelection(cursor + chunk.length, cursor + chunk.length);
        e.$textarea.focus();
    };

    /**
     * called when the app data is loaded
     * @memberOf ui
     */
    ui.on_data_loaded = function on_data_loaded()
    {
    };

    /**
     * called when both the DOM and the data app have finished loading
     * @memberOf ui
     */
    ui.on_all_loaded = function on_all_loaded()
    {
        app.textcomplete.setup('#descriptionMd');
        ui.refresh_deck();
    };


})(app.ui, jQuery);
