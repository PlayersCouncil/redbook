/* global app, _, Translator, Routing */
/**
 * The deckbuilder page (phase3/03): the deck on the left, the card pool and
 * tools on the right. The deck lives in app.deck; every change re-renders the
 * deck, refreshes the pool's controls and asks Gemp for validation (debounced).
 */
(function ui_deckedit(ui, $)
{
    var ROW_LIMIT = 200,
        sort = {key: 'label', dir: 1},
        filter_text = '';

    function format()
    {
        return app.deck.get_format();
    }

    /* ---- filters ---- */

    ui.build_culture_buttons = function build_culture_buttons()
    {
        var cultures = {};
        app.data.cards.find({}).forEach(function (card) {
            if(card.culture_code) {
                cultures[card.culture_code] = card.culture_name;
            }
        });
        var html = _.map(_.sortBy(_.keys(cultures), function (c) { return cultures[c]; }), function (code) {
            return '<label class="btn btn-default btn-xs" title="' + _.escape(cultures[code]) + '"><input type="checkbox" name="' + code + '">' + _.escape(cultures[code]) + '</label>';
        }).join('');
        $('[data-filter=culture_code]').html(html);
    };

    ui.build_type_buttons = function build_type_buttons()
    {
        var types = {};
        app.data.cards.find({}).forEach(function (card) {
            types[card.type_code] = card.type_name;
        });
        var html = _.map(_.sortBy(_.keys(types), function (t) { return types[t]; }), function (code) {
            return '<label class="btn btn-default btn-xs"><input type="checkbox" name="' + code + '">' + _.escape(types[code]) + '</label>';
        }).join('');
        $('[data-filter=type_code]').html(html);
    };

    function checked(filter)
    {
        return $('[data-filter=' + filter + '] input:checked').map(function () { return this.name; }).get();
    }

    /** The cards the pool shows: the format's sets unless "show all sets", then the filters */
    ui.get_pool = function get_pool()
    {
        var f = format(),
            sets = f && !app.config.get('show-all-sets') && f.sets && f.sets.length ? f.sets : null,
            show_errata = app.config.get('show-errata') && (!f || f.pc_errata),
            sides = checked('side'),
            cultures = checked('culture_code'),
            types = checked('type_code'),
            only_deck = app.config.get('show-only-deck'),
            hide_banned = app.config.get('hide-banned'),
            query = app.smart_filter.get_query({});

        var cards = app.data.cards.find(query);
        var content = app.deck.get_content();
        return _.filter(cards, function (card) {
            var in_deck = content[card.code] || content['rb:' + card.code];
            if(only_deck) {
                return !!in_deck;
            }
            if(in_deck) {
                return true;
            }
            if(card.kind === 'errata' && !show_errata) {
                return false;
            }
            if(sets && sets.indexOf(card.pack_code) === -1) {
                return false;
            }
            if(sides.length && sides.indexOf(card.side || 'none') === -1) {
                return false;
            }
            if(cultures.length && cultures.indexOf(card.culture_code) === -1) {
                return false;
            }
            if(types.length && types.indexOf(card.type_code) === -1) {
                return false;
            }
            if(hide_banned && f && app.data.format_limit(f, card.code) === 'banned') {
                return false;
            }
            return true;
        });
    };

    /* ---- the pool table ---- */

    function quantity_control(card)
    {
        var role = app.deck.role_for_card(card),
            qty = app.deck.get_quantity(card.code),
            html = '';
        if(role === 'draw') {
            var max = app.card_modal.max_quantity(card);
            html += '<div class="btn-group btn-group-xs" data-toggle="buttons">';
            for(var i = 0; i <= max; i++) {
                html += '<label class="btn btn-default' + (i === qty ? ' active' : '') + '"><input type="radio" name="qty-' + card.code + '" value="' + i + '">' + i + '</label>';
            }
            html += '</div>';
            if(card.type_code === 'companion') {
                var is_rb = app.deck.get_ring_bearer_code() === card.code;
                html += ' <button type="button" class="btn btn-xs ' + (is_rb ? 'btn-primary' : 'btn-default') + ' btn-ring-bearer" title="'
                    + Translator.trans('deck.ring_bearer') + '">RB</button>';
            }
        } else {
            html += '<label class="btn btn-xs ' + (qty ? 'btn-primary' : 'btn-default') + ' btn-toggle-card"><input type="checkbox"'
                + (qty ? ' checked' : '') + ' style="display:none"> ' + (qty ? Translator.trans('deck.in_deck') : Translator.trans('deck.add')) + '</label>';
        }
        return html;
    }

    function row(card)
    {
        var f = format(),
            limit = f ? app.data.format_limit(f, card.code) : null,
            stats = card.type_code === 'site'
                ? (card.site_number ? Translator.trans('card.info.site') + ' ' + card.site_number : '') + (card.shadow_number !== null && card.shadow_number !== undefined ? ' (' + card.shadow_number + ')' : '')
                : [card.strength, card.vitality].filter(function (v) { return v !== null && v !== undefined; }).join('/');
        return '<tr data-code="' + card.code + '" class="' + (limit === 'banned' ? 'danger' : '') + '">'
            + '<td class="pool-qty">' + quantity_control(card) + '</td>'
            + '<td><a class="card card-tip" data-code="' + card.code + '" href="' + card.url + '">'
            + (card.is_unique ? '&bull;' : '') + _.escape(card.label) + '</a>'
            + (card.kind === 'errata' ? ' <span class="label label-info">PC</span>' : '')
            + (limit ? ' <span class="label label-' + (limit === 'banned' ? 'danger' : 'warning') + '">' + Translator.trans('formats.list.' + limit) + '</span>' : '')
            + ' <span class="text-muted small">' + _.escape(card.coll_info || card.code) + '</span></td>'
            + '<td>' + (card.type_code === 'site' ? '' : (card.twilight !== null && card.twilight !== undefined ? card.twilight : '')) + '</td>'
            + '<td>' + stats + '</td>'
            + '<td>' + _.escape(card.type_name) + '</td>'
            + '<td>' + _.escape(card.culture_name || '') + '</td>'
            + '</tr>';
    }

    ui.refresh_list = function refresh_list()
    {
        var pool = ui.get_pool();
        pool.sort(function (a, b) {
            var x = a[sort.key], y = b[sort.key];
            if(x === y) {
                return a.label.localeCompare(b.label);
            }
            if(x === null || x === undefined) { return 1; }
            if(y === null || y === undefined) { return -1; }
            return (typeof x === 'string' ? x.localeCompare(y) : x - y) * sort.dir;
        });
        $('#collection-table').html(_.map(pool.slice(0, ROW_LIMIT), row).join(''));
        $('#collection-more').text(pool.length > ROW_LIMIT ? Translator.trans('decks.edit.build.more', {shown: ROW_LIMIT, total: pool.length}) : '');
    };

    ui.refresh_deck = function refresh_deck()
    {
        app.deck.display('#deck', {editable: true});
        app.draw_simulator && app.draw_simulator.reset();
        app.deck_charts && app.deck_charts.setup();
        var f = format();
        $('#btn-convert-pc').toggle(!!(f && f.pc_errata));
    };

    /* ---- events ---- */

    ui.on_deck_changed = function on_deck_changed()
    {
        ui.refresh_deck();
        ui.refresh_list();
        app.deck_history && app.deck_history.notify_change();
    };

    ui.setup_event_handlers = function setup_event_handlers()
    {
        $(document).on('deck.changed', ui.on_deck_changed);

        $('#collection-table').on('change', 'input[type=radio]', function () {
            app.deck.set_quantity($(this).closest('tr').data('code'), parseInt($(this).val(), 10));
        });
        $('#collection-table').on('click', '.btn-toggle-card', function (event) {
            event.preventDefault();
            var code = $(this).closest('tr').data('code');
            app.deck.set_quantity(code, app.deck.get_quantity(code) ? 0 : 1);
        });
        $('#collection-table').on('click', '.btn-ring-bearer', function () {
            var code = $(this).closest('tr').data('code');
            app.deck.set_ring_bearer(app.deck.get_ring_bearer_code() === code ? null : code);
        });

        $('#deck').on('click', '.deck-card-minus, .deck-card-plus', function (event) {
            event.preventDefault();
            var key = $(this).closest('.deck-card').data('key') + '',
                delta = $(this).hasClass('deck-card-plus') ? 1 : -1;
            if(key.indexOf('rb:') === 0) {
                app.deck.set_ring_bearer(null);
                return;
            }
            var card = app.data.cards.findById(key);
            app.deck.set_quantity(key, Math.max(0, Math.min(app.card_modal.max_quantity(card), app.deck.get_quantity(key) + delta)));
        });

        $('#cardModal').on('change', '.modal-qty input[type=radio]', function () {
            app.deck.set_quantity($('#cardModal').data('code'), parseInt($(this).val(), 10));
            $('#cardModal').modal('hide');
        });

        $('#filter-text').on('input', _.debounce(function () {
            filter_text = $(this).val();
            app.smart_filter.update(filter_text);
            ui.refresh_list();
        }, 250));

        $('[data-filter]').on('change', 'input', function () {
            $(this).closest('label').toggleClass('active', this.checked);
            ui.refresh_list();
        });

        $('#config-options').on('change', 'input', function () {
            app.config.set(this.name, this.checked);
            ui.refresh_list();
        });
        $('#config-options').on('click', 'a', function (event) {
            event.stopPropagation();
        });

        $('#collection').on('click', 'thead a[data-sort]', function (event) {
            event.preventDefault();
            var key = $(this).data('sort');
            sort = {key: key, dir: sort.key === key ? -sort.dir : 1};
            ui.refresh_list();
        });

        $('#deck-format').on('change', function () {
            app.deck.set_format_code($(this).val());
            app.deck.changed();
        });

        $('#btn-convert-pc').on('click', function () {
            var button = $(this).prop('disabled', true);
            $.ajax(Routing.generate('deck_convert'), {
                type: 'POST',
                data: {content: app.deck.get_json(), target_format: app.deck.get_format_code()},
                dataType: 'json',
                success: function (response) {
                    app.deck.set_content(response.content);
                },
                error: function () {
                    app.ui.insert_alert_message('warning', Translator.trans('deck.convert_failed'));
                },
                complete: function () {
                    button.prop('disabled', false);
                }
            });
        });

        $('#save_form').on('submit', ui.on_submit_form);
        $('#btn-cancel-edits').on('click', function () {
            $('#deck-cancel-edits').val(1);
        });

        app.markdown && app.markdown.setup('textarea[name=description_]', '#description-preview');
    };

    ui.on_submit_form = function on_submit_form()
    {
        var form = $('#save_form');
        form.find('input[name=content]').val(app.deck.get_json());
        form.find('input[name=description]').val($('textarea[name=description_]').val());
        form.find('input[name=tags]').val($('input[name=tags_]').val());
        $(window).off('beforeunload');
    };

    ui.on_dom_loaded = function on_dom_loaded()
    {
        ui.setup_event_handlers();
        app.draw_simulator && app.draw_simulator.on_dom_loaded();
        app.deck_history && app.deck_history.setup('#history');
        $('#config-options input').each(function () {
            this.checked = !!app.config.get(this.name);
        });
    };

    ui.on_data_loaded = function on_data_loaded()
    {
        ui.build_culture_buttons();
        ui.build_type_buttons();
    };

    ui.on_all_loaded = function on_all_loaded()
    {
        ui.refresh_deck();
        ui.refresh_list();
        app.deck.display_validation('#deck-validation');
        app.deck.validate();
    };

})(app.ui, jQuery);
