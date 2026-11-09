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
        $('#social-like').on('click', ui.send_like);
        $('#social-favorite').on('click', ui.send_favorite);
        $('#social-fire').on('click', ui.send_fire);
        $('[data-copy-list]').on('click', ui.copy_list);
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

    /** Copy List (Ketura, 2026-11-04): the deck as a list, straight onto the clipboard */
    ui.copy_list = function copy_list(event)
    {
        event.preventDefault();
        var anchor = $(this).is(':visible') ? this : $('.rb-reader-buttons-narrow .dropdown-toggle')[0];
        app.ui.copy_text(app.deck.get_export('list')).then(function () {
            app.ui.toast(Translator.trans('decklist.reader.copied'), anchor);
        });
    };

    /** A like or favourite button: pressed once the user has, its count from the server */
    function set_pressed(button, on, count)
    {
        $(button).toggleClass('is-on', on).attr('aria-pressed', on)
            .find('.fa-thumbs-up, .fa-bookmark, .fa-comment').toggleClass('fas', on).toggleClass('far', !on);
        if(count !== undefined) {
            $(button).find('.num').text(count);
        }
    }

    function toggle(button, route, done)
    {
        if(button.hasClass('processing')) {
            return;
        }
        button.addClass('processing').data('touched', true);
        $.post(Routing.generate(route), {id: app.deck.get_id()}, function (data)
        {
            set_pressed(button, !button.hasClass('is-on'), data);
            done && done(button.hasClass('is-on'));
        }).always(function () { button.removeClass('processing'); });
    }

    /**
     * The fire button follows the like (Ketura, 2026-11-04): liking slides it out from behind
     * Like, ready to use; unliking puts the user's fire out with it, and tucks the button away
     * again when nobody's fire is left.
     */
    function set_fire(liked, fired, count)
    {
        var fire = $('#social-fire'),
            num = count === undefined ? parseInt(fire.find('.num').text(), 10) || 0 : count;
        fire.find('.num').text(num);
        fire.prop('disabled', !liked).toggleClass('is-on', !!fired).attr('aria-pressed', !!fired)
            .attr('title', !liked ? Translator.trans('decklist.reader.fire_locked') : fire.data(fired ? 'title-off' : 'title-on'))
            .toggleClass('is-tucked', !liked && num === 0);
    }

    ui.send_like = function send_like(event)
    {
        event.preventDefault();
        toggle($(this), 'decklist_like', function (liked) {
            var fire = $('#social-fire'),
                fired = fire.hasClass('is-on'),
                num = parseInt(fire.find('.num').text(), 10) || 0;
            // the server puts out this user's fire along with the like
            set_fire(liked, liked && fired, !liked && fired ? num - 1 : num);
        });
    };

    ui.send_fire = function send_fire(event)
    {
        event.preventDefault();
        var fire = $(this);
        if(fire.hasClass('processing') || fire.prop('disabled')) {
            return;
        }
        fire.addClass('processing').data('touched', true);
        $.post(Routing.generate('decklist_fire'), {id: app.deck.get_id()}, function (data)
        {
            set_fire(true, data.on, data.fires);
        }).always(function () { fire.removeClass('processing'); });
    };

    ui.send_favorite = function send_favorite(event)
    {
        event.preventDefault();
        toggle($(this), 'decklist_favorite');
    };

    ui.setup_comment_form = function setup_comment_form()
    {

        // the deckbuilder's markdown editor (Ketura, 2026-11-08): its toolbar, Preview,
        // fullscreen, symbols and # for cards, with @ for the people in the conversation
        var form = $('<form method="POST" class="rb-comment-form" action="' + Routing.generate('decklist_comment') + '"><input type="hidden" name="id" value="' + app.deck.get_id() + '">'
                + '<div class="rb-md-editor"><textarea id="comment-form-text" rows="6" name="comment" data-md-title="' + _.escape(Translator.trans('decklist.reader.comment')) + '" placeholder="'
                + _.escape(Translator.trans('decklist.reader.comment_placeholder')) + '"></textarea></div>'
                + '<button type="submit" class="btn btn-success">' + Translator.trans('decklist.view.comments.submit') + '</button></form>').insertAfter('#comment-form');
        app.md_editor.attach('#comment-form-text', {
            users: Commenters,
            footer: Translator.trans('decklist.reader.comment_hint'),
            deck: $.trim($('.decklist-name').text())
        });

        var already_submitted = false;
        form.on('submit', function (event)
        {
            event.preventDefault();
            var data = $(this).serialize();
            if(already_submitted)
                return;
            already_submitted = true;
            $.ajax(Routing.generate('decklist_comment'), {
                data: data,
                type: 'POST',
                success: function (data, textStatus, jqXHR)
                {
                    form.replaceWith('<div class="alert alert-success" role="alert">Your comment has been posted. It will appear on the site in a few minutes.</div>');
                },
                error: function (jqXHR, textStatus, errorThrown)
                {
                    console.log('[' + moment().format('YYYY-MM-DD HH:mm:ss') + '] Error on ' + this.url, textStatus, errorThrown);
                    form.replaceWith('<div class="alert alert-danger" role="alert">An error occured while posting your comment (' + jqXHR.statusText + '). Reload the page and try again.</div>');
                }
            });
        });

        $('#social-comment').on('click', function (event)
        {
            event.preventDefault();
            $('#comment-form-text').trigger('focus')[0].scrollIntoView({block: 'nearest', behavior: 'smooth'});
        });


    };

    /** The buttons show what the user has done; an author can't like their own deck */
    ui.setup_social_icons = function setup_social_icons()
    {
        var user = app.user.data;
        if(!user || !$('.rb-social').length) {
            return;
        }
        // a button clicked before this arrived already shows what the server said
        var fresh = function (sel) { return !$(sel).data('touched'); },
            liked = fresh('#social-like') ? !!user.is_liked : $('#social-like').hasClass('is-on');
        fresh('#social-like') && set_pressed('#social-like', liked);
        fresh('#social-favorite') && set_pressed('#social-favorite', !!user.is_favorite);
        set_pressed('#social-comment', _.contains(Commenters || [], user.name));
        if(user.is_author) {
            $('#social-like').prop('disabled', true).attr('title', Translator.trans('decklist.reader.own_deck'));
        }
        if(fresh('#social-fire') && fresh('#social-like')) {
            // no animation for what was already so when the page opened
            $('#social-fire').addClass('rb-no-anim');
            set_fire(liked && !user.is_author, !!user.is_fired);
            void $('#social-fire')[0].offsetWidth;
            $('#social-fire').removeClass('rb-no-anim');
        }
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
        } else {
            $('.decklist-delete').remove();
        }
    };

    ui.setup_comment_hide = function setup_comment_hide()
    {
        if(app.user.data && app.user.data.is_author) {
            $('.comment-hide-button').remove();
            $('<a href="#" class="comment-hide-button"><span class="text-danger" style="margin-left:.5em"><i class="fas fa-times"></i></span></a>').appendTo('.collapse.in > .comment-date').on('click', function (event)
            {
                if(confirm('Do you really want to hide this comment for everybody?')) {
                    ui.hide_comment($(this).closest('td'));
                }
                return false;
            });
            $('<a href="#" class="comment-hide-button"><span class="text-success" style="margin-left:.5em"><i class="fas fa-check"></i></span></a>').appendTo('.collapse:not(.in) > .comment-date').on('click', function (event)
            {
                if(confirm('Do you really want to unhide this comment?')) {
                    ui.unhide_comment($(this).closest('td'));
                }
                return false;
            });
        }
    };

    ui.hide_comment = function hide_comment(element)
    {
        var id = element.attr('id').replace(/comment-/, '');
        $.ajax(Routing.generate('decklist_comment_hide', {comment_id: id, hidden: 1}), {
            type: 'POST',
            dataType: 'json',
            success: function (data, textStatus, jqXHR)
            {
                if(data === true) {
                    $(element).find('.collapse').collapse('hide');
                    $(element).find('.comment-toggler').show().prepend('The comment will be hidden for everyone in a few minutes.');
                    setTimeout(ui.setup_comment_hide, 1000);
                } else {
                    alert(data);
                }
            },
            error: function (jqXHR, textStatus, errorThrown)
            {
                console.log('[' + moment().format('YYYY-MM-DD HH:mm:ss') + '] Error on ' + this.url, textStatus, errorThrown);
                alert('An error occured while hiding this comment (' + jqXHR.statusText + '). Reload the page and try again.');
            }
        });
    };

    ui.unhide_comment = function unhide_comment(element)
    {
        var id = element.attr('id').replace(/comment-/, '');
        $.ajax(Routing.generate('decklist_comment_hide', {comment_id: id, hidden: 0}), {
            type: 'POST',
            dataType: 'json',
            success: function (data, textStatus, jqXHR)
            {
                if(data === true) {
                    $(element).find('.collapse').collapse('show');
                    $(element).find('.comment-toggler').hide();
                    setTimeout(setup_comment_hide, 1000);
                } else {
                    alert(data);
                }
            },
            error: function (jqXHR, textStatus, errorThrown)
            {
                console.log('[' + moment().format('YYYY-MM-DD HH:mm:ss') + '] Error on ' + this.url, textStatus, errorThrown);
                alert('An error occured while unhiding this comment (' + jqXHR.statusText + '). Reload the page and try again.');
            }
        });
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
                app.user.loaded.done(function ()
                {
                    ui.setup_comment_form();
                    ui.add_author_actions();
                    ui.setup_comment_hide();
                }).fail(function ()
                {
                    $('<p>You must be logged in to post comments.</p>').insertAfter('#comment-form');
                });
            }
        ]);
    };

})(app.ui, jQuery);
