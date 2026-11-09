/* global _, Translator, jQuery */

/**
 * Tags as chips (Ketura, 2026-11-03; shared 2026-11-09): the deckbuilder's Notes tab, the
 * decklist form and the library editor. The markup (Deck/notes-editor, Library/edit): a box
 * holding a hidden input with the tags space-separated, the entry field and its suggestions,
 * and the chips. The entry suggests the tags there are (from the url given, with how many
 * carry each), filtering as you type; any other word is a new tag. A space, comma or Enter
 * adds what's typed; Backspace in an empty entry takes the last off. A change triggers input
 * on the hidden field.
 *
 * var box = app.tag_input.attach('#tag-input', {url: ...}); box.render(); box.tags();
 */
(function app_tag_input(tag_input, $)
{
    var lists = {};

    function t(key, params)
    {
        return Translator.trans(key, params || {});
    }

    tag_input.attach = function attach(root, options)
    {
        root = $(root);
        var field = root.find('input[type=hidden]').first(),
            entry = root.find('.rb-tag-entry input[type=text]'),
            box = root.find('.rb-tag-suggest'),
            chips = root.find('.rb-tag-chips');

        function tags()
        {
            return _.uniq((field.val() || '').split(/\s+/).filter(Boolean));
        }

        function render()
        {
            chips.html(_.map(tags(), function (tag) {
                return '<span class="rb-tag-chip">' + _.escape(tag) + '<button type="button" data-untag="' + _.escape(tag) + '" aria-label="'
                    + t('deckbuilder.notes.tag_remove', {tag: _.escape(tag)}) + '">&times;</button></span>';
            }).join(''));
        }

        function set(list)
        {
            field.val(_.uniq(list).join(' ')).trigger('input');
            render();
        }

        function known(then)
        {
            var url = typeof options.url === 'function' ? options.url() : options.url;
            if(lists[url]) {
                return then(lists[url]);
            }
            $.getJSON(url).done(function (rows) { lists[url] = rows || []; then(lists[url]); })
                .fail(function () { lists[url] = []; then(lists[url]); });
        }

        function suggest()
        {
            known(function (rows_all) {
                var typed = (entry.val() || '').trim().toLowerCase(),
                    have = tags(),
                    rows = _.filter(rows_all, function (row) {
                        return have.indexOf(row.tag) === -1 && (!typed || row.tag.toLowerCase().indexOf(typed) !== -1);
                    }).slice(0, 12);
                if(typed && !_.findWhere(rows_all, {tag: typed}) && have.indexOf(typed) === -1) {
                    rows.unshift({tag: typed, count: 0, is_new: true});
                }
                if(!entry.is(':focus')) {
                    return;
                }
                box.html(_.map(rows, function (row) {
                    var note = row.is_new ? t('deckbuilder.notes.tag_new')
                        : row.count ? Translator.transChoice('deckbuilder.notes.tag_count', row.count, {count: row.count})
                        : Translator.transChoice('deckbuilder.notes.tag_site', row.site, {count: row.site});
                    return '<button type="button" data-tag="' + _.escape(row.tag) + '">' + _.escape(row.tag) + ' <small>' + note + '</small></button>';
                }).join('')).prop('hidden', !rows.length);
            });
        }

        function add_typed()
        {
            var typed = (entry.val() || '').trim().replace(/[\s,]+/g, '-').replace(/^-+|-+$/g, '');
            if(typed) {
                set(tags().concat([typed]));
            }
            entry.val('');
            suggest();
        }

        entry.on('focus click', suggest);
        entry.on('input', function () {
            if(/[\s,]$/.test(entry.val())) {
                add_typed();
            } else {
                suggest();
            }
        });
        entry.on('keydown', function (event) {
            if(event.key === 'Enter') {
                event.preventDefault();
                add_typed();
            } else if(event.key === 'Backspace' && !entry.val()) {
                set(tags().slice(0, -1));
            } else if(event.key === 'Escape') {
                box.prop('hidden', true);
            }
        });
        // the suggestions belong to the box: they close when it loses focus, and picking one
        // doesn't take the focus away first (Ketura, 2026-11-04)
        entry.on('blur', function () {
            setTimeout(function () {
                if(!entry.is(':focus')) {
                    box.prop('hidden', true);
                }
            }, 120);
        });
        root.on('mousedown', '.rb-tag-suggest button', function (event) {
            event.preventDefault();
        });
        root.on('click', '[data-tag]', function () {
            set(tags().concat([$(this).data('tag') + '']));
            entry.val('').trigger('focus');
            suggest();
        });
        root.on('click', '[data-untag]', function () {
            set(_.without(tags(), $(this).data('untag') + ''));
        });
        render();

        return {render: render, tags: tags, set: set};
    };
})(app.tag_input = {}, jQuery);
