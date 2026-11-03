/* global app, _, Translator, Routing, marked */
/**
 * The deckbuilder's Notes tab (Ketura, 2026-11-03).
 *
 * - Tags as chips. The entry box suggests tags from the user's own decks, with how
 *   many decks carry each, filtering as they type; any other word is a new tag.
 * - Starting notes: the companions marked S (hover for the card), the bid as written
 *   ("2", "2-3"), and free text with its own preview.
 * - Card annotations: a note on any card, in the deck or not (rejected options,
 *   cards to watch for). Search a card by name to add one.
 * - The deck's notes, with their preview.
 */
(function app_deck_notes(notes, $)
{
    var on_edit = function () {},
        known_tags = null;

    function t(key, params)
    {
        return Translator.trans(key, params || {});
    }

    function md(text)
    {
        return typeof marked === 'function' ? marked(text || '') : _.escape(text || '');
    }

    /* ---- tags ---- */

    function tags()
    {
        return _.uniq(($('input[name=tags_]').val() || '').split(/\s+/).filter(Boolean));
    }

    function set_tags(list)
    {
        $('input[name=tags_]').val(_.uniq(list).join(' ')).trigger('input');
        render_chips();
    }

    function render_chips()
    {
        $('#tag-input .rb-tag-chips').html(_.map(tags(), function (tag) {
            return '<span class="rb-tag-chip">' + _.escape(tag) + '<button type="button" data-untag="' + _.escape(tag) + '" aria-label="'
                + t('deckbuilder.notes.tag_remove', {tag: _.escape(tag)}) + '">&times;</button></span>';
        }).join(''));
    }

    function load_tags(then)
    {
        if(known_tags) {
            return then();
        }
        $.getJSON(Routing.generate('deck_tags'), function (rows) {
            known_tags = rows || [];
            then();
        }).fail(function () {
            known_tags = [];
            then();
        });
    }

    function show_suggestions()
    {
        load_tags(function () {
            var typed = ($('#tag-entry').val() || '').trim().toLowerCase(),
                have = tags(),
                rows = _.filter(known_tags, function (row) {
                    return have.indexOf(row.tag) === -1 && (!typed || row.tag.toLowerCase().indexOf(typed) !== -1);
                }).slice(0, 12),
                box = $('#tag-input .rb-tag-suggest');
            if(typed && !_.findWhere(known_tags, {tag: typed}) && have.indexOf(typed) === -1) {
                rows.unshift({tag: typed, count: 0, is_new: true});
            }
            box.html(_.map(rows, function (row) {
                return '<button type="button" data-tag="' + _.escape(row.tag) + '">' + _.escape(row.tag)
                    + (row.is_new ? ' <small>' + t('deckbuilder.notes.tag_new') + '</small>' : ' <small>' + Translator.transChoice('deckbuilder.notes.tag_count', row.count, {count: row.count}) + '</small>')
                    + '</button>';
            }).join('')).prop('hidden', !rows.length);
        });
    }

    function add_typed_tag()
    {
        var typed = ($('#tag-entry').val() || '').trim().replace(/\s+/g, '-');
        if(typed) {
            set_tags(tags().concat([typed]));
        }
        $('#tag-entry').val('');
        show_suggestions();
    }

    /* ---- starting notes ---- */

    function render_starting()
    {
        var codes = app.deck.get_starting(),
            list = $('#starting-list');
        if(!app.data.cards) {
            return;
        }
        if(!codes.length) {
            list.html('<span class="rb-hint">' + t('deckbuilder.notes.starting_none') + '</span>');
            return;
        }
        var cards = _.compact(_.map(codes, function (code) { return app.data.cards.findById(code); })),
            twilight = _.reduce(cards, function (sum, card) { return sum + (card.twilight || 0); }, 0);
        list.html('<span class="rb-starting-label">' + t('deckbuilder.notes.starting_fellowship') + '</span> '
            + _.map(cards, function (card) {
                return '<a href="' + card.url + '" class="rb-starting-card card-tip" data-code="' + card.code + '">' + _.escape(card.label) + '</a>';
            }).join('')
            + '<span class="rb-starting-cost">' + t('deckbuilder.notes.starting_twilight', {cost: twilight}) + '</span>');
    }

    /* ---- card annotations ---- */

    function render_annotations()
    {
        var rows = app.deck.get_annotations(),
            in_deck = app.deck.get_content ? app.deck.get_content() : {};
        if(!app.data.cards) {
            return;
        }
        $('#annot-list').html(rows.length ? _.map(rows, function (row) {
            var card = app.data.cards.findById(row.code),
                held = !!(in_deck[row.code] || in_deck['rb:' + row.code]);
            return '<div class="rb-annot" data-code="' + row.code + '">'
                + '<div class="rb-annot-card"><a href="' + (card ? card.url : '#') + '" class="card-tip" data-code="' + row.code + '">' + _.escape(card ? card.label : row.code) + '</a>'
                + '<span class="rb-annot-where' + (held ? ' is-held' : '') + '">' + t(held ? 'deckbuilder.notes.in_deck' : 'deckbuilder.notes.not_in_deck') + '</span>'
                + '<button type="button" class="rb-annot-remove" data-unannotate="' + row.code + '" aria-label="' + t('deckbuilder.notes.annotation_remove') + '">&times;</button></div>'
                + '<textarea rows="2" data-annotation="' + row.code + '" aria-label="' + t('deckbuilder.notes.annotation_for', {card: _.escape(card ? card.label : row.code)}) + '">' + _.escape(row.text || '') + '</textarea>'
                + '</div>';
        }).join('') : '<p class="rb-hint">' + t('deckbuilder.notes.annotations_none') + '</p>');
    }

    /** Lower case without accents, as the card pool's search does */
    function fold(s)
    {
        return (s || '').normalize('NFD').replace(/[\u0300-\u036f]/g, '').replace(/\u2022/g, '').toLowerCase();
    }

    function annotation_matches(typed)
    {
        typed = fold(typed);
        var in_deck = app.deck.get_content ? app.deck.get_content() : {},
            have = _.pluck(app.deck.get_annotations(), 'code'),
            found = _.filter(app.data.cards.find(), function (card) {
                return have.indexOf(card.code) === -1 && fold(card.label).indexOf(typed) !== -1;
            });
        // the deck's own cards first
        found.sort(function (a, b) {
            return (in_deck[b.code] ? 1 : 0) - (in_deck[a.code] ? 1 : 0) || a.label.localeCompare(b.label);
        });
        return found.slice(0, 12);
    }

    function in_deck_now(code)
    {
        var content = app.deck.get_content ? app.deck.get_content() : {};
        return !!(content[code] || content['rb:' + code]);
    }

    function show_annotation_suggestions()
    {
        var typed = ($('#annot-search').val() || '').trim(),
            box = $('.rb-annot-suggest');
        if(typed.length < 2) {
            box.prop('hidden', true).empty();
            return;
        }
        var rows = annotation_matches(typed);
        box.html(rows.length ? _.map(rows, function (card) {
            return '<button type="button" data-annotate="' + card.code + '" data-hover-code="' + card.code + '">' + _.escape(card.label)
                + ' <small>' + card.code + (in_deck_now(card.code) ? ' &middot; ' + t('deckbuilder.notes.in_deck') : '') + '</small></button>';
        }).join('') : '<p class="rb-hint">' + t('deckbuilder.notes.no_card') + '</p>').prop('hidden', false);
    }

    function add_annotation(code)
    {
        var rows = app.deck.get_annotations();
        if(!_.findWhere(rows, {code: code})) {
            rows.push({code: code, text: ''});
            app.deck.set_annotations(rows);
            on_edit();
        }
        $('#annot-search').val('');
        $('.rb-annot-suggest').prop('hidden', true).empty();
        render_annotations();
        $('#annot-list [data-annotation="' + code + '"]').focus();
    }

    /* ---- the whole tab ---- */

    /** Fill everything from the deck (on load, and after a draft or revert) */
    notes.fill = function fill()
    {
        render_chips();
        $('#starting-bid').val(app.deck.get_starting_bid());
        $('#starting-notes').val(app.deck.get_starting_notes());
        $('#starting-preview').html(md(app.deck.get_starting_notes()));
        $('#description-preview').html(md($('#description').val()));
        render_starting();
        render_annotations();
    };

    notes.setup = function setup(edit_callback)
    {
        on_edit = edit_callback || on_edit;
        var root = $('#deck-notes');

        // tags
        root.on('focus click', '#tag-entry', show_suggestions);
        root.on('input', '#tag-entry', function () {
            if(/[\s,]$/.test($(this).val())) {
                add_typed_tag();
            } else {
                show_suggestions();
            }
        });
        root.on('keydown', '#tag-entry', function (event) {
            if(event.key === 'Enter') {
                event.preventDefault();
                add_typed_tag();
            } else if(event.key === 'Backspace' && !$(this).val()) {
                set_tags(tags().slice(0, -1));
            } else if(event.key === 'Escape') {
                $('#tag-input .rb-tag-suggest').prop('hidden', true);
            }
        });
        root.on('click', '[data-tag]', function () {
            set_tags(tags().concat([$(this).data('tag') + '']));
            $('#tag-entry').val('').focus();
            show_suggestions();
        });
        root.on('click', '[data-untag]', function () {
            set_tags(_.without(tags(), $(this).data('untag') + ''));
        });
        $(document).on('click', function (event) {
            if(!$(event.target).closest('#tag-input').length) {
                $('#tag-input .rb-tag-suggest').prop('hidden', true);
            }
            if(!$(event.target).closest('.rb-annot-add').length) {
                $('.rb-annot-suggest').prop('hidden', true);
            }
        });

        // starting notes
        root.on('input', '#starting-bid', function () {
            app.deck.set_starting_bid($(this).val());
            on_edit();
        });
        root.on('input', '#starting-notes', function () {
            app.deck.set_starting_notes($(this).val());
            $('#starting-preview').html(md($(this).val()));
            on_edit();
        });

        // annotations
        root.on('input focus', '#annot-search', show_annotation_suggestions);
        root.on('keydown', '#annot-search', function (event) {
            if(event.key === 'Enter') {
                event.preventDefault();
                var first = $('.rb-annot-suggest [data-annotate]').first();
                if(first.length) {
                    add_annotation(first.data('annotate') + '');
                }
            }
        });
        root.on('click', '[data-annotate]', function () {
            add_annotation($(this).data('annotate') + '');
        });
        root.on('input', '[data-annotation]', function () {
            var code = $(this).data('annotation') + '',
                rows = app.deck.get_annotations(),
                row = _.findWhere(rows, {code: code});
            if(row) {
                row.text = $(this).val();
                app.deck.set_annotations(rows);
                on_edit();
            }
        });
        root.on('click', '[data-unannotate]', function () {
            var code = $(this).data('unannotate') + '';
            app.deck.set_annotations(_.reject(app.deck.get_annotations(), function (row) { return row.code === code; }));
            render_annotations();
            on_edit();
        });

        // the description's own preview
        root.on('input', '#description', function () {
            $('#description-preview').html(md($(this).val()));
        });

        // the S marks and the deck's cards change outside this tab
        $(document).on('deck.changed', function () {
            render_starting();
            $('#annot-list .rb-annot').each(function () {
                var code = $(this).data('code') + '',
                    content = app.deck.get_content ? app.deck.get_content() : {},
                    held = !!(content[code] || content['rb:' + code]);
                $(this).find('.rb-annot-where').toggleClass('is-held', held).text(t(held ? 'deckbuilder.notes.in_deck' : 'deckbuilder.notes.not_in_deck'));
            });
        });
    };

})(app.deck_notes = {}, jQuery);
