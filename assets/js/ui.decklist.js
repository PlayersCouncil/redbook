/* global app, Commenters, Translator */

(function ui_decklist(ui, $)
{

    /**
     * sets up event handlers ; dataloaded not fired yet
     * @memberOf ui
     */
    ui.setup_event_handlers = function setup_event_handlers()
    {

        $('.decklist-delete').on('click', ui.delete_form);
        $('#social-like').on('click', ui.send_like);
        $('#social-favorite').on('click', ui.send_favorite);
        $('[data-copy-list]').on('click', ui.copy_list);
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

    function toggle(button, route)
    {
        if(button.hasClass('processing')) {
            return;
        }
        button.addClass('processing');
        $.post(Routing.generate(route), {id: app.deck.get_id()}, function (data)
        {
            set_pressed(button, !button.hasClass('is-on'), data);
        }).always(function () { button.removeClass('processing'); });
    }

    ui.send_like = function send_like(event)
    {
        event.preventDefault();
        toggle($(this), 'decklist_like');
    };

    ui.send_favorite = function send_favorite(event)
    {
        event.preventDefault();
        toggle($(this), 'decklist_favorite');
    };

    ui.setup_comment_form = function setup_comment_form()
    {

        var form = $('<form method="POST" action="' + Routing.generate('decklist_comment') + '"><input type="hidden" name="id" value="' + app.deck.get_id() + '"><div class="form-group">'
                + '<textarea id="comment-form-text" class="form-control" rows="4" name="comment" placeholder="' + Translator.trans('decklist.view.comments.hint') + '"></textarea>'
                + '</div><div class="well text-muted" id="comment-form-preview"><small>Preview. Look <a href="http://daringfireball.net/projects/markdown/dingus">here</a> for a Markdown syntax reference.</small></div>'
                + '<button type="submit" class="btn btn-success">' + Translator.trans('decklist.view.comments.submit') + '</button></form>').insertAfter('#comment-form');

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

        app.markdown.setup('#comment-form-text', '#comment-form-preview');
        app.textcomplete.setup('#comment-form-text', {
            cards: true,
            icons: true,
            users: Commenters
        });

    };

    /** The buttons show what the user has done; an author can't like their own deck */
    ui.setup_social_icons = function setup_social_icons()
    {
        var user = app.user.data;
        if(!user) {
            return;
        }
        set_pressed('#social-like', !!user.is_liked);
        set_pressed('#social-favorite', !!user.is_favorite);
        set_pressed('#social-comment', _.contains(Commenters || [], user.name));
        if(user.is_author) {
            $('#social-like').prop('disabled', true).attr('title', Translator.trans('decklist.reader.own_deck'));
        }
    };

    ui.add_author_actions = function add_author_actions()
    {
        if(app.user.data && app.user.data.is_author) {
            $('.decklist-edit').show();
            if(app.user.data.can_delete) {
                $('.decklist-delete').show();
            } else {
                $('.decklist-delete').remove();
            }
        } else {
            $('.decklist-edit').remove();
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
     * A note and its card, tied by a line (Ketura, 2026-11-04): pointing at either lights
     * both and draws the line between them; one out of view is scrolled to first.
     */
    var linked = null;

    function deck_item(code)
    {
        return $('#deck').find('.rb-line[data-code="' + code + '"], .rb-tile[data-code="' + code + '"]').first();
    }

    function visible_in(el, pane)
    {
        var r = el.getBoundingClientRect(), p = pane.getBoundingClientRect();
        return r.bottom > p.top + 4 && r.top < p.bottom - 4;
    }

    function draw_link()
    {
        var path = $('#annot-link path');
        if(!linked) {
            path.attr('d', '');
            return;
        }
        var note = $('.rb-reader-note[data-note="' + linked + '"]')[0],
            item = deck_item(linked)[0];
        if(!note || !item || !visible_in(note, $('.rb-reader-desc .rb-reader-scroll')[0]) || !visible_in(item, $('#deck')[0])) {
            path.attr('d', '');
            return;
        }
        var n = note.getBoundingClientRect(), c = item.getBoundingClientRect(),
            x1 = n.left - 6, y1 = n.top + 12,
            x2 = c.right + 6, y2 = c.top + c.height / 2,
            bend = Math.max(30, (x1 - x2) / 2);
        path.attr('d', 'M' + x1 + ' ' + y1 + ' C' + (x1 - bend) + ' ' + y1 + ' ' + (x2 + bend) + ' ' + y2 + ' ' + x2 + ' ' + y2);
    }

    function link(code)
    {
        unlink();
        // the columns sit side by side only on a desktop; a phone stacks them and has no hover
        if(!window.matchMedia('(min-width: 992px)').matches) {
            return;
        }
        linked = code;
        var note = $('.rb-reader-note[data-note="' + code + '"]').addClass('is-linked'),
            item = deck_item(code).addClass('is-linked');
        _.each([[note, $('.rb-reader-desc .rb-reader-scroll')], [item, $('#deck')]], function (pair) {
            if(pair[0][0] && pair[1][0] && !visible_in(pair[0][0], pair[1][0])) {
                pair[0][0].scrollIntoView({block: 'nearest', behavior: 'smooth'});
            }
        });
        draw_link();
    }

    function unlink()
    {
        linked = null;
        $('.is-linked').removeClass('is-linked');
        draw_link();
    }

    ui.setup_reader = function setup_reader()
    {
        app.deck_editor.setup('#deck', '#deck-toolbar', {readonly: true});
        app.deck_stats && app.deck_stats.setup();
        $(document).on('mouseenter', '.rb-reader-note', function () { link($(this).data('note') + ''); })
            .on('mouseleave', '.rb-reader-note', unlink)
            .on('mouseenter', '#deck .rb-annot-mark', function () { link($(this).data('annot') + ''); })
            .on('mouseleave', '#deck .rb-annot-mark', unlink);
        // the line follows its ends as the columns scroll
        document.addEventListener('scroll', _.throttle(draw_link, 16), {passive: true, capture: true});
        $(window).on('resize', _.throttle(draw_link, 50));
    };

    /**
     * called when the DOM is loaded
     * @memberOf ui
     */
    ui.on_dom_loaded = function on_dom_loaded()
    {
        ui.setup_event_handlers();
        ui.setup_reader();
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
        steps[0]();
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
                app.user.loaded.done(function ()
                {
                    ui.setup_comment_form();
                    ui.add_author_actions();
                    ui.setup_comment_hide();
                }).fail(function ()
                {
                    $('<p>You must be logged in to post comments.</p>').insertAfter('#comment-form');
                }).always(function ()
                {
                    ui.setup_social_icons();
                });
            }
        ]);
    };

})(app.ui, jQuery);
