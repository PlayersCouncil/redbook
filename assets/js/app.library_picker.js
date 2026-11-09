/* global _, app, Routing, Translator */

/**
 * Add to Library (Ketura, 2026-11-08): a drawer under the button, on every list of decks. The
 * user's libraries, most recently changed first, a search box over them and + Add New Library
 * just under it; a click adds the decklists to that library at once. Any element with
 * data-library-add="<decklist id>" opens it; app.library_picker.open(button, ids) for several.
 */
(function app_library_picker(picker, $)
{
    var drawer = null, ids = [], anchor = null, token = null, request = null, timer = null;

    function t(key, params)
    {
        return Translator.trans(key, params || {});
    }

    function close()
    {
        if(request) {
            request.abort();
        }
        if(drawer) {
            drawer.remove();
            drawer = null;
            $(anchor).attr('aria-expanded', 'false');
            anchor = null;
        }
    }

    function place()
    {
        if(!drawer || !anchor) {
            return;
        }
        var rect = anchor.getBoundingClientRect(), width = drawer.outerWidth(),
            left = Math.max(8, Math.min(rect.right - width, window.innerWidth - width - 8)),
            below = window.innerHeight - rect.bottom - 8,
            top = below >= 260 || below >= rect.top ? rect.bottom + 4 : Math.max(8, rect.top - drawer.outerHeight() - 4);
        drawer.css({left: left, top: top, maxHeight: Math.max(220, below >= 260 || below >= rect.top ? below : rect.top - 12)});
    }

    function message(html, kind)
    {
        drawer.find('.rb-ld-message').html(html).attr('class', 'rb-ld-message' + (kind ? ' is-' + kind : '')).prop('hidden', !html);
    }

    function render(libraries)
    {
        var list = drawer.find('.rb-ld-list');
        if(!libraries.length) {
            list.html('<p class="rb-ld-none">' + _.escape(t($.trim(drawer.find('.rb-ld-search').val()) ? 'library.picker.none_found' : 'library.picker.none')) + '</p>');
            return;
        }
        list.html(_.map(libraries, function (lib) {
            var all = ids.length && lib.has === ids.length;
            return '<button type="button" class="rb-ld-library' + (all ? ' is-in' : '') + '" data-library="' + _.escape(lib.uuid) + '"' + (all ? ' aria-disabled="true"' : '') + '>'
                + '<span class="rb-ld-name">' + _.escape(lib.name) + '</span>'
                + '<span class="rb-ld-count">' + _.escape(Translator.transChoice('library.picker.count', lib.count, {count: lib.count})) + '</span>'
                + (all ? '<span class="rb-ld-in"><span class="fas fa-check" aria-hidden="true"></span> ' + _.escape(t('library.picker.in')) + '</span>' : '')
                + '</button>';
        }).join(''));
    }

    function load()
    {
        if(request) {
            request.abort();
        }
        var q = $.trim(drawer.find('.rb-ld-search').val() || '');
        request = $.ajax(Routing.generate('library_picker'), {data: {q: q, decklists: ids}, dataType: 'json', traditional: false});
        request.done(function (response) {
            token = response.token;
            // closed while it was on its way
            if(!drawer) {
                return;
            }
            render(response.libraries);
            place();
        }).fail(function (xhr, status) {
            if(status === 'abort' || !drawer) {
                return;
            }
            if(xhr.status === 401) {
                drawer.find('.rb-ld-search, .rb-ld-new').prop('hidden', true);
                drawer.find('.rb-ld-list').empty();
                message(t('library.picker.login', {url: _.escape((xhr.responseJSON && xhr.responseJSON.login) || '/login')}), 'info');
            } else {
                message(_.escape(t('library.picker.failed')), 'error');
            }
        }).always(function () { request = null; });
    }

    function add(data)
    {
        data.decklists = ids;
        data.token = token;
        $.ajax(Routing.generate('library_add'), {type: 'POST', data: data, dataType: 'json'})
            .done(function (response) {
                $(document).trigger('library:added', [response]);
                if(!drawer) {
                    return;
                }
                message(_.escape(response.message) + ' <a href="' + _.escape(response.edit) + '">' + _.escape(t('library.picker.open')) + '</a>', response.added ? 'ok' : 'info');
                drawer.find('.rb-ld-new-form').prop('hidden', true);
                drawer.find('.rb-ld-new').prop('hidden', false);
                load();
            })
            .fail(function () { drawer && message(_.escape(t('library.picker.failed')), 'error'); });
    }

    picker.open = function open(button, decklist_ids)
    {
        if(drawer && anchor === button) {
            return close();
        }
        close();
        ids = _.uniq(_.compact(_.map(decklist_ids, function (id) { return parseInt(id, 10); })));
        if(!ids.length) {
            return;
        }
        anchor = button;
        $(button).attr('aria-expanded', 'true');
        drawer = $('<div class="rb-library-drawer" role="dialog" aria-label="' + _.escape(t('library.picker.title')) + '">'
            + '<div class="rb-ld-head">'
            + '<span class="rb-ld-title">' + _.escape(ids.length > 1 ? t('library.picker.title_several', {count: ids.length}) : t('library.picker.title')) + '</span>'
            + '<button type="button" class="close rb-ld-close" aria-label="' + _.escape(t('forms.cancel')) + '">&times;</button>'
            + '</div>'
            + '<input type="search" class="form-control input-sm rb-ld-search" placeholder="' + _.escape(t('library.picker.search')) + '" aria-label="' + _.escape(t('library.picker.search')) + '">'
            + '<button type="button" class="rb-ld-new"><span class="fas fa-plus" aria-hidden="true"></span> ' + _.escape(t('library.picker.new')) + '</button>'
            + '<form class="rb-ld-new-form" hidden><input type="text" class="form-control input-sm rb-ld-new-name" maxlength="255" placeholder="' + _.escape(t('library.picker.new_name')) + '" aria-label="' + _.escape(t('library.picker.new_name')) + '">'
            + '<button type="submit" class="btn btn-success btn-sm">' + _.escape(t('library.picker.create')) + '</button></form>'
            + '<div class="rb-ld-message" hidden></div>'
            + '<div class="rb-ld-list"><p class="rb-ld-none">' + _.escape(t('decklist.reader.loading')) + '</p></div>'
            + '</div>').appendTo('body');
        place();
        drawer.find('.rb-ld-search').trigger('focus');
        load();

        drawer.on('input', '.rb-ld-search', function () {
            clearTimeout(timer);
            timer = setTimeout(load, 200);
        });
        drawer.on('click', '.rb-ld-close', close);
        drawer.on('click', '.rb-ld-library:not(.is-in)', function () {
            add({library: $(this).data('library')});
        });
        drawer.on('click', '.rb-ld-new', function () {
            $(this).prop('hidden', true);
            var form = drawer.find('.rb-ld-new-form').prop('hidden', false);
            form.find('input').val($.trim(drawer.find('.rb-ld-search').val() || '')).trigger('focus');
            place();
        });
        drawer.on('submit', '.rb-ld-new-form', function (event) {
            event.preventDefault();
            var name = $.trim($(this).find('input').val());
            if(name) {
                add({name: name});
            }
        });
    };

    picker.close = close;

    $(function () {
        $(document).on('click', '[data-library-add]', function (event) {
            event.preventDefault();
            event.stopPropagation();
            picker.open(this, [$(this).data('library-add')]);
        });
        $(document).on('mousedown', function (event) {
            if(drawer && !$(event.target).closest('.rb-library-drawer, [data-library-add]').length && event.target !== anchor && !$(event.target).closest(anchor).length) {
                close();
            }
        });
        $(document).on('keydown', function (event) {
            if(drawer && event.key === 'Escape') {
                close();
            }
        });
        $(window).on('resize', place);
        $(document).on('scroll', function () { place(); });
        document.addEventListener('scroll', place, true);
    });
})(app.library_picker = {}, jQuery);
