/* global _, app, Translator */

/**
 * The library editor (Ketura, 2026-11-08). The page is the state: the decklists in
 * #library-entries in their order, each with its commentary; the name; the Notes tab's tags,
 * description and notes. Save sends them all; Revert reloads what was saved.
 * The Build tab is the decklists search (ui.decklist_search.js, without history); Add puts a
 * decklist at the end of the library.
 */
(function ui_library_edit(ui, $)
{
    var page, saved = '', saving = false, known_tags = null;

    function t(key, params)
    {
        return Translator.trans(key, params || {});
    }

    var ICON_X = '<svg width="12" height="12" viewBox="0 0 14 14" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" aria-hidden="true"><path d="M3 3l8 8M11 3l-8 8"/></svg>',
        ICON_TRASH = '<span class="fas fa-trash-alt" aria-hidden="true"></span>';

    /* ---- the entries ---- */

    function entry_html(d, annotation)
    {
        var flags = '';
        if(d.withdrawn) {
            flags += '<span class="rb-lib-flag is-gone">' + _.escape(t('library.edit.withdrawn')) + '</span>';
        } else if(d.grace_until) {
            // still within its undo window: the author may take it down (Ketura, 2026-11-08)
            flags += '<span class="rb-lib-flag is-volatile" title="' + _.escape(t('library.edit.volatile_title')) + '">'
                + _.escape(t('library.edit.volatile', {date: d.grace_until})) + '</span>';
        }
        if(d.newer) {
            flags += '<a class="rb-lib-flag is-newer" href="' + _.escape(d.newer.url) + '" target="_blank" rel="noopener">'
                + _.escape(t('library.edit.newer', {version: d.newer.version})) + '</a>';
        }
        return '<div class="rb-lib-entry rb-kind-' + _.escape(d.kind) + '" data-decklist="' + d.id + '">'
            + '<div class="rb-lib-deck">'
            + '<div class="rb-lib-controls">'
            + '<button type="button" class="rb-lib-drag" draggable="true" aria-label="' + _.escape(t('library.edit.drag')) + '" title="' + _.escape(t('library.edit.drag')) + '"><span class="fas fa-grip-vertical" aria-hidden="true"></span></button>'
            + '<button type="button" class="rb-lib-move" data-move="-1" aria-label="' + _.escape(t('library.edit.up')) + '"><span class="fas fa-chevron-up" aria-hidden="true"></span></button>'
            + '<button type="button" class="rb-lib-move" data-move="1" aria-label="' + _.escape(t('library.edit.down')) + '"><span class="fas fa-chevron-down" aria-hidden="true"></span></button>'
            + '</div>'
            + '<span class="rb-lib-num"></span>'
            + '<span class="rb-lib-thumb">' + (d.thumb || '') + '</span>'
            + '<div class="rb-lib-meta">'
            + '<a class="rb-deck-name" href="' + _.escape(d.url) + '" target="_blank" rel="noopener">' + _.escape(d.name) + '</a>'
            + '<span class="rb-deck-by">' + _.escape(d.author) + ' <span class="rb-deck-dot" aria-hidden="true">•</span> ' + _.escape(d.version)
            + ' <span class="rb-deck-dot" aria-hidden="true">•</span> ' + _.escape(d.format || '—') + '</span>'
            + '<span class="rb-size"><span class="rb-size-fp' + (d.fp ? '' : ' is-none') + '">' + (d.fp || '–') + '</span><span class="rb-size-slash">/</span><span class="rb-size-sh' + (d.shadow ? '' : ' is-none') + '">' + (d.shadow || '–') + '</span></span>'
            + flags
            + '</div>'
            + '<span class="rb-lib-remove">'
            + '<button type="button" class="rb-x" aria-label="' + _.escape(t('library.edit.remove', {name: d.name})) + '" title="' + _.escape(t('library.edit.remove_hint')) + '">' + ICON_X + '</button>'
            + '</span>'
            + '</div>'
            + '<div class="rb-lib-notes rb-md-editor"><textarea rows="4" data-annotation data-md-title="' + _.escape(t('library.edit.annotation_title', {name: d.name})) + '" placeholder="'
            + _.escape(t('library.edit.annotation_hint')) + '" aria-label="' + _.escape(t('library.edit.annotation_title', {name: d.name})) + '">' + _.escape(annotation || '') + '</textarea></div>'
            + '</div>';
    }

    function add_entry(d, annotation)
    {
        var row = $(entry_html(d, annotation)).appendTo('#library-entries');
        app.md_editor && app.md_editor.attach(row.find('textarea'), {deck: $('#library-name').val()});
        renumber();
        return row;
    }

    function renumber()
    {
        var rows = $('#library-entries .rb-lib-entry');
        rows.each(function (i) { $(this).find('.rb-lib-num').text(i + 1); });
        $('#library-empty').prop('hidden', rows.length > 0);
        mark_added();
    }

    /** The Build tab's results: Added for what's in the library */
    function mark_added()
    {
        var have = {};
        $('#library-entries .rb-lib-entry').not('.is-pending').each(function () { have[$(this).data('decklist')] = true; });
        $('#browse-results tr[data-decklist]').each(function () {
            var on = !!have[$(this).data('decklist')];
            $(this).find('[data-add]').prop('hidden', on);
            $(this).find('.rb-library-added').prop('hidden', !on);
        });
    }

    /* ---- tags, as the deckbuilder's Notes tab has them ---- */

    function tags()
    {
        return _.uniq(($('#library-tags').val() || '').split(/\s+/).filter(Boolean));
    }

    function set_tags(list)
    {
        $('#library-tags').val(_.uniq(list).join(' '));
        render_chips();
        changed();
    }

    function render_chips()
    {
        $('#tag-input .rb-tag-chips').html(_.map(tags(), function (tag) {
            return '<span class="rb-tag-chip">' + _.escape(tag) + '<button type="button" data-untag="' + _.escape(tag) + '" aria-label="'
                + t('deckbuilder.notes.tag_remove', {tag: _.escape(tag)}) + '">&times;</button></span>';
        }).join(''));
    }

    function show_suggestions()
    {
        var go = function () {
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
                var note = row.is_new ? t('deckbuilder.notes.tag_new')
                    : row.count ? Translator.transChoice('deckbuilder.notes.tag_count', row.count, {count: row.count})
                    : Translator.transChoice('deckbuilder.notes.tag_site', row.site, {count: row.site});
                return '<button type="button" data-tag="' + _.escape(row.tag) + '">' + _.escape(row.tag) + ' <small>' + note + '</small></button>';
            }).join('')).prop('hidden', !rows.length);
        };
        if(known_tags) {
            return go();
        }
        $.getJSON(page.tags_url).done(function (rows) { known_tags = rows || []; go(); }).fail(function () { known_tags = []; go(); });
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

    /* ---- saving ---- */

    function state()
    {
        return JSON.stringify({
            name: $('#library-name').val(),
            description: $('#library-description').val(),
            notes: $('#library-notes-text').val(),
            tags: tags().join(' '),
            // one marked for removal goes when it's saved
            entries: $('#library-entries .rb-lib-entry').not('.is-pending').map(function () {
                return {decklist: $(this).data('decklist'), annotation: $(this).find('textarea[data-annotation]').val()};
            }).get()
        });
    }

    function is_dirty()
    {
        return state() !== saved;
    }

    function changed()
    {
        if(saving) {
            return;
        }
        var dirty = is_dirty();
        $('#btn-save').prop('disabled', !dirty);
        $('#btn-revert').prop('disabled', !dirty);
        $('#save-status').text(dirty ? t('library.edit.unsaved') : '').attr('class', 'rb-save-status' + (dirty ? ' is-dirty' : ''));
    }

    function save()
    {
        var now = state();
        saving = true;
        $('#btn-save, #btn-revert').prop('disabled', true);
        $('#save-status').text(t('deckbuilder.info.saving')).attr('class', 'rb-save-status');
        $.ajax(page.save_url, {type: 'POST', data: {library: now, token: page.token}, dataType: 'json'})
            .done(function () {
                saved = now;
                $('#library-entries .rb-lib-entry.is-pending').remove();
                renumber();
                saving = false;
                changed();
                $('#save-status').text(t('library.edit.saved')).attr('class', 'rb-save-status');
            })
            .fail(function () {
                saving = false;
                changed();
                $('#save-status').text(t('deckbuilder.info.save_failed')).attr('class', 'rb-save-status is-dirty');
            });
    }

    /* ---- who can see it ---- */

    var VIS_ICONS = {
        'private': 'fa-lock',
        hidden: 'fa-link',
        'public': 'fa-globe-americas'
    };

    function show_visibility()
    {
        var vis = page.visibility;
        $('#btn-visibility').html('<span class="fas ' + VIS_ICONS[vis] + '" aria-hidden="true"></span> <span>' + _.escape(t('library.visibility.' + vis)) + '</span> <span aria-hidden="true">&#9662;</span>')
            .attr('title', t('library.visibility.label'));
        $('#menu-visibility [data-visibility]').each(function () {
            var on = $(this).data('visibility') === vis;
            $(this).attr('aria-checked', on).toggleClass('active', on);
        });
        $('#btn-publish').html('<span class="fas fa-globe-americas" aria-hidden="true"></span> ' + _.escape(t(vis === 'public' ? 'library.edit.is_public' : 'library.edit.publish')))
            .prop('disabled', vis === 'public')
            .attr('title', t(vis === 'public' ? 'library.edit.is_public_hint' : 'library.edit.publish_hint'));
    }

    function set_visibility(vis)
    {
        $.ajax(page.visibility_url, {type: 'POST', data: {visibility: vis, token: page.token}, dataType: 'json'})
            .done(function (response) {
                page.visibility = response.visibility;
                show_visibility();
                if(response.visibility === 'public' && response.public_url) {
                    $('#save-status').html(t('library.edit.now_public', {url: _.escape(response.public_url)})).attr('class', 'rb-save-status');
                }
            });
    }

    function close_menus()
    {
        $('.rb-menu').prop('hidden', true);
        $('[aria-haspopup=menu]').attr('aria-expanded', 'false');
    }

    /* ---- setting up ---- */

    ui.on_dom_loaded = function on_dom_loaded()
    {
        page = app.library_page;
        _.each(page.entries, function (entry) { add_entry(entry.decklist, entry.annotation); });
        renumber();
        render_chips();
        app.md_editor && app.md_editor.attach('#library-description', {deck: $('#library-name').val()});
        app.md_editor && app.md_editor.attach('#library-notes-text', {deck: $('#library-name').val()});
        saved = state();
        changed();
        show_visibility();

        var editor = $('#library-editor'), entries = $('#library-entries');
        editor.on('input change', 'input, textarea', function () {
            if(!$(this).closest('#browse-form').length) {
                changed();
            }
        });
        $('#btn-save').on('click', save);
        $('#btn-revert').on('click', function () {
            saved = state();
            window.location.reload();
        });
        $(window).on('beforeunload', function () {
            return is_dirty() ? t('library.edit.leave') : undefined;
        });

        // up and down
        entries.on('click', '[data-move]', function () {
            var row = $(this).closest('.rb-lib-entry');
            if($(this).data('move') < 0) {
                row.prev('.rb-lib-entry').before(row);
            } else {
                row.next('.rb-lib-entry').after(row);
            }
            renumber();
            changed();
            $(this).trigger('focus');
        });
        // dragging by the handle
        var dragged = null;
        entries.on('dragstart', '.rb-lib-drag', function (event) {
            dragged = $(this).closest('.rb-lib-entry').addClass('is-dragging');
            event.originalEvent.dataTransfer.effectAllowed = 'move';
            event.originalEvent.dataTransfer.setData('text/plain', String(dragged.data('decklist')));
            event.originalEvent.dataTransfer.setDragImage(dragged[0], 20, 20);
        });
        entries.on('dragover', '.rb-lib-entry', function (event) {
            if(!dragged) {
                return;
            }
            event.preventDefault();
            var row = $(this);
            if(row.is(dragged)) {
                return;
            }
            var rect = this.getBoundingClientRect();
            if(event.originalEvent.clientY < rect.top + rect.height / 2) {
                row.before(dragged);
            } else {
                row.after(dragged);
            }
        });
        entries.on('drop dragend', function (event) {
            if(dragged) {
                event.preventDefault();
                dragged.removeClass('is-dragging');
                dragged = null;
                renumber();
                changed();
            }
        });
        // X, then the bin; Keep takes it back (as the deckbuilder's cards: Ketura, 2026-11-08)
        entries.on('click', '.rb-x', function () {
            var row = $(this).closest('.rb-lib-entry').addClass('is-pending');
            row.find('.rb-lib-remove').html('<button type="button" class="rb-trash" aria-label="' + _.escape(t('library.edit.trash')) + '" title="' + _.escape(t('library.edit.trash')) + '">' + ICON_TRASH + '</button>'
                + '<button type="button" class="rb-btn rb-btn-small rb-lib-keep">' + _.escape(t('library.edit.keep')) + '</button>');
            mark_added();
            changed();
        });
        entries.on('click', '.rb-lib-keep', function () {
            var row = $(this).closest('.rb-lib-entry').removeClass('is-pending');
            row.find('.rb-lib-remove').html('<button type="button" class="rb-x" aria-label="' + _.escape(t('library.edit.remove', {name: row.find('.rb-deck-name').text()})) + '" title="' + _.escape(t('library.edit.remove_hint')) + '">' + ICON_X + '</button>');
            mark_added();
            changed();
        });
        entries.on('click', '.rb-trash', function () {
            $(this).closest('.rb-lib-entry').remove();
            renumber();
            changed();
        });

        // the Build tab: Add puts it at the end, and shows it there
        ui.browse_setup();
        $('#tab-pane-build').on('click', '[data-add]', function () {
            var tr = $(this).closest('tr'), id = tr.data('decklist'), existing = $('#library-entries .rb-lib-entry[data-decklist="' + id + '"]');
            if(existing.length) {
                existing.find('.rb-lib-keep').trigger('click');
            } else {
                var row = add_entry(tr.data('entry'), '');
                row.addClass('is-new');
                row[0].scrollIntoView({block: 'nearest', behavior: 'smooth'});
                setTimeout(function () { row.removeClass('is-new'); }, 1600);
            }
            changed();
        });
        $(document).on('browse:loaded', mark_added);

        // tags
        $('#tag-entry').on('focus input', show_suggestions);
        $('#tag-entry').on('keydown', function (event) {
            if(event.key === 'Enter' || event.key === ' ' || event.key === ',') {
                event.preventDefault();
                add_typed_tag();
            } else if(event.key === 'Backspace' && !$(this).val()) {
                set_tags(tags().slice(0, -1));
            }
        });
        $('#tag-entry').on('blur', function () {
            setTimeout(function () { $('#tag-input .rb-tag-suggest').prop('hidden', true); }, 150);
        });
        $('#tag-input').on('mousedown', '.rb-tag-suggest button', function (event) { event.preventDefault(); });
        $('#tag-input').on('click', '.rb-tag-suggest button', function () {
            set_tags(tags().concat([$(this).data('tag') + '']));
            $('#tag-entry').val('');
            show_suggestions();
        });
        $('#tag-input').on('click', '[data-untag]', function () {
            var tag = $(this).data('untag') + '';
            set_tags(_.without(tags(), tag));
        });

        // who can see it
        $('#btn-visibility').on('click', function (event) {
            event.stopPropagation();
            var menu = $('#menu-visibility'), open = menu.prop('hidden');
            close_menus();
            menu.prop('hidden', !open);
            $(this).attr('aria-expanded', open ? 'true' : 'false');
        });
        $('#menu-visibility').on('click', '[data-visibility]', function () {
            close_menus();
            set_visibility($(this).data('visibility'));
        });
        $('#btn-publish').on('click', function () {
            set_visibility('public');
        });
        $(document).on('click', function (event) {
            if(!$(event.target).closest('.rb-menu-wrap').length) {
                close_menus();
            }
        });

        // the panes resize from the bar between them, as the deckbuilder's
        var row = $('.rb-builder-row'), left = $('.rb-col-left');
        ui.splitter('#builder-splitter', {
            key: 'rb-library-left-width',
            min: 520,
            max: function () { return row.width() - 480; },
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
    };

    ui.on_data_loaded = function on_data_loaded()
    {
        ui.browse_data_loaded();
    };

    ui.on_all_loaded = function on_all_loaded()
    {
    };
})(app.ui, jQuery);
