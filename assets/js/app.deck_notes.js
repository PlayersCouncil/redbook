/* global app, _, Translator, Routing, marked */
/**
 * The deckbuilder's Notes tab (Ketura, 2026-11-03).
 *
 * - Tags as chips (app.tag_input, shared): the entry suggests tags from the user's own
 *   decks, with how many decks carry each, filtering as they type.
 * - Starting notes: the companions marked S (hover for the card), the bid as written
 *   ("2", "2-3"), and free text.
 * - Card annotations: a note on any card, in the deck or not (rejected options,
 *   cards to watch for). Search a card by name to add one.
 * - The deck's notes.
 * The two text fields are markdown editors with their own Preview (app.md_editor;
 * Ketura, 2026-11-07). The decklist form uses the same tab (Deck/notes-editor), sending
 * its named fields; #annotations-field carries the annotations as JSON.
 */
(function app_deck_notes(notes, $)
{
    var on_edit = function () {};

    function t(key, params)
    {
        return Translator.trans(key, params || {});
    }

    /** The annotations as the decklist form sends them */
    function sync_annotations()
    {
        $('#annotations-field').val(JSON.stringify(app.deck.get_annotations()));
    }

    /* ---- tags: the shared chip input (app.tag_input) ---- */

    var tag_box = null;

    function render_chips()
    {
        tag_box && tag_box.render();
    }

    /* ---- starting notes ---- */

    /** A group's name, or its place: "Starting Fellowship", "Starting Fellowship 2" */
    function default_name(i)
    {
        return i === 0 ? t('deckbuilder.notes.starting_fellowship') : t('deckbuilder.notes.starting_fellowship_n', {number: i + 1});
    }

    /** One group's companions as pills, each with an x to take it off */
    function pills_html(codes, i)
    {
        if(!codes.length) {
            return '<span class="rb-hint">' + t('deckbuilder.notes.starting_none') + '</span>';
        }
        var content = app.deck.get_content ? app.deck.get_content() : {},
            cards = _.compact(_.map(codes, function (code) { return app.data.cards.findById(code); }));
        return _.map(cards, function (card) {
            // a companion any version will do for reads as in the list, its hover split (Ketura, 2026-11-08)
            var generic = !!content['any:' + card.code],
                label = generic ? app.card_versions.label(card) : card.label,
                split = generic && app.deck_editor.split_attr ? app.deck_editor.split_attr({generic: true, card: card}) : '';
            return '<span class="rb-starting-pill"><button type="button" class="rb-starting-remove" data-unstart="' + card.code + '" data-group="' + i + '" aria-label="'
                + t('deckbuilder.notes.start_remove', {card: _.escape(label)}) + '">&times;</button>'
                + '<a href="' + card.url + '" class="rb-starting-card card-tip" data-code="' + card.code + '"' + split + '>' + _.escape(label) + '</a></span>';
        }).join('');
    }

    /**
     * The starting fellowships (Ketura, 2026-11-07; several, each named, 2026-11-11): a row
     * each, a handle to drag it up or down (with more than one), its name, + to pick a
     * companion from the deck, its companions as pills in the order they're played (dragged to
     * change it), and − to take the row away (with more than one). No twilight total: game
     * text discounts make it wrong just where it matters (Ketura, 2026-11-11). The active row
     * (the one clicked last) is where + and the deck list's S go. #starting-field carries them
     * all for the decklist form. A row's name box isn't redrawn while it's being typed in.
     */
    function render_starting()
    {
        var groups = app.deck.get_starting_groups(),
            box = $('#starting-groups');
        $('#starting-field').val(JSON.stringify(groups));
        if(!app.data.cards || !box.length) {
            return;
        }
        // always one row to put companions in
        var shown = groups.length ? groups : [{name: '', codes: []}],
            active = app.deck.get_active_group(),
            rows = box.children('.rb-starting-group');
        if(rows.length !== shown.length) {
            box.html(_.map(shown, function (group, i) {
                return '<div class="rb-starting-group" data-group="' + i + '">'
                    + '<span class="rb-starting-handle" title="' + _.escape(t('deckbuilder.notes.starting_drag')) + '" aria-hidden="true"><span class="fas fa-grip-vertical"></span></span>'
                    + '<input type="text" class="rb-starting-name" maxlength="60" autocomplete="off" aria-label="' + _.escape(t('deckbuilder.notes.starting_name')) + '">'
                    + '<button type="button" class="rb-btn rb-btn-small rb-start-pick" aria-pressed="false" title="' + _.escape(t('deckbuilder.notes.start_pick')) + '" aria-label="' + _.escape(t('deckbuilder.notes.start_pick')) + '">+</button>'
                    + '<div class="rb-starting-list"></div>'
                    + '<button type="button" class="rb-starting-drop" title="' + _.escape(t('deckbuilder.notes.starting_remove_group')) + '" aria-label="' + _.escape(t('deckbuilder.notes.starting_remove_group')) + '">&minus;</button>'
                    + '</div>';
            }).join(''));
            rows = box.children('.rb-starting-group');
            // the companions in each, dragged into the order they're played
            rows.find('.rb-starting-list').each(function () {
                app.sortable.attach(this, {items: '.rb-starting-pill', axis: 'x', ignore: '.rb-starting-remove', done: function (pill) {
                    var list = pill.closest('.rb-starting-list'), i = list.closest('.rb-starting-group').data('group'), groups = app.deck.get_starting_groups();
                    if(groups[i]) {
                        groups[i].codes = list.find('.rb-starting-card').map(function () { return $(this).data('code') + ''; }).get();
                        app.deck.set_starting_groups(groups);
                        on_edit();
                    }
                }});
            });
        }
        rows.each(function (i) {
            var row = $(this), group = shown[i], name = row.find('.rb-starting-name');
            name.attr('placeholder', default_name(i));
            if(!name.is(':focus')) {
                name.val(group.name);
            }
            row.find('.rb-starting-list').html(pills_html(group.codes, i));
            row.toggleClass('is-active', shown.length > 1 && i === active).attr('title', shown.length > 1 && i === active ? t('deckbuilder.notes.starting_active') : null);
            row.find('.rb-starting-drop').prop('hidden', shown.length < 2);
            row.toggleClass('is-sortable', shown.length > 1);
            row.find('.rb-start-pick').attr('aria-pressed', picking === 'start' && i === active ? 'true' : 'false');
        });
    }

    /* ---- card annotations ---- */

    function render_annotations()
    {
        var rows = app.deck.get_annotations(),
            in_deck = app.deck.get_content ? app.deck.get_content() : {};
        sync_annotations();
        if(!app.data.cards) {
            return;
        }
        $('#annot-list').html(rows.length ? _.map(rows, function (row) {
            var card = app.data.cards.findById(row.code),
                held = !!(in_deck[row.code] || in_deck['rb:' + row.code] || in_deck['any:' + row.code] || in_deck['rb:any:' + row.code]);
            // a card that isn't in the deck gets an asterisk after its name (Ketura, 2026-11-03)
            return '<div class="rb-annot" data-code="' + row.code + '">'
                + '<div class="rb-annot-card"><span><a href="' + (card ? card.url : '#') + '" class="card-tip" data-code="' + row.code + '">' + _.escape(card ? card.label : row.code) + '</a>'
                + '<span class="rb-annot-out" title="' + t('deckbuilder.notes.not_in_deck') + '"' + (held ? ' hidden' : '') + '>*</span></span>'
                + '<button type="button" class="rb-annot-remove" data-unannotate="' + row.code + '" aria-label="' + t('deckbuilder.notes.annotation_remove') + '">&times;</button></div>'
                + '<div class="rb-annot-title"></div>'
                + '<textarea rows="2" data-annotation="' + row.code + '" aria-label="' + t('deckbuilder.notes.annotation_for', {card: _.escape(card ? card.label : row.code)}) + '">' + _.escape(row.text || '') + '</textarea>'
                // fullscreen, as the notes' editors have (Ketura, 2026-11-07)
                + '<button type="button" class="rb-annot-full" data-annot-full aria-label="' + t('deckbuilder.notes.fullscreen') + '" title="' + t('deckbuilder.notes.fullscreen') + '"><span class="fa fa-expand" aria-hidden="true"></span></button>'
                + '<div class="rb-annot-done"><button type="button" class="btn btn-primary" data-annot-done>' + t('deckbuilder.notes.done') + '</button></div>'
                + '</div>';
        }).join('') : '<p class="rb-hint">' + t('deckbuilder.notes.annotations_none') + '</p>');
    }

    /**
     * Cards to annotate: names beginning with what's typed first, as the # completion does,
     * the deck's own cards first within each (Ketura, 2026-11-07)
     */
    function annotation_matches(typed)
    {
        var in_deck = app.deck.get_content ? app.deck.get_content() : {},
            have = _.pluck(app.deck.get_annotations(), 'code'),
            cards = _.filter(app.data.cards.find(), function (card) { return have.indexOf(card.code) === -1; });
        return app.textcomplete.rank(cards, typed, function (card) { return in_deck[card.code] ? 0 : 1; }).slice(0, 12);
    }

    function in_deck_now(code)
    {
        var content = app.deck.get_content ? app.deck.get_content() : {};
        return !!(content[code] || content['rb:' + code] || content['any:' + code] || content['rb:any:' + code]);
    }

    function show_annotation_suggestions()
    {
        var typed = ($('#annot-search').val() || '').trim(),
            box = $('.rb-annot-suggest');
        if(!typed.length) {
            box.prop('hidden', true).empty();
            return;
        }
        var rows = annotation_matches(typed);
        box.html(rows.length ? _.map(rows, function (card) {
            return '<button type="button" data-annotate="' + card.code + '" data-hover-code="' + card.code + '"><span>' + app.textcomplete.highlight(card.label, typed) + '</span>'
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
        var row = $('#annot-list .rb-annot[data-code="' + code + '"]');
        row.addClass('is-new');
        row.find('textarea').focus();
        if(row[0]) {
            row[0].scrollIntoView({block: 'nearest'});
        }
    }

    /* ---- + then a card in the deck (Ketura, 2026-11-03) ---- */

    /** false, 'annot' (a card to annotate) or 'start' (a companion to start; Ketura, 2026-11-07) */
    var picking = false;

    function is_companion(code)
    {
        var card = app.data.cards && app.data.cards.findById(code);
        return !!card && card.type_code === 'companion';
    }

    /** While picking a companion, the other cards are greyed out */
    function mark_pickable()
    {
        $('#deck .rb-line[data-code], #deck .rb-tile[data-code]').each(function () {
            $(this).toggleClass('rb-pick-off', picking === 'start' && !is_companion($(this).data('code') + ''));
        });
    }

    function set_picking(mode)
    {
        picking = mode || false;
        $('#annot-pick').attr('aria-pressed', picking === 'annot' ? 'true' : 'false');
        $('#starting-groups .rb-start-pick').each(function () {
            $(this).attr('aria-pressed', picking === 'start' && $(this).closest('.rb-starting-group').data('group') === app.deck.get_active_group() ? 'true' : 'false');
        });
        $('#deck').toggleClass('rb-picking', !!picking).toggleClass('rb-picking-start', picking === 'start');
        mark_pickable();
    }

    /** Into the active group, unless it's there already */
    function add_starting(code)
    {
        if(!_.contains(app.deck.get_active_starting(), code)) {
            app.deck.toggle_starting(code);
            on_edit();
        }
    }

    /** While picking, a click on a deck card annotates it, or starts it, and does nothing else */
    function pick_click(event)
    {
        if(!picking) {
            return;
        }
        var target = $(event.target);
        if(target.closest('#annot-pick, .rb-start-pick').length) {
            return;
        }
        var item = target.closest('#deck .rb-line[data-code], #deck .rb-tile[data-code]');
        if(item.length) {
            event.preventDefault();
            event.stopPropagation();
            var code = item.data('code') + '';
            if(picking === 'start') {
                // only a companion can start; a click on anything else does nothing
                if(is_companion(code)) {
                    set_picking(false);
                    add_starting(code);
                }
                return;
            }
            set_picking(false);
            add_annotation(code);
        } else if(!target.closest('#deck').length) {
            set_picking(false);
        }
    }

    /* ---- the whole tab ---- */

    /** Fill everything from the deck (on load, and after a draft or revert) */
    notes.fill = function fill()
    {
        render_chips();
        $('#starting-bid').val(app.deck.get_starting_bid());
        $('#starting-notes').val(app.deck.get_starting_notes());
        render_starting();
        render_annotations();
    };

    notes.setup = function setup(edit_callback)
    {
        on_edit = edit_callback || on_edit;
        var root = $('#deck-notes');
        app.md_editor && app.md_editor.attach('#starting-notes');
        app.md_editor && app.md_editor.attach('#description');

        // tags
        tag_box = app.tag_input.attach('#tag-input', {url: Routing.generate('deck_tags')});
        $(document).on('click', function (event) {
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
            on_edit();
        });

        // annotations
        root.on('click', '#annot-pick', function () {
            set_picking(picking === 'annot' ? false : 'annot');
        });
        // the starting fellowships: a click in a row makes it the active one
        function activate(row)
        {
            var i = row.data('group');
            if(i !== app.deck.get_active_group()) {
                app.deck.set_active_group(i);
                render_starting();
                // a test draw starts with the active group
                $(document).trigger('deck.starting_active');
            }
        }
        root.on('mousedown focusin', '.rb-starting-group', function () { activate($(this)); });
        root.on('click', '.rb-start-pick', function () {
            var row = $(this).closest('.rb-starting-group'), was = picking === 'start' && row.data('group') === app.deck.get_active_group();
            activate(row);
            set_picking(was ? false : 'start');
        });
        root.on('click', '[data-unstart]', function () {
            var groups = app.deck.get_starting_groups(), i = $(this).data('group'), code = $(this).data('unstart') + '';
            if(groups[i]) {
                groups[i].codes = _.without(groups[i].codes, code);
                app.deck.set_starting_groups(groups);
                on_edit();
            }
        });
        root.on('input', '.rb-starting-name', function () {
            var i = $(this).closest('.rb-starting-group').data('group'), groups = app.deck.get_starting_groups();
            if(!groups[i]) {
                // naming the one empty row makes it
                groups[i] = {name: '', codes: []};
                app.deck.set_starting_groups(groups);
            }
            app.deck.rename_starting_group(i, $(this).val());
            $('#starting-field').val(JSON.stringify(app.deck.get_starting_groups()));
            on_edit();
        });
        // the rows themselves, by the handle on the left
        app.sortable.attach('#starting-groups', {items: '.rb-starting-group', handle: '.rb-starting-handle', axis: 'y', done: function (moved) {
            var groups = app.deck.get_starting_groups(),
                order = $('#starting-groups .rb-starting-group').map(function () { return $(this).data('group'); }).get();
            app.deck.set_starting_groups(_.compact(_.map(order, function (i) { return groups[i]; })));
            app.deck.set_active_group(order.indexOf(moved.data('group')));
            // redrawn whole: each row's place has changed
            $('#starting-groups').empty();
            render_starting();
            on_edit();
        }});
        root.on('click', '#starting-add', function () {
            var groups = app.deck.get_starting_groups();
            if(!groups.length) {
                groups.push({name: '', codes: []});
            }
            groups.push({name: '', codes: []});
            app.deck.set_starting_groups(groups);
            app.deck.set_active_group(groups.length - 1);
            render_starting();
            $('#starting-groups .rb-starting-group').last().find('.rb-starting-name').trigger('focus');
            on_edit();
        });
        root.on('click', '.rb-starting-drop', function () {
            var i = $(this).closest('.rb-starting-group').data('group'), groups = app.deck.get_starting_groups();
            groups.splice(i, 1);
            if(app.deck.get_active_group() >= i) {
                app.deck.set_active_group(Math.max(0, app.deck.get_active_group() - 1));
            }
            app.deck.set_starting_groups(groups);
            on_edit();
        });
        // the deck redrawn while picking keeps its greyed-out cards
        $(document).on('deck.rendered', mark_pickable);
        document.addEventListener('click', pick_click, true);
        $(document).on('keydown', function (event) {
            if(picking && event.key === 'Escape') {
                set_picking(false);
            }
        });
        $('a[data-toggle=tab]').on('shown.bs.tab', function () {
            set_picking(false);
        });
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
                sync_annotations();
                on_edit();
            }
        });
        // an annotation fullscreen: the box over most of the page, as the editors' (Ketura, 2026-11-07)
        function annot_fullscreen(row, on)
        {
            if(on) {
                var card = app.data.cards && app.data.cards.findById(row.data('code') + ''),
                    deck = $.trim($('#deck-name').val() || $('#name').val() || '');
                row.find('.rb-annot-title').text(t('deckbuilder.notes.editing', {field: t('deckbuilder.notes.annotation_for', {card: card ? card.label : row.data('code')}), deck: deck}));
            }
            row.toggleClass('is-full', on);
            $('body').toggleClass('md-nooverflow', on);
            if(on) {
                row.find('textarea').focus();
            }
        }
        root.on('click', '[data-annot-full]', function () {
            var row = $(this).closest('.rb-annot');
            annot_fullscreen(row, !row.hasClass('is-full'));
        });
        root.on('click', '[data-annot-done]', function () {
            annot_fullscreen($(this).closest('.rb-annot'), false);
        });
        root.on('keydown', '.rb-annot.is-full textarea', function (event) {
            if(event.key === 'Escape') {
                annot_fullscreen($(this).closest('.rb-annot'), false);
            }
        });
        root.on('click', '[data-unannotate]', function () {
            var code = $(this).data('unannotate') + '';
            app.deck.set_annotations(_.reject(app.deck.get_annotations(), function (row) { return row.code === code; }));
            render_annotations();
            on_edit();
        });

        // the S marks and the deck's cards change outside this tab
        $(document).on('deck.changed', function () {
            render_starting();
            $('#annot-list .rb-annot').each(function () {
                var code = $(this).data('code') + '',
                    content = app.deck.get_content ? app.deck.get_content() : {},
                    held = !!(content[code] || content['rb:' + code] || content['any:' + code] || content['rb:any:' + code]);
                $(this).find('.rb-annot-out').prop('hidden', held);
            });
        });
    };

})(app.deck_notes = {}, jQuery);
