/* global app, Translator, Routing, moment */

/*
 * The Formats page's Timeline tab (Ketura, 2026-11-11), from the other instance's draft
 * (_planning/timeline/format-timeline.html), in Red Book's dress. It draws the format timeline
 * (App\Services\FormatTimeline; its data is format-timeline.json, described in
 * _planning/timeline/format-timeline-data.md):
 * - rows: time, top to bottom; a year with events is to scale, a year without is one short row
 * - columns: the events (a marker on the line each, its label beside it), the official formats
 *   (where the data's formats[].drawn puts them, cut where their micro-formats start), the Gemp
 *   format that fits each era, and the Redbook micro-formats of the main format
 * - a click on any of them shows its details and sources in the pane beside, Escape (or the
 *   pane's Overview) the key to it all; a card named in them links to its page
 * The timeline scrolls both ways inside its own pane, the dates kept at its left and the column
 * names at its top.
 */
(function ui_format_timeline(ui, $)
{
    var ZOOM = 1.6,         // px per day in a year with events
        QUIET_ROW = 26,     // px: a year with no events
        LH = 17,            // px: an event's label
        COLW = {axis: 96, events: 360, official: 132, gemp: 170, redbook: 176},
        DAY = 86400000,
        S = {data: null};

    function t(key, params) { return Translator.trans('formats.timeline.' + key, params || {}); }

    function cards_text(n) { return Translator.transChoice('formats.timeline.card_count', n, {count: n}); }

    function el(tag, cls, text)
    {
        var e = document.createElement(tag);
        if(cls) {
            e.className = cls;
        }
        if(text != null) {
            e.textContent = text;
        }
        return e;
    }

    function esc(s)
    {
        return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) {
            return {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;'}[c];
        });
    }

    function kind_color(kind) { return 'var(--rb-tl-k-' + kind + ', var(--rb-ink-3))'; }

    /** "2004-07-13" as "13 Jul 2004"; "2007-03" as "Mar 2007"; "2007" as is; none: open-ended */
    function fmt_date(d)
    {
        if(!d) {
            return t('open_ended');
        }
        var parts = String(d).split('-');
        if(parts.length === 3) {
            return moment(d, 'YYYY-MM-DD').format('D MMM YYYY');
        }
        return parts.length === 2 ? moment(d, 'YYYY-MM').format('MMM YYYY') : d;
    }

    /** The timeline's card ID as Red Book's: the PC's virtual sets V0-V3 are Gemp's 100-103 */
    function card_code(id)
    {
        var m = /^V(\d+)_(\d+)/.exec(String(id || ''));
        return m ? (100 + parseInt(m[1], 10)) + '_' + m[2] : String(id || '');
    }

    function card_link(id, title)
    {
        var code = card_code(id);
        if(!/^\d+_\d+$/.test(code)) {
            return esc(title || id);
        }
        return '<a class="card-tip" data-code="' + esc(code) + '" href="' + esc(Routing.generate('cards_zoom', {card_code: code})) + '">' + esc(title || code) + '</a>';
    }

    /* ---- geometry ---- */

    /** y as a function of the date: piecewise linear, a segment a year */
    function geom()
    {
        var d = S.data, pad = 30, busy = {}, seg = [], off = pad,
            r0 = Date.parse(d.range.start), r1 = Date.parse(d.range.end);
        d.events.forEach(function (e) { busy[+String(e.date).slice(0, 4)] = true; });
        for(var yr = +d.range.start.slice(0, 4); yr <= +d.range.end.slice(0, 4); yr++) {
            var a = Math.max(Date.UTC(yr, 0, 1), r0), b = Math.min(Date.UTC(yr + 1, 0, 1), r1);
            if(b <= a) {
                continue;
            }
            var quiet = !busy[yr], h = quiet ? QUIET_ROW : (b - a) / DAY * ZOOM;
            seg.push({yr: yr, a: a, b: b, top: off, h: h, quiet: quiet});
            off += h;
        }
        function y(s)
        {
            s = String(s);
            var time = Date.parse(s.length === 4 ? s + '-01-01' : (s.length === 7 ? s + '-01' : s)),
                g = seg.find(function (g) { return time < g.b; }) || seg[seg.length - 1];
            return g.top + Math.min(1, Math.max(0, (time - g.a) / (g.b - g.a))) * g.h;
        }
        return {y: y, seg: seg, height: off + pad};
    }

    function columns()
    {
        var cols = [{id: 'axis', name: '', kind: 'axis', w: COLW.axis}, {id: 'events', name: t('events'), kind: 'events', w: COLW.events}], x = 0;
        S.data.columns.forEach(function (c) { cols.push($.extend({}, c, {w: COLW[c.kind] || 150})); });
        cols.forEach(function (c) { c.x = x; x += c.w; });
        return {cols: cols, width: x};
    }

    /* ---- drawing ---- */

    function rect(body, top, bottom, x, w, cls, name, sub)
    {
        var r = el('div', 'rb-tl-rect ' + (cls || ''));
        r.style.left = x + 'px';
        r.style.width = w + 'px';
        r.style.top = top + 'px';
        r.style.height = Math.max(2, bottom - top) + 'px';
        if(bottom - top >= 16) {
            var n = el('span', 'rb-tl-nm');
            n.innerHTML = esc(name) + (sub && bottom - top >= 32 ? '<small>' + esc(sub) + '</small>' : '');
            r.appendChild(n);
        }
        body.appendChild(r);
        return r;
    }

    function render(main)
    {
        var d = S.data, G = geom(), layout = columns(), cols = layout.cols, width = layout.width;
        main.innerHTML = '';
        var grid = el('div', 'rb-tl-grid'), head = el('div', 'rb-tl-cols'), body = el('div', 'rb-tl-body');
        grid.style.width = width + 'px';
        cols.forEach(function (c) {
            var h = el('div', 'is-' + c.kind, c.name);
            h.style.width = c.w + 'px';
            head.appendChild(h);
        });
        body.style.height = G.height + 'px';
        grid.appendChild(head);
        grid.appendChild(body);
        main.appendChild(grid);
        function col(id) { return cols.find(function (c) { return c.id === id; }); }

        // the dates at the left (their labels kept in view), the years and months across
        var axis = el('div', 'rb-tl-axis');
        axis.style.height = G.height + 'px';
        body.appendChild(axis);
        G.seg.forEach(function (g) {
            var yr = el('div', 'rb-tl-yr' + (g.yr % 2 === 0 ? ' is-even' : '') + (g.quiet ? ' is-quiet' : ''));
            yr.style.top = g.top + 'px';
            yr.style.height = g.h + 'px';
            yr.appendChild(el('span', 'rb-tl-ylab', String(g.yr)));
            axis.appendChild(yr);
            if(g.yr % 2 === 0) {
                var band = el('div', 'rb-tl-yearband');
                band.style.top = g.top + 'px';
                band.style.height = g.h + 'px';
                body.appendChild(band);
            }
            var line = el('div', 'rb-tl-yline');
            line.style.top = g.top + 'px';
            body.appendChild(line);
            if(g.quiet) {
                return;
            }
            for(var m = 0; m < 12; m++) {
                var a = Date.UTC(g.yr, m, 1), b = Date.UTC(g.yr, m + 1, 1);
                if(b <= g.a || a >= g.b) {
                    continue;
                }
                var s0 = new Date(Math.max(a, g.a)).toISOString().slice(0, 10),
                    s1 = new Date(Math.min(b, g.b)).toISOString().slice(0, 10),
                    top = G.y(s0), bottom = b >= g.b ? g.top + g.h : G.y(s1),
                    mo = el('div', 'rb-tl-mo');
                mo.style.top = (top - g.top) + 'px';
                mo.style.height = (bottom - top) + 'px';
                mo.appendChild(el('span', 'rb-tl-mlab', moment.monthsShort(m)));
                yr.appendChild(mo);
                if(m > 0 && a >= g.a) {
                    var ml = el('div', 'rb-tl-mline');
                    ml.style.top = top + 'px';
                    body.appendChild(ml);
                }
            }
        });

        // the official formats, where the data draws them, cut where their micro-formats start
        d.formats.forEach(function (f) {
            (f.drawn || [{start: f.start, end: f.end, columns: [f.column || f.id]}]).forEach(function (p) {
                var cs = p.columns.map(col).filter(Boolean);
                if(!cs.length) {
                    return;
                }
                var x0 = Math.min.apply(null, cs.map(function (c) { return c.x; })),
                    x1 = Math.max.apply(null, cs.map(function (c) { return c.x + c.w; })),
                    a = p.start, b = p.end || d.range.end,
                    r = rect(body, G.y(a), G.y(b), x0 + 4, x1 - x0 - 8, 'is-format rb-tl-f-' + f.id + (f.retroactive ? ' is-retro' : ''), f.name, f.retroactive ? t('retroactive') : '');
                r.title = f.name;
                $(r).data('select', {type: 'format', item: f});
                d.microformats.forEach(function (m) {
                    if(m.format === f.id && m.start !== f.start && m.start >= a && m.start < b) {
                        var cut = el('div', 'rb-tl-cut');
                        cut.style.top = (G.y(m.start) - G.y(a)) + 'px';
                        r.appendChild(cut);
                    }
                });
            });
        });

        // Gemp's best fit for each era
        var gc = col('gemp');
        if(gc) {
            d.gemp.forEach(function (g) {
                var r = rect(body, G.y(g.start), G.y(g.end || d.range.end), gc.x + 4, gc.w - 8, 'is-gemp is-fit-' + (g.fit || 'exact'), g.name, g.code);
                r.title = g.name + ' (' + g.code + '): ' + t('fit.' + (g.fit || 'exact')) + (g.note ? '\n' + g.note : '');
                $(r).data('select', {type: 'gemp', item: g});
            });
        }

        // Redbook: the main format's micro-formats
        var rc = col('redbook');
        if(rc) {
            d.primary.forEach(function (span) {
                d.microformats.forEach(function (m) {
                    if(m.family !== span.family) {
                        return;
                    }
                    var a = m.start > span.start ? m.start : span.start,
                        m_end = m.end || d.range.end, s_end = span.end || d.range.end,
                        b = m_end < s_end ? m_end : s_end;
                    if(a >= b) {
                        return;
                    }
                    var r = rect(body, G.y(a), G.y(b), rc.x + 4, rc.w - 8, 'is-redbook', m.name, fmt_date(m.start) + ' – ' + (m.end ? fmt_date(m.end) : '…'));
                    r.title = m.name;
                    $(r).data('select', {type: 'micro', item: m});
                });
            });
        }

        // the events: a line, a marker each (side by side on the same day), labels kept apart
        var ec = col('events'), spine = el('div', 'rb-tl-spine'), last = -1e9, same_day = {},
            svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg'), hl = el('div', 'rb-tl-hl');
        spine.style.left = (ec.x + 18) + 'px';
        spine.style.height = G.height + 'px';
        body.appendChild(spine);
        svg.setAttribute('class', 'rb-tl-leaders');
        svg.setAttribute('width', width);
        svg.setAttribute('height', G.height);
        body.appendChild(svg);
        body.appendChild(hl);
        d.events.forEach(function (e) {
            var my = G.y(e.date), ly = Math.max(my - LH / 2, last + LH),
                nth = same_day[e.date] = (same_day[e.date] === undefined ? -1 : same_day[e.date]) + 1,
                mark = el('div', 'rb-tl-mark'), label = el('div', 'rb-tl-lab');
            last = ly;
            mark.style.left = (ec.x + 19 + nth * 9) + 'px';
            mark.style.zIndex = 3 + nth;
            mark.style.top = my + 'px';
            mark.style.background = kind_color(e.kind);
            label.style.left = (ec.x + 38) + 'px';
            label.style.top = ly + 'px';
            label.style.width = (ec.w - 44) + 'px';
            label.innerHTML = '<b>' + esc(e.shown_date || e.date) + '</b>' + esc(e.title);
            label.title = (e.shown_date || e.date) + ' — ' + e.title;
            if(Math.abs(ly + LH / 2 - my) > 2) {
                var path = document.createElementNS('http://www.w3.org/2000/svg', 'path');
                path.setAttribute('d', 'M' + (ec.x + 25) + ' ' + my + ' L' + (ec.x + 31) + ' ' + my + ' L' + (ec.x + 36) + ' ' + (ly + LH / 2));
                svg.appendChild(path);
            }
            [mark, label].forEach(function (n) {
                $(n).data('select', {type: 'event', item: e}).data('line', my).addClass('rb-tl-event');
            });
            $(mark).data('label', label);
            body.appendChild(mark);
            body.appendChild(label);
        });
        if(last + LH > G.height) {
            body.style.height = axis.style.height = (last + LH + 30) + 'px';
        }

        // hovering an event marks its date across; a click shows anything's details
        $(body).on('mouseenter', '.rb-tl-event', function () {
            hl.style.top = $(this).data('line') + 'px';
            hl.style.display = 'block';
        }).on('mouseleave', '.rb-tl-event', function () {
            hl.style.display = 'none';
        }).on('click', '.rb-tl-rect, .rb-tl-event', function () {
            var node = $(this).data('label') || this;
            select($(this).data('select'), node);
        });
    }

    /* ---- the details pane ---- */

    function pane() { return $('#tl-panel-body'); }

    function overview()
    {
        $('.rb-tl-grid .is-sel').removeClass('is-sel');
        var d = S.data,
            kinds = Object.keys(d.eventKinds).map(function (k) {
                return '<li><span class="rb-tl-dot" style="background:' + kind_color(k) + '"></span>' + esc(d.eventKinds[k]) + '</li>';
            }).join(''),
            cols = d.columns.filter(function (c) { return c.kind === 'official'; }).map(function (c) {
                var names = d.formats.filter(function (f) {
                    return (f.drawn || []).some(function (p) { return p.columns.indexOf(c.id) >= 0; });
                }).sort(function (a, b) { return a.start < b.start ? -1 : 1; }).map(function (f) { return esc(f.name); }).join(' → ');
                return '<dt>' + esc(c.name) + '</dt><dd>' + names + '</dd>';
            }).join('');
        pane().html('<h2>' + esc(t('overview.title')) + '</h2><p>' + esc(t('overview.intro')) + '</p>'
            + '<h3>' + esc(t('events')) + '</h3><ul class="rb-tl-legend">' + kinds + '</ul>'
            + '<h3>' + esc(t('columns')) + '</h3><dl>' + cols
            + '<dt>' + esc(t('col.gemp')) + '</dt><dd>' + esc(t('overview.gemp')) + '</dd>'
            + '<dt>' + esc(t('col.redbook')) + '</dt><dd>' + esc(t('overview.redbook')) + '</dd></dl>'
            + '<p class="rb-muted">' + esc(t('overview.note')) + '</p>'
            + '<p class="rb-muted rb-tl-generated">' + esc(t('generated', {date: String(d.generatedAt || '').slice(0, 10), events: d.events.length, micro: d.microformats.length})) + '</p>');
    }

    function select(choice, node)
    {
        if(!choice) {
            return;
        }
        $('.rb-tl-grid .is-sel').removeClass('is-sel');
        node && $(node).addClass('is-sel');
        pane().html('<a href="#" class="rb-tl-back">← ' + esc(t('overview.back')) + '</a>' + detail(choice));
        $('#tl-panel').scrollTop(0);
    }

    function sources(list)
    {
        if(!list || !list.length) {
            return '<p class="rb-muted">' + esc(t('no_source')) + '</p>';
        }
        return list.map(function (s) {
            var where = (s.url ? (/^https?:/.test(s.url) ? '<a href="' + esc(s.url) + '" target="_blank" rel="noopener">' + esc(s.url) + '</a>' : esc(s.url)) : '')
                + (s.clause ? (s.url ? '<br>' : '') + esc(t('clause')) + ' <code>' + esc(s.clause) + '</code>' : '')
                + (s.captured ? ' <span class="rb-muted">(' + esc(s.captured) + ')</span>' : '');
            return '<div class="rb-tl-src">' + where + (s.note ? '<div>' + esc(s.note) + '</div>' : '') + (s.quote ? '<q>' + esc(s.quote) + '</q>' : '') + '</div>';
        }).join('');
    }

    function card_list(cards)
    {
        if(!cards || !cards.length) {
            return '';
        }
        return '<ul>' + cards.map(function (c) {
            return '<li>' + card_link(c.card, c.title || c.name) + ' <code>' + esc(c.card) + '</code>' + (c.change ? ' — ' + esc(c.change) : '') + (c.note ? '<div class="rb-muted">' + esc(c.note) + '</div>' : '') + '</li>';
        }).join('') + '</ul>';
    }

    function event_link(e) { return '<a href="#" data-ev="' + esc(e.id) + '">' + esc(e.title) + '</a>'; }

    function format_name(id)
    {
        var f = S.data.formats.find(function (f) { return f.id === id; });
        return f ? f.name : id;
    }

    function detail(choice)
    {
        var d = S.data, x = choice.item;
        if(choice.type === 'event') {
            var affects = (x.affects || []).map(format_name).join(', ') || '—';
            return '<h2>' + esc(x.title) + '</h2><dl><dt>' + esc(t('date')) + '</dt><dd>' + esc(fmt_date(x.shown_date || x.date)) + '</dd>'
                + '<dt>' + esc(t('kind')) + '</dt><dd>' + esc(d.eventKinds[x.kind] || x.kind) + '</dd>'
                + '<dt>' + esc(t('affects')) + '</dt><dd>' + esc(affects) + '</dd></dl>'
                + (x.detail ? '<p>' + esc(x.detail) + '</p>' : '')
                + (x.changes && x.changes.length ? '<h3>' + esc(t('changes')) + '</h3>' + card_list(x.changes.map(function (c) { return {card: c.card, title: c.title || c.name, change: c.change}; })) : '')
                + (x.cards && !x.changes && typeof x.cards[0] === 'object' ? '<h3>' + esc(t('cards')) + '</h3>' + card_list(x.cards) : '')
                + '<h3>' + esc(t('sources')) + '</h3>' + sources(x.sources);
        }
        if(choice.type === 'format') {
            var micro = d.microformats.filter(function (m) { return m.format === x.id; }),
                drawn_end = (x.drawn || []).reduce(function (e, p) { return (!e || (p.end && p.end > e)) ? p.end : e; }, null);
            return '<h2>' + esc(x.name) + '</h2><dl><dt>' + esc(t('from')) + '</dt><dd>' + esc(fmt_date(x.start)) + (x.retroactive ? ' (' + esc(t('retroactive')) + ')' : '') + '</dd>'
                + '<dt>' + esc(t('to')) + '</dt><dd>' + esc(fmt_date(x.end)) + '</dd>'
                + (!x.end && drawn_end ? '<dt>' + esc(t('drawn_until')) + '</dt><dd>' + esc(t('drawn_until_text', {date: fmt_date(drawn_end)})) + '</dd>' : '')
                + '<dt>' + esc(t('sets')) + '</dt><dd>' + esc((x.sets || []).join(', ')) + '</dd>'
                + '<dt>' + esc(t('lists')) + '</dt><dd>' + esc((x.lists || []).map(function (k) { return d.listNames && d.listNames[k] || k; }).join(', ') || '—') + '</dd>'
                + '<dt>' + esc(t('sites')) + '</dt><dd>' + (x.sites || []).map(function (s) { return esc(fmt_date(s[0]) + ': ' + s[1]); }).join('<br>') + '</dd>'
                + (x.gemp ? '<dt>' + esc(t('gemp_code')) + '</dt><dd><a href="' + esc(Routing.generate('formats')) + '#format-' + esc(x.gemp) + '"><code>' + esc(x.gemp) + '</code></a></dd>' : '')
                + '</dl>'
                + (x.note ? '<p>' + esc(x.note) + '</p>' : '') + (x.reprintsKeepLegal ? '<p>' + esc(x.reprintsKeepLegal) + '</p>' : '')
                + (x.source ? '<h3>' + esc(t('source')) + '</h3>' + sources([x.source]) : '')
                + '<h3>' + esc(t('micro_count', {count: micro.length})) + '</h3><ul>' + micro.map(function (m) {
                    return '<li><a href="#" data-micro="' + esc(m.id) + '">' + esc(m.name) + '</a>: ' + esc(fmt_date(m.start)) + ' – ' + esc(m.end ? fmt_date(m.end) : '…') + '</li>';
                }).join('') + '</ul>';
        }
        if(choice.type === 'gemp') {
            return '<h2>' + esc(x.name) + '</h2><dl><dt>' + esc(t('gemp_code')) + '</dt><dd><a href="' + esc(Routing.generate('formats')) + '#format-' + esc(x.code) + '"><code>' + esc(x.code) + '</code></a></dd>'
                + '<dt>' + esc(t('era')) + '</dt><dd>' + esc(fmt_date(x.start)) + ' – ' + esc(x.end ? fmt_date(x.end) : '…') + '</dd>'
                + '<dt>' + esc(t('fit_label')) + '</dt><dd>' + esc(t('fit.' + (x.fit || 'exact'))) + '</dd></dl>' + (x.note ? '<p>' + esc(x.note) + '</p>' : '');
        }
        if(choice.type === 'micro') {
            var lists = Object.keys(x.lists || {}).map(function (k) {
                    var v = x.lists[k];
                    return '<li>' + esc(d.listNames && d.listNames[k] || k) + ': ' + esc(t('list_count', {count: v.n, date: fmt_date(v.since)})) + '</li>';
                }).join(''),
                rules = Object.keys(x.rules || {}).map(function (k) {
                    return '<li>' + esc(k) + ': ' + esc(t(x.rules[k] ? 'in_force' : 'not_yet')) + '</li>';
                }).join(''),
                rulings = (x.rulings || []).map(function (r) {
                    return '<li><a href="#" data-ev="' + esc(r.id) + '">' + esc(r.title) + '</a> — ' + esc(t('since', {date: fmt_date(r.since)})) + '</li>';
                }).join(''),
                starts = (x.startEvents || []).map(function (id) { return d.events.find(function (e) { return e.id === id; }); })
                    .filter(Boolean).map(function (e) { return '<li>' + event_link(e) + '</li>'; }).join('');
            return '<h2>' + esc(x.name) + '</h2><dl><dt>' + esc(t('format')) + '</dt><dd>' + esc(format_name(x.format)) + '</dd>'
                + '<dt>' + esc(t('from')) + '</dt><dd>' + esc(fmt_date(x.start)) + '</dd><dt>' + esc(t('to')) + '</dt><dd>' + esc(x.end ? fmt_date(x.end) : t('open_ended')) + '</dd>'
                + '<dt>' + esc(t('sets')) + '</dt><dd>' + esc((x.sets || []).join(', ')) + '</dd><dt>' + esc(t('sites')) + '</dt><dd>' + esc(x.sites || '—') + '</dd>'
                + '<dt>' + esc(t('decipher_errata')) + '</dt><dd>' + esc(cards_text(x.decipherErrata || 0)) + '</dd>'
                + (x.pcErrata != null ? '<dt>' + esc(t('pc_errata')) + '</dt><dd>' + esc(cards_text(x.pcErrata)) + '</dd>' : '') + '</dl>'
                + (x.extraCards && x.extraCards.length ? '<h3>' + esc(t('also_legal')) + '</h3>' + card_list(x.extraCards) : '')
                + (lists ? '<h3>' + esc(t('lists')) + '</h3><ul>' + lists + '</ul>' : '')
                + (rules ? '<h3>' + esc(t('rules')) + '</h3><ul>' + rules + '</ul>' : '')
                + (rulings ? '<h3>' + esc(t('rulings')) + '</h3><ul>' + rulings + '</ul>' : '')
                + (starts ? '<h3>' + esc(t('starts_with')) + '</h3><ul>' + starts + '</ul>' : '');
        }
        return '';
    }

    ui.on_dom_loaded = function on_dom_loaded()
    {
        var page = $('#format-timeline');
        if(!page.length || !page.data('url')) {
            return;
        }
        var main = document.getElementById('tl-main');
        $.getJSON(page.data('url')).done(function (data) {
            S.data = data;
            render(main);
            overview();
        }).fail(function () {
            $(main).html('<p class="rb-tl-empty">' + esc(t('failed')) + '</p>');
        });
        $('#tl-panel').on('click', '.rb-tl-back', function (event) {
            event.preventDefault();
            overview();
        }).on('click', '[data-ev]', function (event) {
            event.preventDefault();
            var id = $(this).data('ev'),
                e = S.data.events.find(function (x) { return x.id === id; });
            e && select({type: 'event', item: e}, $('.rb-tl-lab').filter(function () { return $(this).data('select') && $(this).data('select').item === e; })[0]);
        }).on('click', '[data-micro]', function (event) {
            event.preventDefault();
            var id = $(this).data('micro'),
                m = S.data.microformats.find(function (x) { return x.id === id; });
            m && select({type: 'micro', item: m});
        });
        $(document).on('keydown', function (event) {
            if(event.key === 'Escape' && S.data && !$('.modal.in').length) {
                overview();
            }
        });
    };

})(app.ui, jQuery);
