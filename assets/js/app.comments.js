/* global app, Translator, Routing, _, moment */

/*
 * Comments as threads (Ketura, 2026-11-11), on decklists and libraries alike
 * (templates/Comment/thread.html.twig): the actions each viewer may use, from /comment/state
 * (the page itself is the same for everyone); the comment form at the end and the reply and
 * edit forms in place; Cheers and fire; History and Report in a popup; Copy Link and Copy Text;
 * folding a thread by its line; and going to the comment a link names (#comment-N).
 */
(function app_comments(comments, $)
{
    var t = function (key, params) { return Translator.trans(key, params || {}); },
        section = null,
        state = null,
        options = {},
        forms = 0;

    /* ---- helpers ---- */

    function state_url()
    {
        return Routing.generate('comment_state', {kind: section.data('kind'), id: section.data('id')});
    }

    /**
     * Posts with the page's token. A page left open long enough for its token to expire gets
     * a fresh one and sends again by itself (Ketura, 2026-11-11: the viewer never has to reload).
     * Resolves as $.ajax does.
     */
    function post(url, data)
    {
        var done = $.Deferred();
        function attempt(again)
        {
            $.ajax(url, {type: 'POST', data: _.extend({token: state ? state.token : ''}, data)})
                .done(function (answer, status, jqXHR) { done.resolve(answer, status, jqXHR); })
                .fail(function (jqXHR) {
                    if(again && jqXHR.getResponseHeader('X-Comment-Error') === 'expired') {
                        $.getJSON(state_url()).done(function (fresh) {
                            if(fresh && fresh.token) {
                                state.token = fresh.token;
                                return attempt(false);
                            }
                            done.reject(jqXHR);
                        }).fail(function () { done.reject(jqXHR); });
                        return;
                    }
                    done.reject(jqXHR);
                });
        }
        attempt(true);
        return done.promise();
    }

    /** What the server said was wrong, or that something was */
    function failed(jqXHR)
    {
        var message = jqXHR && jqXHR.responseJSON && jqXHR.responseJSON.message;
        alert(message || t('comment.error', {status: jqXHR && jqXHR.status ? jqXHR.status + ' ' + jqXHR.statusText : '?'}));
    }

    function node_of(el)
    {
        return $(el).closest('.rb-cmt');
    }

    function id_of(node)
    {
        return node.data('id');
    }

    /** "3 days ago", the exact time in the tooltip already */
    function relative_times(root)
    {
        if(typeof moment === 'undefined') {
            return;
        }
        $(root).find('.rb-cmt-time time').each(function () {
            var m = moment($(this).attr('datetime'));
            if(m.isValid()) {
                $(this).text(m.fromNow());
            }
        });
    }

    function set_count(count)
    {
        section.find('.rb-comments-count').text(Translator.transChoice('decklist.view.comments.count', count, {count: count}));
        $('#social-comment .num').text(count);
    }

    function copy(text)
    {
        if(navigator.clipboard && window.isSecureContext) {
            return navigator.clipboard.writeText(text);
        }
        var area = $('<textarea style="position:fixed;opacity:0"></textarea>').val(text).appendTo('body');
        area[0].select();
        document.execCommand('copy');
        area.remove();
        return $.Deferred().resolve().promise();
    }

    /** A moment's "Copied" on whatever was clicked */
    function flash(el, text)
    {
        var tip = $('<span class="rb-cmt-flash" role="status"></span>').text(text);
        node_of(el).find('.rb-cmt-actions').first().append(tip);
        setTimeout(function () { tip.remove(); }, 1600);
    }

    /**
     * The comment as written, {markdown}. One from before the markdown was kept (data-legacy)
     * has only what the page shows, so Copy Text and Quote take that as it reads; nothing is
     * made back into markdown, and it can't be edited.
     */
    function source(node)
    {
        if(node.is('[data-legacy]')) {
            var text = node.children('.rb-cmt-body').find('.rb-cmt-text')[0];
            return $.Deferred().resolve({markdown: $.trim(text ? text.innerText : '')}).promise();
        }
        return $.getJSON(Routing.generate('comment_source', {id: id_of(node)}));
    }

    /* ---- what this viewer may do ---- */

    /** Shows the actions this viewer may use on each comment under root (or root itself) */
    function apply(root)
    {
        var logged = !!state;
        $(root).find('.rb-cmt').addBack('.rb-cmt').each(function () {
            var node = $(this), body = node.children('.rb-cmt-body');
            if(node.hasClass('is-deleted')) {
                return;
            }
            var id = id_of(node),
                mine = logged && String(node.data('author')) === String(state.user),
                cheered = logged && _.contains(state.cheered, id),
                fired = logged && _.contains(state.fired, id),
                reported = logged && _.contains(state.reported, id);
            set_reactions(node, {cheered: cheered, fired: fired}, mine);
            body.find('[data-act="reply"], [data-act="quote"]').prop('hidden', !logged);
            body.find('.rb-cmt-own').prop('hidden', !(mine || (logged && state.moderator)));
            node.is('[data-legacy]') && body.find('.rb-cmt-editor').prop('hidden', true);
            body.find('.rb-cmt-hider').prop('hidden', !(logged && (state.owner || state.moderator)));
            body.find('.rb-cmt-other').prop('hidden', !logged || mine);
            body.find('.rb-cmt-report-label').text(t(reported ? 'comment.reported' : 'comment.report'));
        });
        relative_times(root);
    }

    /**
     * Cheers and fire as a decklist has them: the fire tucked behind Cheers until there is a
     * cheers to set on fire, or some fire already. counts: {cheers, fires} from the server.
     */
    function set_reactions(node, mine, own, counts)
    {
        var body = node.children('.rb-cmt-body'),
            cheer = body.find('[data-act="cheer"]'),
            fire = body.find('[data-act="fire"]');
        if(counts) {
            cheer.find('.num').text(counts.cheers);
            fire.find('.num').text(counts.fires);
        }
        var fires = parseInt(fire.find('.num').text(), 10) || 0;
        cheer.prop('disabled', !state || own).attr('aria-pressed', mine.cheered ? 'true' : 'false')
            .attr('title', own ? t('comment.own') : t('comment.cheers'));
        fire.prop('disabled', !state || own || !mine.cheered).attr('aria-pressed', mine.fired ? 'true' : 'false')
            .attr('title', mine.cheered ? t(mine.fired ? 'comment.fire_out' : 'comment.fire') : t('comment.fire_locked'))
            .toggleClass('is-tucked', !mine.cheered && fires === 0);
    }

    /* ---- forms ---- */

    /**
     * A markdown box (the deckbuilder's editor: Preview, symbols, # for cards, @ for the
     * people here) with its buttons. opts: text, submit (label), cancel (bool), placeholder,
     * send(text, form) -> promise
     */
    function editor_form(opts)
    {
        var id = 'cmt-text-' + (++forms),
            form = $('<form class="rb-comment-form"><div class="rb-md-editor"><textarea id="' + id + '" rows="' + (opts.rows || 5) + '" name="comment"></textarea></div>'
                + '<div class="rb-comment-form-buttons"><button type="submit" class="btn btn-primary"></button></div></form>');
        form.find('textarea').attr('data-md-title', t('decklist.reader.comment')).attr('placeholder', opts.placeholder || '').val(opts.text || '');
        form.find('button[type=submit]').text(opts.submit);
        if(opts.cancel) {
            $('<button type="button" class="btn btn-default rb-comment-cancel"></button>').text(t('comment.cancel'))
                .appendTo(form.find('.rb-comment-form-buttons'))
                .on('click', function () { form.trigger('rb:cancel'); });
        }
        var sending = false;
        form.on('submit', function (event) {
            event.preventDefault();
            var area = form.find('textarea'), text = $.trim(area.val());
            if(sending || !text) {
                return;
            }
            // over the limit: the counter under the box is red, and says by how much
            if(app.md_editor.too_long(area)) {
                area.trigger('focus');
                return;
            }
            sending = true;
            form.find('button').prop('disabled', true);
            opts.send(text, form).fail(function (jqXHR) {
                failed(jqXHR);
            }).always(function () {
                sending = false;
                form.find('button').prop('disabled', false);
            });
        });
        form.data('attach', function () {
            app.md_editor.attach('#' + id, {
                max: parseInt(section.data('max-length'), 10) || 0,
                users: section.data('commenters') || [],
                footer: t('decklist.reader.comment_hint'),
                deck: options.title || ''
            });
        });
        return form;
    }

    /** A comment just posted joins the page: at the end, or at the end of what it answers */
    function add_node(html, parent)
    {
        var added = $($.parseHTML($.trim(html)));
        (parent ? parent.children('.rb-cmt-replies') : section.find('.rb-cmt-list')).append(added);
        apply(added);
        return added;
    }

    function posted(jqXHR, added)
    {
        var count = parseInt(jqXHR.getResponseHeader('X-Comment-Count'), 10);
        if(!isNaN(count)) {
            set_count(count);
        }
        app.social && app.social.set_pressed('#social-comment', true);
        target(added, false);
    }

    /** The form at the end of the comments */
    function main_form()
    {
        section.find('.rb-comment-form.is-main').remove();
        var form = editor_form({
            submit: t('decklist.view.comments.submit'),
            placeholder: t('decklist.reader.comment_placeholder'),
            rows: 6,
            send: function (text) {
                return post(Routing.generate('comment_post'), {kind: section.data('kind'), id: section.data('id'), comment: text})
                    .done(function (html, status, jqXHR) {
                        posted(jqXHR, add_node(html, null));
                        main_form();
                    });
            }
        }).addClass('is-main');
        form.insertAfter(section.find('#comment-form'));
        form.data('attach')();
    }

    /** Reply under a comment; with quote, its text quoted to start */
    function reply(node, quote)
    {
        var form = node.children('.rb-comment-form.is-reply');
        if(!form.length) {
            form = editor_form({
                submit: t('comment.post_reply'),
                placeholder: t('comment.reply_placeholder'),
                cancel: true,
                send: function (text) {
                    return post(Routing.generate('comment_post'), {kind: section.data('kind'), id: section.data('id'), parent: id_of(node), comment: text})
                        .done(function (html, status, jqXHR) {
                            form.remove();
                            node.removeClass('is-folded');
                            posted(jqXHR, add_node(html, node));
                        });
                }
            }).addClass('is-reply').on('rb:cancel', function () { form.remove(); });
            form.insertAfter(node.children('.rb-cmt-body'));
            form.data('attach')();
        }
        var area = form.find('textarea');
        var done = function () {
            area.trigger('focus');
            form[0].scrollIntoView({block: 'nearest', behavior: 'smooth'});
        };
        if(quote) {
            source(node).done(function (data) {
                var quoted = data.markdown.split('\n').map(function (line) { return '> ' + line; }).join('\n').replace(/> $/gm, '>');
                var now = area.val();
                area.val((now ? now.replace(/\s*$/, '\n\n') : '') + quoted + '\n\n').trigger('input');
                done();
            }).fail(failed);
        } else {
            done();
        }
    }

    function edit(node)
    {
        var body = node.children('.rb-cmt-body');
        if(body.find('.rb-comment-form').length) {
            return;
        }
        source(node).done(function (data) {
            var form = editor_form({
                text: data.markdown,
                submit: t('comment.save'),
                cancel: true,
                send: function (text) {
                    return post(Routing.generate('comment_edit', {id: id_of(node)}), {comment: text}).done(function (html) {
                        body.replaceWith($.trim(html));
                        apply(node);
                    });
                }
            }).addClass('is-edit').on('rb:cancel', function () {
                form.remove();
                body.removeClass('is-editing');
            });
            body.addClass('is-editing').find('.rb-cmt-text').after(form);
            form.data('attach')();
            form.find('textarea').trigger('focus');
        }).fail(failed);
    }

    function remove(node)
    {
        if(!confirm(t('comment.delete_confirm'))) {
            return;
        }
        post(Routing.generate('comment_delete', {id: id_of(node)})).done(function (data) {
            set_count(data.count);
            settle(node, data.body);
        }).fail(failed);
    }

    /** A deleted comment stays while replies stand under it; one with none goes, and so on up */
    function settle(node, body)
    {
        if(body !== undefined) {
            node.addClass('is-deleted').attr('data-author', '');
            node.children('.rb-cmt-body').replaceWith($.trim(body));
            relative_times(node);
        }
        if(node.hasClass('is-deleted') && !node.children('.rb-cmt-replies').children('.rb-cmt').length) {
            var parent = node.parent().closest('.rb-cmt');
            node.remove();
            if(parent.length) {
                settle(parent);
            }
        }
    }

    function hide(node)
    {
        var hidden = !node.hasClass('is-hidden');
        if(hidden && !confirm(t('comment.hide_confirm'))) {
            return;
        }
        post(Routing.generate('comment_hide', {id: id_of(node)}), {hidden: hidden ? 1 : 0}).done(function (data) {
            node.toggleClass('is-hidden', data.hidden).removeClass('is-open');
            var body = node.children('.rb-cmt-body'), label = body.find('.rb-cmt-hide-label');
            body.find('.rb-cmt-hidden-note').prop('hidden', !data.hidden);
            label.text(label.data(data.hidden ? 'unhide' : 'hide'));
        }).fail(failed);
    }

    function react(node, what)
    {
        var button = node.children('.rb-cmt-body').find('[data-act="' + what + '"]');
        if(button.hasClass('processing') || button.prop('disabled')) {
            return;
        }
        button.addClass('processing');
        post(Routing.generate(what === 'cheer' ? 'comment_cheer' : 'comment_fire', {id: id_of(node)})).done(function (data) {
            var id = id_of(node);
            state.cheered = _.without(state.cheered, id);
            state.fired = _.without(state.fired, id);
            data.cheered && state.cheered.push(id);
            data.fired && state.fired.push(id);
            set_reactions(node, data, false, data);
        }).fail(failed).always(function () { button.removeClass('processing'); });
    }

    /* ---- the popup: History and Report ---- */

    function modal(title, content, footer)
    {
        var box = $('#rb-cmt-modal');
        if(!box.length) {
            box = $('<div class="modal fade" id="rb-cmt-modal" tabindex="-1" role="dialog" aria-labelledby="rb-cmt-modal-title"><div class="modal-dialog" role="document"><div class="modal-content">'
                + '<div class="modal-header"><button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button><h4 class="modal-title" id="rb-cmt-modal-title"></h4></div>'
                + '<div class="modal-body"></div><div class="modal-footer"></div></div></div></div>').appendTo('body');
        }
        box.find('.modal-title').text(title);
        box.find('.modal-body').empty().append(content);
        box.find('.modal-footer').empty().append(footer || $('<button type="button" class="btn btn-default" data-dismiss="modal"></button>').text(t('comment.close')));
        box.modal('show');
        return box;
    }

    function history(node)
    {
        $.get(Routing.generate('comment_history', {id: id_of(node)})).done(function (html) {
            modal(t('comment.history_title'), $.parseHTML($.trim(html)));
        }).fail(failed);
    }

    function report(node)
    {
        var area = $('<textarea class="form-control" rows="4" maxlength="2000"></textarea>'),
            send = $('<button type="button" class="btn btn-primary"></button>').text(t('comment.report_send')),
            content = $('<div></div>').append($('<p class="help-block"></p>').text(t('comment.report_hint')), area),
            box = modal(t('comment.report_title'), content, [$('<button type="button" class="btn btn-default" data-dismiss="modal"></button>').text(t('comment.cancel')), send]);
        box.one('shown.bs.modal', function () { area.trigger('focus'); });
        send.on('click', function () {
            var reason = $.trim(area.val());
            if(!reason) {
                return area.trigger('focus');
            }
            send.prop('disabled', true);
            post(Routing.generate('comment_report', {id: id_of(node)}), {reason: reason}).done(function () {
                state.reported.push(id_of(node));
                apply(node);
                box.find('.modal-body').empty().append($('<p></p>').text(t('comment.report_done')));
                box.find('.modal-footer').empty().append($('<button type="button" class="btn btn-default" data-dismiss="modal">OK</button>'));
            }).fail(function (jqXHR) {
                send.prop('disabled', false);
                failed(jqXHR);
            });
        });
    }

    /* ---- going to a comment ---- */

    /** Opens the way to it (folded threads, a hidden comment), scrolls it into view, lights it */
    function target(node, center)
    {
        if(!node || !node.length) {
            return;
        }
        node.parents('.rb-cmt.is-folded').removeClass('is-folded');
        node.filter('.is-hidden').addClass('is-open');
        node[0].scrollIntoView({block: center === false ? 'nearest' : 'center', behavior: center === false ? 'smooth' : 'auto'});
        node.removeClass('is-target');
        void node[0].offsetWidth;
        node.addClass('is-target');
    }

    function follow_hash()
    {
        var match = /^#comment-(\d+)$/.exec(location.hash);
        if(match) {
            target(section.find('#comment-' + match[1]));
        }
    }

    /* ---- the actions ---- */

    function act(el, what)
    {
        var node = node_of(el);
        switch(what) {
            case 'reply': return reply(node, false);
            case 'quote': return reply(node, true);
            case 'cheer': case 'fire': return react(node, what);
            case 'edit': return edit(node);
            case 'delete': return remove(node);
            case 'hide': return hide(node);
            case 'history': return history(node);
            case 'report': return report(node);
            case 'copy-link':
                return copy(new URL(node.children('.rb-cmt-body').find('.rb-cmt-time').attr('href'), location.href).href)
                    .then(function () { flash(el, t('comment.copied')); });
            case 'copy-text':
                return source(node).done(function (data) {
                    copy(data.markdown).then(function () { flash(el, t('comment.copied')); });
                }).fail(failed);
        }
    }

    function fold(node, folded)
    {
        node.toggleClass('is-folded', folded);
        var count = node.find('.rb-cmt').not('.is-deleted').length;
        node.children('.rb-cmt-unfold-thread').text(Translator.transChoice('comment.unfold_thread', count, {count: count})).prop('hidden', !folded);
    }

    /**
     * Runs the comments on this page. opts: title (the decklist's or library's name, for the
     * editor's fullscreen heading). Done when the viewer's state has come (or there is none).
     */
    comments.setup = function setup(opts)
    {
        section = $('.rb-cmts').first();
        if(!section.length) {
            return $.Deferred().resolve().promise();
        }
        options = opts || {};
        section.on('click', '[data-act]', function (event) {
            event.preventDefault();
            act(this, $(this).data('act'));
        });
        section.on('click', '.rb-cmt-line', function () { fold(node_of(this), true); });
        section.on('click', '.rb-cmt-unfold-thread', function () { fold(node_of(this), false); });
        section.on('click', '.rb-cmt-unfold', function () { node_of(this).toggleClass('is-open'); });
        // the time is the comment's own link: going there lights it up
        section.on('click', '.rb-cmt-time', function () { setTimeout(follow_hash, 0); });
        $(window).on('hashchange', follow_hash);
        relative_times(section);
        follow_hash();

        var done = $.Deferred();
        if(String(section.data('logged-in')) !== '1') {
            apply(section);
            return done.resolve().promise();
        }
        $.getJSON(state_url()).done(function (data) {
            state = data;
            apply(section);
            if(state) {
                main_form();
            }
        }).always(function () { done.resolve(); });
        return done.promise();
    };

    /** The comment form's text box, for the page's Comment button */
    comments.focus_form = function focus_form()
    {
        var area = section && section.find('.rb-comment-form.is-main textarea');
        if(area && area.length) {
            area[0].scrollIntoView({block: 'center', behavior: 'smooth'});
            area.trigger('focus');
        } else if(section) {
            section[0].scrollIntoView({block: 'start', behavior: 'smooth'});
        }
    };

    // the times read "3 days ago" from the start, before the rest is set up (the decklist page
    // waits for the card data)
    $(function () { relative_times('.rb-cmts'); });

})(app.comments = {}, jQuery);
