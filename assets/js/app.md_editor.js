/* global app, _, Translator, jQuery */
/**
 * The markdown editor for a deck's notes (Ketura, 2026-11-07): bootstrap-markdown's
 * toolbar, with Preview in place of a second pane, and the same editor in the deckbuilder's
 * Notes tab and the decklist form. # in the text, or the card button, puts in a
 * [[card]] link; the preview renders those as the page will.
 * Fullscreen (Ketura, 2026-11-07) is a box over most of the page with the rest dimmed,
 * headed "Editing <field> for <deck>", with Done under the text to close it. The field's
 * name comes from the textarea's data-md-title; the deck's from the page's name field.
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

    /**
     * The card button types a # where the cursor is, which opens the card list as typing
     * one does (Ketura, 2026-11-07)
     */
    function on_button_card(e)
    {
        var selected = e.getSelection(), before = e.getContent().substr(0, selected.start);
        var chunk = (/\S$/.test(before) ? ' ' : '') + '#';
        e.replaceSelection(chunk);
        var cursor = selected.start + chunk.length;
        e.setSelection(cursor, cursor);
        e.$textarea.focus().trigger('input');
        // once the click is over: the list closes on a click outside it, this one included
        setTimeout(function () { e.$textarea.trigger($.Event('keyup', {keyCode: 51, which: 51})); }, 0);
    }

    /**
     * The symbol button (Ketura, 2026-11-07): a menu of the cultures, the blocks and the twilight
     * costs, each putting in the text that shows as that icon ([Moria], [Towers], (3))
     */
    function symbol_menu(e)
    {
        var button = e.$editor.find('button[data-handler="bootstrap-markdown-cmdSymbol"]'),
            menu = button.siblings('.rb-sym-menu');
        if(!menu.length) {
            var rows = app.symbols ? app.symbols.list() : [],
                groups = _.groupBy(rows, 'group');
            menu = $('<div class="rb-sym-menu" role="menu" hidden></div>').html(_.map(['cultures', 'blocks', 'twilight'], function (group) {
                return '<div class="rb-sym-group rb-sym-group-' + group + '">' + _.map(groups[group] || [], function (row) {
                    return '<button type="button" role="menuitem" data-insert="' + _.escape(row.text) + '" title="' + _.escape(row.name) + '">'
                        + row.html.replace(' data-tip="' + _.escape(row.name) + '"', '')
                        + (group === 'twilight' ? '' : '<span class="rb-sym-name">' + _.escape(row.name) + '</span>') + '</button>';
                }).join('') + '</div>';
            }).join('')).insertAfter(button);
            menu.on('click', '[data-insert]', function () {
                var selected = e.getSelection();
                replace_selection(e, selected, $(this).data('insert') + '');
                menu.prop('hidden', true);
            });
            $(document).on('mousedown', function (event) {
                if(!$(event.target).closest(menu).length && !$(event.target).closest(button).length) {
                    menu.prop('hidden', true);
                }
            });
            e.$textarea.add(menu).on('keydown', function (event) {
                if(event.key === 'Escape' && !menu.prop('hidden')) {
                    event.stopPropagation();
                    menu.prop('hidden', true);
                }
            });
        }
        menu.prop('hidden', !menu.prop('hidden'));
    }

    /** The deck's own cards come first in the card list, and fill it before anything's typed */
    function in_deck(card)
    {
        var content = app.deck && app.deck.get_content ? app.deck.get_content() : {};
        return content[card.code] || content['rb:' + card.code] ? 0 : 1;
    }

    /**
     * Room to scroll past the last line until it's at the top, as text editors allow; a
     * click down there puts the cursor at the end of the text (Ketura, 2026-11-07)
     */
    function scroll_past_end(textarea)
    {
        var el = textarea[0];
        function pad()
        {
            if(!el.clientHeight) {
                return;
            }
            // measured without the padding: a box can't be shorter than its padding, so padding
            // left from a bigger size (fullscreen) would otherwise hold it at that size
            var scroll = el.scrollTop;
            el.style.removeProperty('padding-bottom');
            var style = getComputedStyle(el),
                line = parseFloat(style.lineHeight) || parseFloat(style.fontSize) * 1.4,
                bottom = parseFloat(style.paddingBottom) || 0,
                // the text's own area, the toolbar and footer apart
                visible = el.clientHeight - (parseFloat(style.paddingTop) || 0) - bottom,
                want = Math.max(bottom, Math.round(visible + bottom - line));
            el.style.setProperty('padding-bottom', want + 'px', 'important');
            el.scrollTop = scroll;
        }
        if(window.ResizeObserver) {
            new ResizeObserver(pad).observe(el);
        }
        $(window).on('resize', _.debounce(pad, 100));
        pad();
        textarea.on('mouseup', function (event) {
            var style = getComputedStyle(el),
                text_bottom = el.scrollHeight - (parseFloat(style.paddingBottom) || 0),
                y = event.clientY - el.getBoundingClientRect().top + el.scrollTop;
            if(y > text_bottom && el.selectionStart === el.selectionEnd) {
                var end = el.value.length;
                el.setSelectionRange(end, end);
            }
        });
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
        var box;
        textarea.markdown({
            onFullscreen: function (e) {
                var deck = $.trim($('#deck-name').val() || $('#name').val() || '');
                box.find('.rb-md-title').text(t('deckbuilder.notes.editing', {field: textarea.data('md-title') || '', deck: deck}));
                e.$textarea.focus();
            },
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
                    title: t('deckbuilder.notes.card_button'),
                    icon: 'fas fa-clone',
                    callback: on_button_card
                }, {
                    name: 'cmdSymbol',
                    title: t('decklist.publish.editor.entersymbol'),
                    icon: 'fas fa-shapes',
                    callback: symbol_menu
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
        var instance = textarea.data('markdown');
        box = textarea.closest('.md-editor');
        // Preview shows whether it's on: plain off, blue on (Ketura, 2026-11-07)
        var preview = box.find('button[data-handler="bootstrap-markdown-cmdPreview"]').removeClass('btn-primary').addClass('btn-default rb-md-preview-btn');
        box.on('click', 'button', function () {
            setTimeout(function () { preview.toggleClass('active', !!instance.$isPreview).attr('aria-pressed', instance.$isPreview ? 'true' : 'false'); }, 0);
        });
        scroll_past_end(textarea);
        box.prepend('<div class="rb-md-title"></div>');
        $('<div class="rb-md-done"><button type="button" class="btn btn-primary">' + t('deckbuilder.notes.done') + '</button></div>')
            .appendTo(box)
            .on('click', 'button', function () { instance.setFullscreen(false); });
        textarea.on('keydown', function (event) {
            if(event.key === 'Escape' && box.hasClass('md-fullscreen-mode') && !box.find('.rb-sym-menu:not([hidden])').length) {
                instance.setFullscreen(false);
            }
        });
        // # then a card's name
        app.textcomplete && app.textcomplete.setup(textarea, {icons: false, card_syntax: 'wiki', prefer: in_deck});
    };

})(app.md_editor = {}, jQuery);
