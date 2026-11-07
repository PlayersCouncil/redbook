/* global app, _, Translator, jQuery */
/**
 * The markdown editor for a deck's notes (Ketura, 2026-11-07): bootstrap-markdown's
 * toolbar, with Preview in place of a second pane, and the same editor in the deckbuilder's
 * Notes tab and the decklist form. # in the text, or the card button, puts in a
 * [[card]] link; the preview renders those as the page will.
 */
(function app_md_editor(editor, $)
{
    function t(key, params)
    {
        return Translator.trans(key, params || {});
    }

    function replace_selection(e, selected, chunk)
    {
        e.replaceSelection(chunk);
        var cursor = selected.start;
        e.setSelection(cursor + chunk.length, cursor + chunk.length);
        e.$textarea.focus();
        e.$textarea.trigger('input');
    }

    /** # heading around the selection, or out again */
    function on_button_heading(heading, e)
    {
        var chunk, cursor, selected = e.getSelection(), content = e.getContent(), pointer, prevChar;

        if(selected.length === 0) {
            chunk = e.__localize('heading text');
        } else {
            chunk = selected.text + '\n';
        }

        if((pointer = heading.length + 2, content.substr(selected.start - pointer, pointer) === heading + ' ')
                || (pointer = heading.length + 1, content.substr(selected.start - pointer, pointer) === heading)) {
            e.setSelection(selected.start - pointer, selected.end);
            e.replaceSelection(chunk);
            cursor = selected.start - pointer;
        } else if(selected.start > 0 && (prevChar = content.substr(selected.start - 1, 1), !!prevChar && prevChar !== '\n')) {
            e.replaceSelection('\n\n' + heading + ' ' + chunk);
            cursor = selected.start + heading.length + 4;
        } else {
            e.replaceSelection(heading + ' ' + chunk);
            cursor = selected.start + heading.length + 1;
        }
        e.setSelection(cursor, cursor + chunk.length);
        e.$textarea.trigger('input');
    }

    /** The selected text's matching cards, as a menu; the pick goes in as [[card]] */
    function on_button_card(e)
    {
        var button = e.$editor.find('button[data-handler=bootstrap-markdown-cmdCard]');
        button.attr('data-toggle', 'dropdown');
        button.next('.dropdown-menu').remove();

        var menu = $('<ul class="dropdown-menu">').insertAfter(button).on('click', 'li', function (event)
        {
            event.preventDefault();
            replace_selection(e, e.getSelection(), '[[' + $(this).data('name') + ']]');
            menu.remove();
            button.off('click');
        });

        var typed = e.getSelection().text,
            cards = app.data.cards ? app.data.cards.find({label: new RegExp(typed.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'), 'i'), kind: 'card'}, {'$orderBy': {label: 1}}) : [];
        cards.slice(0, 10).forEach(function (card)
        {
            menu.append('<li data-name="' + _.escape(card.label) + '"><a href="#">' + _.escape(card.label) + ' <small><i>' + _.escape(card.pack_name || '') + '</i></small></a></li>');
        });
        button.dropdown();
    }

    /**
     * Makes the textarea a markdown editor. Its value and input events stay the textarea's,
     * so whatever listens to it keeps working.
     */
    editor.attach = function attach(selector)
    {
        var textarea = $(selector);
        if(!textarea.length || textarea.closest('.md-editor').length) {
            return;
        }
        textarea.markdown({
            autofocus: false,
            savable: false,
            iconlibrary: 'fa',
            hiddenButtons: ['cmdHeading', 'cmdImage', 'cmdCode'],
            footer: t('deckbuilder.notes.editor_hint'),
            language: Translator.locale,
            onPreview: function (e) {
                return app.markdown ? app.markdown.render(e.getContent()) : _.escape(e.getContent());
            },
            additionalButtons: [[{
                name: 'groupCard',
                data: [{
                    name: 'cmdCard',
                    title: t('decklist.publish.editor.cardtolink'),
                    icon: 'fas fa-clone',
                    callback: on_button_card
                }]
            }, {
                name: 'groupCustom',
                data: [1, 2, 3].map(function (n) {
                    return {
                        name: 'cmdCustom' + n,
                        title: t('decklist.publish.editor.heading', {number: n}),
                        icon: 'fas fa-heading' + (n === 2 ? ' small' : n === 3 ? ' smaller' : ''),
                        callback: _.partial(on_button_heading, new Array(n + 1).join('#'))
                    };
                })
            }]]
        });
        // # then a card's name
        app.textcomplete && app.textcomplete.setup(textarea, {icons: false, card_syntax: 'wiki'});
    };

})(app.md_editor = {}, jQuery);
