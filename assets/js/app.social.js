/* global app, Translator, Routing, _, moment */

/*
 * Likes, favourites and comments (Ketura, 2026-11-09), shared by a decklist's reader and a
 * library's view: the toggle buttons, the comment form under the comments, and the owner's
 * hide and unhide on each comment.
 */
(function app_social(social, $)
{
    /** A like or favourite button: pressed once the user has, with its count */
    social.set_pressed = function set_pressed(button, on, count)
    {
        $(button).toggleClass('is-on', on).attr('aria-pressed', on)
            .find('.fa-comment').toggleClass('fas', on).toggleClass('far', !on);
        if(count !== undefined) {
            $(button).find('.num').text(count);
        }
    };

    /**
     * Posts the click; the server answers the new count (a number), or {count, on}. done(on)
     * after. A second click while the first is out is ignored.
     */
    social.toggle = function toggle(button, url, data, done)
    {
        button = $(button);
        if(button.hasClass('processing') || button.prop('disabled')) {
            return;
        }
        button.addClass('processing').data('touched', true);
        $.post(url, data, function (answer)
        {
            var on = answer && answer.on !== undefined ? !!answer.on : !button.hasClass('is-on'),
                count = answer && answer.count !== undefined ? answer.count : answer;
            social.set_pressed(button, on, count);
            done && done(on);
        }).always(function () { button.removeClass('processing'); });
    };

    /**
     * The comment form, after anchor: the deckbuilder's markdown editor, then Post. options:
     * url, fields (hidden inputs), users (for @), title (for # suggestions), posted(answer,
     * form) when the server has it (default: a note that it will show shortly)
     */
    social.comment_form = function comment_form(anchor, options)
    {
        var hidden = _.map(options.fields || {}, function (value, name) {
                return '<input type="hidden" name="' + _.escape(name) + '" value="' + _.escape(value) + '">';
            }).join(''),
            form = $('<form method="POST" class="rb-comment-form" action="' + _.escape(options.url) + '">' + hidden
                + '<div class="rb-md-editor"><textarea id="comment-form-text" rows="6" name="comment" data-md-title="' + _.escape(Translator.trans('decklist.reader.comment')) + '" placeholder="'
                + _.escape(Translator.trans('decklist.reader.comment_placeholder')) + '"></textarea></div>'
                + '<button type="submit" class="btn btn-primary">' + Translator.trans('decklist.view.comments.submit') + '</button></form>').insertAfter(anchor);
        app.md_editor.attach('#comment-form-text', {
            users: options.users || [],
            footer: Translator.trans('decklist.reader.comment_hint'),
            deck: options.title || ''
        });
        var sent = false;
        form.on('submit', function (event)
        {
            event.preventDefault();
            if(sent || !$.trim(form.find('textarea').val())) {
                return;
            }
            sent = true;
            $.ajax(options.url, {
                data: form.serialize(),
                type: 'POST',
                success: function (answer)
                {
                    if(options.posted) {
                        options.posted(answer, form);
                    } else {
                        form.replaceWith('<div class="alert alert-success" role="alert">Your comment has been posted. It will appear on the site in a few minutes.</div>');
                    }
                },
                error: function (jqXHR, textStatus, errorThrown)
                {
                    console.log('[' + moment().format('YYYY-MM-DD HH:mm:ss') + '] Error on ' + this.url, textStatus, errorThrown);
                    form.replaceWith('<div class="alert alert-danger" role="alert">An error occured while posting your comment (' + jqXHR.statusText + '). Reload the page and try again.</div>');
                }
            });
        });
        return form;
    };

    /**
     * The owner's hide and unhide on each comment (in its date line); url(id, hidden) is the
     * address to post. A hidden comment folds away for everyone, with an eye to open it.
     */
    social.comment_hiding = function comment_hiding(root, url)
    {
        root = $(root);
        function buttons()
        {
            root.find('.comment-hide-button').remove();
            root.find('[id^="comment-"]').each(function () {
                var item = $(this), hidden = !item.find('.collapse').hasClass('in');
                $('<a href="#" class="comment-hide-button" title="' + (hidden ? 'Unhide' : 'Hide') + '"><span class="' + (hidden ? 'text-success' : 'text-danger')
                    + '" style="margin-left:.5em"><i class="fas ' + (hidden ? 'fa-check' : 'fa-times') + '"></i></span></a>')
                    .appendTo(item.find('.comment-date').first())
                    .on('click', function (event) {
                        event.preventDefault();
                        if(confirm(hidden ? 'Do you really want to unhide this comment?' : 'Do you really want to hide this comment for everybody?')) {
                            set(item, !hidden);
                        }
                    });
            });
        }
        function set(item, hide)
        {
            var id = item.attr('id').replace(/comment-/, '');
            $.ajax(url(id, hide ? 1 : 0), {
                type: 'POST',
                dataType: 'json',
                success: function (data)
                {
                    if(data !== true) {
                        return alert(data);
                    }
                    item.find('.collapse').collapse(hide ? 'hide' : 'show');
                    item.find('.comment-toggler').toggle(hide);
                    setTimeout(buttons, 400);
                },
                error: function (jqXHR)
                {
                    alert('An error occured (' + jqXHR.statusText + '). Reload the page and try again.');
                }
            });
        }
        buttons();
    };

})(app.social = {}, jQuery);
