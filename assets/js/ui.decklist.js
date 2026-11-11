/* global app, Commenters, Translator */

(function ui_decklist(ui, $)
{

    /**
     * sets up event handlers ; dataloaded not fired yet
     * @memberOf ui
     */
    ui.setup_event_handlers = function setup_event_handlers()
    {
        // the starting fellowship's generic companions hover split, as their tiles (Ketura, 2026-11-08)
        $(document).on('deck.rendered', function () {
            $('.rb-starting-card[data-generic]').each(function () {
                var card = app.data.cards && app.data.cards.findById($(this).data('code') + '');
                if(card) {
                    var pair = app.card_versions.pair(card, app.deck.get_format());
                    $(this).attr('data-hover-split', pair[0].code + ',' + pair[1].code);
                }
            });
        });

        $('.decklist-delete').on('click', ui.delete_form);
        // a saved deck's Delete (Ketura, 2026-11-08): the deck list's confirmation
        $('.deck-delete').on('click', function ()
        {
            $('#delete-deck-name').text($(this).data('name'));
            $('#delete-deck-id').val($(this).data('id'));
            $('#deleteModal .rb-delete-published').prop('hidden', !$(this).data('published'));
            $('#deleteModal').modal('show');
        });
        // Cheers, fire and crown (app.reactions; Ketura, 2026-11-11)
        app.reactions.bind('.rb-social', function (group, level) {
            return $.post(Routing.generate('decklist_react'), {id: app.deck.get_id(), level: level});
        });
        $('#social-favorite').on('click', ui.send_favorite);
        // the lineage line opens to its tables; either table's heading closes them (Ketura, 2026-11-08)
        $('#reader-tree').on('click', '.rb-tree-summary', function () {
            $('#reader-tree').removeClass('is-collapsed').find('.rb-tree-summary').attr('aria-expanded', 'true');
        }).on('click', 'thead th', function () {
            $('#reader-tree').addClass('is-collapsed').find('.rb-tree-summary').attr('aria-expanded', 'false').focus();
        });
        $('#btn-compare').on('click', ui.compare_form);
        $('#btn-compare-submit').on('click', ui.compare_submit);
    };

    ui.delete_form = function delete_form()
    {
        $('#deleteModal').modal('show');
    };

    // the like and favourite buttons, and the comments, are app.social's (shared with
    // libraries, Ketura 2026-11-09)
    var set_pressed = app.social.set_pressed;

    function toggle(button, route, done)
    {
        app.social.toggle(button, Routing.generate(route), {id: app.deck.get_id()}, done);
    }

    ui.send_favorite = function send_favorite(event)
    {
        event.preventDefault();
        toggle($(this), 'decklist_favorite');
    };

    /** The comments (app.comments); the Comment button goes to the form */
    ui.setup_comments = function setup_comments()
    {
        $('#social-comment').on('click', function (event)
        {
            event.preventDefault();
            app.comments.focus_form();
        });
        return app.comments.setup({title: $.trim($('.decklist-name').text())});
    };

    /** The buttons show what the user has done; an author's own Cheers is theirs to take back, and that's all */
    ui.setup_social_icons = function setup_social_icons()
    {
        var user = app.user.data;
        if(!user || !$('.rb-social').length) {
            return;
        }
        // a button clicked before this arrived already shows what the server said
        var fresh = function (sel) { return !$(sel).data('touched'); },
            group = $('.rb-social .rb-reactions');
        if(fresh(group)) {
            // no animation for what was already so when the page opened
            group.addClass('rb-no-anim');
            app.reactions.render(group, {level: user.reaction || 0, own: !!user.is_author});
            void group[0].offsetWidth;
            group.removeClass('rb-no-anim');
        }
        fresh('#social-favorite') && set_pressed('#social-favorite', !!user.is_favorite);
        set_pressed('#social-comment', _.contains(Commenters || [], user.name));
    };

    /**
     * The author's Edit, and Undo Publish while the grace period lasts (Ketura, 2026-11-07);
     * Delete is a moderator's
     */
    ui.add_author_actions = function add_author_actions()
    {
        var data = app.user.data || {};
        $('.decklist-edit').toggle(!!data.is_author);
        if(!data.is_author) {
            $('.decklist-edit').remove();
        }
        if(data.can_withdraw && data.state_token) {
            var until = new Date(data.withdraw_until);
            $('.decklist-withdraw').show()
                .attr('title', Translator.trans('decklist.state.withdraw_until', {date: until.toLocaleString()}))
                .off('click').on('click', function () {
                    if(!confirm(Translator.trans('decklist.state.withdraw_confirm'))) {
                        return;
                    }
                    $.post($(this).data('url'), {_token: data.state_token}, function (response) {
                        location.href = response.url;
                    }, 'json').fail(function () {
                        alert(Translator.trans('decklist.state.withdraw_failed'));
                    });
                });
        } else {
            $('.decklist-withdraw').remove();
        }
        // the author's deck gone: Recreate brings it back, in Clone's place (Ketura, 2026-11-08)
        if(data.can_recreate && data.state_token) {
            $('.rb-reader-clone, .rb-reader-clone-item').remove();
            $('.decklist-recreate').prop('hidden', false);
            $('.decklist-recreate-item').prop('hidden', false);
            $('.decklist-recreate').off('click').on('click', function (event) {
                event.preventDefault();
                $.post($(this).data('url'), {_token: data.state_token}, function (response) {
                    location.href = response.url;
                }, 'json').fail(function () {
                    alert(Translator.trans('decklist.recreate.failed'));
                });
            });
        } else {
            $('.decklist-recreate, .decklist-recreate-item').remove();
        }
        if(data.can_delete) {
            $('.decklist-delete').show();
            // deleting someone else's decklist needs a reason (Ketura, 2026-11-11)
            $('#delete-reason').prop('required', !data.is_author).closest('.rb-delete-reason').prop('hidden', !!data.is_author);
        } else {
            $('.decklist-delete').remove();
        }
    };

    /* ---- the reader (Ketura, 2026-11-04) ---- */

    /**
     * A note and its card light up together under the pointer (Ketura, 2026-11-04); the line
     * between them and the scrolling to them went (Ketura, 2026-11-08): the note shows its card.
     */
    function deck_item(code)
    {
        return $('#deck').find('.rb-line[data-code="' + code + '"], .rb-tile[data-code="' + code + '"]').first();
    }

    function link(code)
    {
        unlink();
        $('.rb-reader-note[data-note="' + code + '"]').addClass('is-linked');
        deck_item(code).addClass('is-linked');
    }

    function unlink()
    {
        $('.is-linked').removeClass('is-linked');
    }

    /**
     * A note beside its card (Ketura, 2026-11-08): the card floats left of the note, so a long
     * note wraps under it; a note no taller than the card is centred against it instead. Fitted
     * again as the column's width changes.
     */
    function fit_notes()
    {
        $('.rb-reader-note').each(function () {
            var note = $(this), thumb = note.find('.rb-note-thumb'), body = note.find('.rb-note-body');
            if(!thumb.length) {
                return;
            }
            note.removeClass('is-short');
            note.toggleClass('is-short', body.outerHeight() <= thumb.outerHeight() + 2);
        });
    }

    ui.setup_reader = function setup_reader()
    {
        app.deck_editor.setup('#deck', '#deck-toolbar', {readonly: true});
        app.deck_stats && app.deck_stats.setup();
        $(document).on('mouseenter', '.rb-reader-note', function () { link($(this).data('note') + ''); })
            .on('mouseleave', '.rb-reader-note', unlink)
            .on('mouseenter', '#deck .rb-annot-mark', function () { link($(this).data('annot') + ''); })
            .on('mouseleave', '#deck .rb-annot-mark', unlink);
        var notes = $('#reader-notes');
        if(notes.length) {
            fit_notes();
            notes.find('img').on('load', fit_notes);
            // on the column's width only: fitting changes the height, which mustn't refit
            var width = notes.width();
            if(window.ResizeObserver) {
                new ResizeObserver(_.throttle(function () {
                    if(notes.width() !== width) {
                        width = notes.width();
                        fit_notes();
                    }
                }, 60)).observe(notes[0]);
            }
        }
        setup_splitters();
    };

    /**
     * The bars between the columns (Ketura, 2026-11-08): one sizes the tools, the other the
     * commentary; the deck takes what's left, never under 340px. Each width is remembered.
     * Narrower than 1400px the tools sit under the deck, and only the commentary's bar shows.
     */
    function setup_splitters()
    {
        var grid = $('#reader-grid'), tools = $('.rb-reader-tools'), desc = $('.rb-reader-desc');
        function width_var(name)
        {
            return function (w) {
                if(w === null) {
                    grid[0].style.removeProperty(name);
                } else {
                    grid[0].style.setProperty(name, w + 'px');
                }
            };
        }
        function beside(el)
        {
            var deck = grid.find('.rb-reader-deck')[0];
            return el.is(':visible') && Math.abs(el[0].getBoundingClientRect().top - deck.getBoundingClientRect().top) < 8 ? el.outerWidth() : 0;
        }
        ui.splitter('#reader-splitter-tools', {
            key: 'rb-reader-tools-width',
            min: 240,
            max: function () { return grid.width() - beside(desc) - 340 - 24; },
            current: function () { return tools.outerWidth(); },
            direction: 1,
            set: width_var('--rb-tools-width')
        });
        ui.splitter('#reader-splitter-desc', {
            key: 'rb-reader-desc-width',
            min: 300,
            max: function () { return grid.width() - beside(tools) - 340 - 24; },
            current: function () { return desc.outerWidth(); },
            direction: -1,
            set: width_var('--rb-desc-width')
        });
    }

    /**
     * called when the DOM is loaded
     * @memberOf ui
     */
    ui.on_dom_loaded = function on_dom_loaded()
    {
        ui.setup_event_handlers();
        ui.setup_reader();
        // who you are comes apart from the card data: the buttons needn't wait for the deck
        app.user.loaded.always(ui.setup_social_icons);
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
    /** Runs the steps one after another, letting the browser paint in between */
    function in_frames(steps)
    {
        if(!steps.length) {
            return;
        }
        // one step failing (a chart on an odd archive deck, say) mustn't keep the rest from showing
        try {
            steps[0]();
        } catch (e) {
            console.error(e);
        }
        window.requestAnimationFrame(function () {
            setTimeout(function () { in_frames(steps.slice(1)); }, 0);
        });
    }

    /**
     * called when both the DOM and the data app have finished loading. The description came
     * with the page; the deck, Stats and the comment form follow in that order, a frame
     * apart, so each shows as soon as it's ready (Ketura, 2026-11-04)
     * @memberOf ui
     */
    ui.on_all_loaded = function on_all_loaded()
    {
        in_frames([
            function () {
                app.deck_editor.render();
            },
            function () {
                $('.rb-reader-tools').removeClass('is-pending');
                app.deck_stats && app.deck_stats.refresh();
            },
            function () {
                // a saved deck has no comments (Ketura, 2026-11-08)
                if(!$('#comment-form').length) {
                    return;
                }
                ui.setup_comments();
                app.user.loaded.done(function ()
                {
                    ui.add_author_actions();
                });
            }
        ]);
    };

})(app.ui, jQuery);
