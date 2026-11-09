/* global app, _, Translator, Routing */
/**
 * The deckbuilder page (Ketura's region design, 2026-11-02).
 *
 * Deck information ("pink"): name; format, with Suggest only while no format is
 * chosen and Convert only in a PC format when there is errata to convert; Gemp's
 * verdict; Save / Revert / Visibility / More; Historical Deck Information.
 *
 * Saving (Ketura, 2026-11-02): every change is kept as a draft in this browser
 * (localStorage); Save records a version on the server and clears the draft;
 * Revert throws the draft away. A draft started from an older version is offered
 * back, never applied silently.
 *
 * The deck list is app.deck_editor; the filters and card pool are app.pool.
 */
(function ui_deckedit(ui, $)
{
    var page = {},
        saved_state = null,
        saved_version = null,
        dirty = false,
        status_note = null,
        draft_key = null,
        draft_timer = null,
        visibility = 'private';

    function t(key, params)
    {
        return Translator.trans(key, params || {});
    }

    /* ---- the browser draft ---- */

    function storage()
    {
        try {
            return window.localStorage;
        } catch (e) {
            return null;
        }
    }

    function read_draft()
    {
        var s = storage();
        if(!s || !draft_key) {
            return null;
        }
        try {
            return JSON.parse(s.getItem(draft_key) || 'null');
        } catch (e) {
            return null;
        }
    }

    function write_draft()
    {
        var s = storage();
        if(!s || !draft_key) {
            return;
        }
        try {
            if(dirty) {
                s.setItem(draft_key, JSON.stringify({base_version: saved_version, saved_at: new Date().toISOString(), state: app.deck.get_state()}));
            } else {
                s.removeItem(draft_key);
            }
        } catch (e) {
            // a full or blocked storage just means no draft
        }
    }

    function discard_draft()
    {
        var s = storage();
        try {
            s && draft_key && s.removeItem(draft_key);
        } catch (e) {
            // nothing to do
        }
    }

    function same(a, b)
    {
        return JSON.stringify(normalize(a)) === JSON.stringify(normalize(b));
    }

    function normalize(state)
    {
        var sorted = {};
        _.each(_.keys(state.content || {}).sort(), function (k) { sorted[k] = state.content[k]; });
        return {
            name: state.name || '', target_format: state.target_format || '', description_md: state.description_md || '',
            tags: state.tags || '', historic: state.historic || null, starting: (state.starting || []).slice().sort(), content: sorted,
            starting_bid: state.starting_bid || '', starting_notes: state.starting_notes || '', annotations: state.annotations || [], pending: (state.pending || []).slice().sort()
        };
    }

    /** After any edit: work out whether there is anything unsaved, keep the draft */
    function on_edit()
    {
        dirty = !same(app.deck.get_state(), saved_state);
        status_note = null;
        update_status();
        clearTimeout(draft_timer);
        draft_timer = setTimeout(write_draft, 300);
    }

    function update_status()
    {
        var el = $('#save-status');
        if(dirty) {
            el.text(t('deckbuilder.info.unsaved')).attr('class', 'rb-save-status is-dirty');
        } else {
            el.text(status_note || t('deckbuilder.info.saved_version', {version: saved_version})).attr('class', 'rb-save-status is-saved');
        }
        $('#btn-save').prop('disabled', !dirty);
        $('#btn-revert').prop('disabled', !dirty);
        show_publish();
    }

    function offer_old_draft(draft)
    {
        var banner = $('#draft-banner');
        banner.html('<span>' + t('deckbuilder.draft.old', {version: _.escape(draft.base_version), when: moment(draft.saved_at).fromNow()}) + '</span> '
            + '<button type="button" class="rb-btn rb-btn-small" data-draft="restore">' + t('deckbuilder.draft.restore') + '</button> '
            + '<button type="button" class="rb-btn rb-btn-small" data-draft="discard">' + t('deckbuilder.draft.discard') + '</button>').prop('hidden', false);
        banner.one('click', '[data-draft]', function () {
            if($(this).data('draft') === 'restore') {
                app.deck.set_state(draft.state);
                fill_fields();
                app.deck.changed();
            } else {
                discard_draft();
            }
            banner.prop('hidden', true).empty();
        });
    }

    /* ---- save, revert ---- */

    function payload(copy, verify)
    {
        var historic = app.deck.get_historic();
        return {
            verify: verify ? 1 : 0,
            id: app.deck.get_id(),
            copy: copy ? 1 : 0,
            format: 'json',
            name: app.deck.get_name() || '',
            content: app.deck.get_json(),
            target_format: app.deck.get_format_code() || '',
            description: app.deck.get_description_md() || '',
            tags: app.deck.get_tags() || '',
            historic: historic ? JSON.stringify(historic) : '',
            starting: JSON.stringify(app.deck.get_starting()),
            starting_bid: app.deck.get_starting_bid(),
            starting_notes: app.deck.get_starting_notes(),
            annotations: JSON.stringify(app.deck.get_annotations())
        };
    }

    function save(copy, done, verify)
    {
        $('#btn-save, #btn-save-verify').prop('disabled', true);
        $('#save-status').text(t('deckbuilder.info.saving')).attr('class', 'rb-save-status');
        $.ajax(Routing.generate('deck_save'), {
            type: 'POST',
            data: payload(copy, verify),
            dataType: 'json',
            success: function (response) {
                if(copy) {
                    window.location = response.edit_url;
                    return;
                }
                discard_draft();
                if(response.version !== saved_version) {
                    // a revision now (Ketura, 2026-11-08)
                    page.unrevised = null;
                }
                app.deck.init(response.deck);
                if(response.history && app.deck_history) {
                    app.deck_history.init(response.history);
                    app.deck_history.setup('#history');
                    app.deck_history.render();
                }
                saved_state = app.deck.get_state();
                saved_version = response.version;
                app.deck.changed();
                dirty = false;
                status_note = t(verify ? 'historian.review.verified_now' : 'deckbuilder.info.saved_now', {version: saved_version});
                if(response.review) {
                    page.review = response.review;
                    show_review();
                }
                update_status();
                $('#btn-save-verify').prop('disabled', false);
                done && done();
            },
            error: function () {
                $('#save-status').text(t('deckbuilder.info.save_failed')).attr('class', 'rb-save-status is-dirty');
                $('#btn-save, #btn-save-verify').prop('disabled', false);
            }
        });
    }

    function revert()
    {
        app.deck.set_state(saved_state);
        discard_draft();
        fill_fields();
        app.deck.changed();
        dirty = false;
        status_note = t('deckbuilder.info.reverted', {version: saved_version});
        update_status();
    }

    /* ---- the historian's review of an archive deck (Ketura, 2026-11-02) ---- */

    function flag_text(flag)
    {
        var detail = flag.detail || '';
        switch(flag.code) {
            case 'unresolved':
                return Translator.transChoice('historian.review.flag.unresolved', parseInt(detail, 10) || 0, {count: detail});
            case 'format_guessed':
            case 'problems':
            case 'parse':
            case 'submitted':
                return t('historian.review.flag.' + flag.code, {detail: detail});
            default:
                return t('historian.review.flag.' + flag.code);
        }
    }

    function show_review()
    {
        var review = page.review;
        if(!review) {
            return;
        }
        $('#review-panel').attr('class', 'rb-review is-' + review.status);
        $('#review-status').text(review.status === 'verified'
            ? t('historian.review.verified_by', {name: review.reviewed_by || '?', date: review.reviewed_at || ''})
            : t('historian.review.unverified'));
        $('#review-flags').html(_.map(review.flags || [], function (flag) {
            return '<li class="rb-flag rb-flag-' + _.escape(flag.code) + '">' + _.escape(flag_text(flag)) + '</li>';
        }).join('')).prop('hidden', review.status === 'verified' || !(review.flags || []).length);
        $('#btn-save-verify').text(t(review.status === 'verified' ? 'historian.review.save_update' : 'historian.review.save_verify'));
    }

    /* ---- fields ---- */

    function fill_fields()
    {
        $('#deck-name').val(app.deck.get_name() || '');
        $('#deck-format').val(app.deck.get_format_code() || '');
        $('textarea[name=description_]').val(app.deck.get_description_md() || '');
        $('input[name=tags_]').val(app.deck.get_tags() || '');
        app.deck_notes && app.deck_notes.fill();
        var historic = app.deck.get_historic();
        $('[data-historic]').each(function () {
            $(this).val(historic ? historic[$(this).data('historic')] || '' : '');
        });
        update_format_buttons();
    }

    /** The Archive info fields; the tab only exists for decks with a history (Ketura, 2026-11-03) */
    function read_historic()
    {
        if(!$('#historic-fields').length) {
            return app.deck.get_historic();
        }
        var historic = {};
        $('[data-historic]').each(function () {
            historic[$(this).data('historic')] = $(this).val();
        });
        return historic;
    }

    /** Suggest only without a format; Convert only in a PC format with errata to convert */
    function update_format_buttons()
    {
        var format = app.deck.get_format(),
            code = app.deck.get_format_code();
        $('#btn-suggest-format').prop('hidden', !!code);
        if(code) {
            $('#format-suggestions').prop('hidden', true);
        }
        var convertible = 0;
        if(format && format.pc_errata && app.data.cards) {
            var sets = format.sets || [],
                errata_of = {};
            app.data.cards.find({kind: 'errata'}).forEach(function (card) {
                if(card.errataOf_code && (!sets.length || sets.indexOf(card.pack_code) !== -1)) {
                    errata_of[card.errataOf_code] = true;
                }
            });
            _.each(app.deck.get_content(), function (qty, key) {
                var code = key.indexOf('rb:') === 0 ? key.substr(3) : key;
                if(errata_of[code]) {
                    convertible += key.indexOf('rb:') === 0 ? 1 : qty;
                }
            });
        }
        $('#btn-convert-pc').prop('hidden', !convertible).text(Translator.transChoice('deckbuilder.info.convert', convertible, {count: convertible}));
    }

    /* ---- visibility ---- */

    function show_visibility()
    {
        var icons = {
                'private': '<svg width="15" height="15" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="7" width="10" height="7" rx="1.5"/><path d="M5.5 7V5a2.5 2.5 0 0 1 5 0v2"/></svg>',
                hidden: '<svg width="15" height="15" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6.5 9.5l3-3M5 8l-1.5 1.5a2.1 2.1 0 0 0 3 3L8 11M11 8l1.5-1.5a2.1 2.1 0 0 0-3-3L8 5"/></svg>'
            };
        // who can see the deck as it stands; its publications are public anyway (Ketura, 2026-11-08)
        $('#btn-visibility').html(icons[visibility] + ' <span>' + t('deckbuilder.visibility.' + visibility) + '</span> <span aria-hidden="true">&#9662;</span>')
            .attr('title', t('deckbuilder.visibility.label'));
        $('#menu-visibility [data-visibility]').each(function () {
            var on = $(this).data('visibility') === visibility;
            $(this).attr('aria-checked', on).toggleClass('active', on);
        });
        show_publish();
    }

    /**
     * Publish, with the version it makes (Ketura, 2026-11-08): 1.0 the first time, then the
     * next of its line. A deck still as it was published has nothing new to publish.
     */
    function show_publish()
    {
        var button = $('#btn-publish'),
            same = page.unrevised && !dirty;
        button.html('<svg width="15" height="15" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" aria-hidden="true"><circle cx="8" cy="8" r="6"/><path d="M2 8h12M8 2c2 2 2 10 0 12M8 2c-2 2-2 10 0 12"/></svg> '
                + _.escape(same ? t('deckbuilder.publish.published_as', {version: page.unrevised.version})
                    : (page.next_publication ? t('deckbuilder.publish.version', {version: page.next_publication}) : t('deckbuilder.publish.first'))))
            .prop('disabled', !!same)
            .attr('title', same ? t('deckbuilder.publish.same_hint') : t('deckbuilder.publish.hint'));
    }

    function publish()
    {
        // publishing works on the saved deck
        var go = function () { window.location = page.publish_url; };
        return dirty ? save(false, go) : go();
    }

    function set_visibility(choice)
    {
        $.ajax(Routing.generate('deck_visibility'), {
            type: 'POST',
            data: {id: app.deck.get_id(), visibility: choice},
            dataType: 'json',
            success: function () {
                visibility = choice;
                show_visibility();
            }
        });
    }

    /* ---- a deck as it was published (Ketura, 2026-11-08) ---- */

    /*
     * Greyed until a revision begins: the New Revision button, or any change asks first.
     * Looking around (the tabs, the view options, the filters, Preview, exports) doesn't.
     */
    var locked = false,
        pending_target = null,
        EDITS = '#deck-info input, #deck-info select, #deck-info textarea, #deck-info button, #deck [data-qty], #deck [data-start], #deck [data-find], '
            + '#pool [data-add], #pool [data-rb], #tab-pane-infos input, #tab-pane-infos textarea, #tab-pane-infos select, #tab-pane-infos button, '
            + '#tab-pane-history a[role=button], #tab-pane-archive button, #tab-pane-archive input, #tab-pane-archive select, #tab-pane-archive textarea',
        NOT_EDITS = '#btn-preview, #btn-more, #btn-visibility, #btn-publish, .rb-menu, .rb-menu *';

    function edit_target(element)
    {
        var target = $(element).closest(EDITS);
        return target.length && !target.is(NOT_EDITS) ? target : null;
    }

    function guard(event)
    {
        if(!locked) {
            return;
        }
        var target = edit_target(event.target);
        if(!target) {
            return;
        }
        event.preventDefault();
        event.stopImmediatePropagation();
        if(event.type === 'click' || event.type === 'focusin') {
            pending_target = target[0];
            if(event.type === 'focusin') {
                target.trigger('blur');
            }
            $('#revision-prompt').modal('show');
        }
    }

    function unlock()
    {
        locked = false;
        $('.rb-builder').removeClass('is-locked');
        $('#revision-banner').remove();
    }

    function setup_lock()
    {
        if(!page.unrevised) {
            return;
        }
        locked = true;
        $('.rb-builder').addClass('is-locked');
        // what there is to read first: the notes (Ketura, 2026-11-08)
        $('a[href="#tab-pane-infos"]').tab('show');
        _.each(['mousedown', 'click', 'focusin', 'keydown'], function (type) {
            document.addEventListener(type, guard, true);
        });
        $('#btn-new-revision').on('click', function () {
            pending_target = null;
            $('#revision-prompt').modal('show');
        });
        $('#revision-begin').on('click', function () {
            var target = pending_target;
            unlock();
            $('#revision-prompt').modal('hide');
            // carry on with what was asked for
            if(target) {
                $(target).is('input, textarea, select') ? $(target).trigger('focus') : target.click();
            }
        });
    }

    /* ---- menus ---- */

    function close_menus()
    {
        $('.rb-menu').prop('hidden', true);
        $('[aria-haspopup=menu]').attr('aria-expanded', 'false');
    }

    /**
     * Menus and popovers hang off their button against the window, not the pane
     * they're in, so a scrolling pane can't cut them off (Ketura, 2026-11-03).
     */
    function float_below(button, popup, align_right)
    {
        var rect = $(button)[0].getBoundingClientRect(),
            el = $(popup),
            width = el.outerWidth(),
            height = el.outerHeight(),
            left = align_right ? rect.right - width : rect.left,
            top = rect.bottom + 4;
        left = Math.max(8, Math.min(left, window.innerWidth - width - 8));
        if(top + height > window.innerHeight - 8 && rect.top - 4 - height > 8) {
            top = rect.top - 4 - height;   // no room below: open upwards
        }
        el.css({position: 'fixed', left: left + 'px', top: top + 'px', right: 'auto'});
    }

    function toggle_menu(button, menu)
    {
        var open = $(menu).prop('hidden');
        close_menus();
        $(menu).prop('hidden', !open);
        $(button).attr('aria-expanded', open ? 'true' : 'false');
        if(open) {
            float_below(button, menu, false);
        }
    }

    /** Hand the deck to the historians: saved first, then published for review */
    function submit_historic()
    {
        if(!window.confirm(t('deckbuilder.more.submit_historic_confirm'))) {
            return;
        }
        var go = function () {
            $.ajax(Routing.generate('deck_submit_historic'), {
                type: 'POST',
                data: {id: app.deck.get_id()},
                dataType: 'json',
                success: function (response) {
                    // back with the Archive info tab, to fill in the deck's history
                    window.location = response.edit_url + '#archive';
                },
                error: function () {
                    $('#save-status').text(t('deckbuilder.info.save_failed')).attr('class', 'rb-save-status is-dirty');
                }
            });
        };
        return dirty ? save(false, go) : go();
    }

    function more_action(action)
    {
        switch(action) {
            case 'copy':
                save(true);
                break;
            case 'link':
                copy_text(page.view_url);
                $('#save-status').text(visibility === 'private' ? t('deckbuilder.more.link_private') : t('deckbuilder.more.link_copied'));
                break;
            case 'export-gemp':
                app.ui.export_gemp(app.deck);
                break;
            case 'export-text':
                app.ui.export_plaintext(app.deck);
                break;
            case 'history':
                $('#tab-header-history').tab('show');
                break;
            case 'submit-historic':
                submit_historic();
                break;
            case 'delete':
                $('#delete-deck-id').val(app.deck.get_id());
                $('#delete-deck-name').text(app.deck.get_name());
                $('#deleteModal .rb-delete-published').prop('hidden', !page.published);
                discard_draft();
                $('#deleteModal').modal('show');
                break;
        }
    }

    function copy_text(text)
    {
        if(navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text);
            return;
        }
        var area = $('<textarea>').val(text).appendTo('body').select();
        try {
            document.execCommand('copy');
        } catch (e) {
            // nothing more to try
        }
        area.remove();
    }

    /* ---- format suggestions ---- */

    function show_format_suggestions(response)
    {
        var pop = $('#format-suggestions');
        if(!response.suggestions.length) {
            pop.html('<p class="rb-muted">' + t('deck.suggest_none') + '</p>').prop('hidden', false);
            float_below('#deck-format', pop, true);
            return;
        }
        pop.html('<div class="rb-muted">' + t('deckbuilder.info.suggest_intro') + '</div>' + _.map(response.suggestions, function (s) {
            var gemp = s.gemp === null ? '' : (s.gemp.valid ? t('deck.suggest_legal') : t('deck.suggest_problems', {count: s.gemp.problems}));
            return '<button type="button" class="rb-suggestion" data-format="' + s.code + '" data-name="' + _.escape(s.name) + '">'
                + '<strong>' + _.escape(s.name) + '</strong><span>' + _.escape(gemp) + '</span>'
                + '<small>' + _.map(s.reasons, _.escape).join('; ') + '</small></button>';
        }).join('')).prop('hidden', false);
        float_below('#deck-format', pop, true);
    }

    /* ---- wiring ---- */

    ui.setup_event_handlers = function setup_event_handlers()
    {
        $(document).on('deck.changed', function () {
            on_edit();
            update_format_buttons();
        });

        $('#deck-name').on('input', function () {
            app.deck.set_name($(this).val());
            on_edit();
        });
        $('#deck-format').on('change', function () {
            app.deck.set_format_code($(this).val());
            app.deck.changed();
        }).on('rb:format', update_format_buttons);
        $('textarea[name=description_]').on('input', function () {
            app.deck.set_description_md($(this).val());
            on_edit();
        });
        $('input[name=tags_]').on('input', function () {
            app.deck.set_tags($(this).val());
            on_edit();
        });

        $('#historic-fields').on('input change', '[data-historic]', function () {
            app.deck.set_historic(read_historic());
            on_edit();
        });

        $('#btn-save').on('click', function () { save(false); });
        $('#btn-save-verify').on('click', function () { save(false, null, true); });

        // the Archive info tab: one click adds a candidate card, or searches the pool for an unmatched line
        $('#tab-pane-archive').on('click', '[data-archive-add]', function () {
            var code = String($(this).data('archive-add')),
                card = app.data.cards.findById(code);
            if(!card) {
                return;
            }
            if($(this).data('archive-section') === 'ringBearer') {
                app.deck.set_ring_bearer(code);
            } else {
                app.deck.add_one(code);
            }
            var shown = app.deck.get_quantity(code) || (app.deck.get_ring_bearer_code() === code ? 1 : 0);
            $(this).addClass('is-added').attr('data-count', shown);
            status_note = null;
            $('#save-status').text(t('historian.review.added', {name: card.name})).attr('class', 'rb-save-status is-dirty');
        });
        $('#tab-pane-archive').on('click', '[data-archive-search]', function () {
            var text = String($(this).data('archive-search'))
                .replace(/\([^)]*\)/g, ' ')              // "(start)", "(TTT)"
                .replace(/^\s*\d+\s*x?\s+|\s+x?\s*\d+\s*$/gi, ' ')   // "3x Sting", "Sting x3"
                .replace(/\s+/g, ' ').trim();
            $('a[href="#tab-pane-build"]').tab('show');
            app.pool.search(text);
        });
        $('#btn-revert').on('click', revert);

        $('#btn-visibility').on('click', function (event) {
            event.stopPropagation();
            toggle_menu(this, '#menu-visibility');
        });
        $('#btn-publish').on('click', publish);
        $('#menu-visibility').on('click', '[data-visibility]', function () {
            close_menus();
            set_visibility($(this).data('visibility'));
        });
        $('#btn-more').on('click', function (event) {
            event.stopPropagation();
            toggle_menu(this, '#menu-more');
        });
        $('#menu-more').on('click', '[data-action]', function () {
            close_menus();
            more_action($(this).data('action'));
        });
        // a floating menu would drift from its button if its pane scrolled: close it
        document.addEventListener('scroll', function (event) {
            if(!$(event.target).closest('.rb-menu, .rb-popover').length) {
                close_menus();
                $('#format-suggestions').prop('hidden', true);
            }
        }, {passive: true, capture: true});
        $(window).on('resize', function () {
            close_menus();
            $('#format-suggestions').prop('hidden', true);
        });
        $(document).on('click', function (event) {
            if(!$(event.target).closest('.rb-menu, .rb-popover, #btn-suggest-format').length) {
                close_menus();
                $('#format-suggestions').prop('hidden', true);
            }
        }).on('keydown', function (event) {
            if(event.key === 'Escape') {
                close_menus();
                $('#format-suggestions').prop('hidden', true);
            }
        });

        $('#btn-suggest-format').on('click', function () {
            var button = $(this).prop('disabled', true);
            $.ajax(Routing.generate('deck_suggest_format'), {
                type: 'POST',
                data: {content: app.deck.get_json(), effective_date: app.deck.get_effective_date()},
                dataType: 'json',
                success: show_format_suggestions,
                complete: function () { button.prop('disabled', false); }
            });
        });
        $('#format-suggestions').on('click', '[data-format]', function () {
            var code = $(this).data('format');
            if(!$('#deck-format option[value="' + code + '"]').length) {
                $('#deck-format').append($('<option>').val(code).text($(this).data('name')));
            }
            $('#deck-format').val(code).trigger('change');
            $('#format-suggestions').prop('hidden', true);
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
                    app.ui.insert_alert_message('warning', t('deck.convert_failed'));
                },
                complete: function () {
                    button.prop('disabled', false);
                }
            });
        });

        // the + buttons in the deck list point the pool at the Build tab
        $(document).on('deckbuilder.find', function () {
            $('a[href="#tab-pane-build"]').tab('show');
        });
        $('a[data-toggle=tab]').on('shown.bs.tab', function () {
            $(window).trigger('resize');
        });

        // the Notes tab: tags, starting notes, card annotations, the description's preview
        app.deck_notes && app.deck_notes.setup(on_edit);
    };

    ui.on_dom_loaded = function on_dom_loaded()
    {
        page = app.deckbuilder_page || {};
        visibility = app.deck.is_shared() ? 'hidden' : 'private';
        ui.setup_event_handlers();
        app.deck_editor.setup('#deck', '#deck-toolbar');
        // the deck pane resizes against the cards pane (Ketura, 2026-11-08)
        var row = $('.rb-builder-row'), left = $('.rb-col-left');
        ui.splitter('#builder-splitter', {
            key: 'rb-builder-left-width',
            min: 480,
            max: function () { return row.width() - 560; },
            current: function () { return left.outerWidth(); },
            direction: 1,
            set: function (w) {
                if(w === null) {
                    row[0].style.removeProperty('--rb-builder-left');
                } else {
                    row[0].style.setProperty('--rb-builder-left', w + 'px');
                }
            }
        });
        app.pool.setup('#pool-filters', '#pool');
        app.deck_stats && app.deck_stats.setup();
        app.deck_history && app.deck_history.setup('#history');
    };

    ui.on_data_loaded = function on_data_loaded()
    {
        app.pool.on_data_loaded();
    };

    ui.on_all_loaded = function on_all_loaded()
    {
        draft_key = 'redbook.draft.' + app.deck.get_uuid();
        saved_state = app.deck.get_state();
        saved_version = app.deck.get_version();

        var draft = read_draft();
        if(draft && draft.state) {
            if(draft.base_version === saved_version) {
                app.deck.set_state(draft.state);
            } else {
                offer_old_draft(draft);
            }
        }
        fill_fields();
        app.deck_history && app.deck_history.render();
        // a draft carried on from before means its revision had begun
        if(!same(app.deck.get_state(), saved_state)) {
            page.unrevised = null;
            $('#revision-banner').remove();
        }
        setup_lock();
        show_visibility();
        show_review();
        if(window.location.hash === '#archive') {
            $('#tab-header-archive').tab('show');
        }
        dirty = !same(app.deck.get_state(), saved_state);
        update_status();
        app.deck_editor.render();
        app.pool.refresh(true);
        app.deck.display_validation('#deck-validation');
        // Gemp's verdict is saved with the deck: ask again only when this isn't the saved
        // deck (a draft came back), the format differs, or no verdict was kept (Ketura, 2026-11-03)
        var stored = app.deck.get_validation(),
            format = app.deck.get_format_code();
        if(format && (dirty || !stored || stored.format_code !== format)) {
            app.deck.validate();
        }
    };

})(app.ui, jQuery);
