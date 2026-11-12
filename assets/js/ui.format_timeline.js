/* global app, Translator, Routing, moment, _ */

/*
 * The Formats page's Timeline tab (Ketura, 2026-11-11), from the other instance's draft
 * (_planning/timeline/format-timeline.html), in Red Book's dress. It draws the format timeline
 * (App\Services\FormatTimeline; its data is format-timeline.json, described in
 * _planning/timeline/format-timeline-data.md):
 * - rows: time, top to bottom; a year with events is to scale, a year without is one short row,
 *   and a micro-format too short to hold its name is stretched until it does
 * - columns: the events (a marker on the line each, its shape and colour its group), the
 *   official formats (where the data's formats[].drawn puts them, cut where their micro-formats
 *   start), the Gemp format that fits each era (those in Gemp's hall), and the Red Book
 *   micro-formats of the main format
 * - a click on any of them shows its details and sources in the pane beside, Escape (or the
 *   pane's Overview) the key to it all; cards named in them link to their pages, an erratum's
 *   hover showing the card before and after
 * - each micro-format has an address of its own (#<micro-format>), so a deck can link to the
 *   one it was built for
 * The timeline scrolls both ways inside its own pane, the dates kept at its left and the column
 * names at its top.
 */
(function ui_format_timeline(ui, $)
{
    var ZOOM = 1.6,         // px per day in a year with events
        QUIET_ROW = 26,     // px: a year with no events
        MIN_SPAN = 22,      // px: the least a Red Book micro-format is drawn, room for its name
        LH = 17,            // px: an event's label
        DOT = 22,           // px: an event's marker
        DOT_STEP = 14,      // px: between markers on the same day
        COLW = {axis: 96, events: 400, official: 132, gemp: 170, redbook: 176},
        DAY = 86400000,
        // the events' kinds in five groups, each a colour and a shape (Ketura, 2026-11-11)
        GROUPS = [
            {id: 'cards', kinds: ['set', 'card'], shape: 'circle'},
            {id: 'lists', kinds: ['list', 'rotation'], shape: 'square'},
            {id: 'news', kinds: ['format', 'precedence'], shape: 'diamond'},
            {id: 'errata', kinds: ['errata', 'pc-errata'], shape: 'triangle'},
            {id: 'rules', kinds: ['rule', 'ruling'], shape: 'hexagon'}
        ],
        SHAPES = {
            circle: '<circle cx="10" cy="10" r="7.5"/>',
            square: '<rect x="3" y="3" width="14" height="14" rx="1.5"/>',
            diamond: '<polygon points="10,1.5 18.5,10 10,18.5 1.5,10"/>',
            triangle: '<polygon points="10,2 18.5,17.5 1.5,17.5"/>',
            hexagon: '<polygon points="10,1.5 17.5,5.75 17.5,14.25 10,18.5 2.5,14.25 2.5,5.75"/>'
        },
        // sites gone, whose pages only the Wayback Machine keeps
        DEAD = /^https?:\/\/(?:www\.)?(?:decipher\.com|dgma\.com|[\w.]*fanhq\.com)\b/i,
        S = {data: null, hall: [], revisions: {}, rects: {}, view: null};

    function t(key, params) { return Translator.trans('formats.timeline.' + key, params || {}); }

    function count_text(key, n) { return Translator.transChoice('formats.timeline.' + key, n, {count: n}); }

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

    function group_of(kind)
    {
        return _.find(GROUPS, function (g) { return g.kinds.indexOf(kind) >= 0; }) || GROUPS[2];
    }

    /** An event's marker: its group's shape in its group's colour */
    function shape(group)
    {
        return '<svg class="rb-tl-shape is-' + group.id + '" viewBox="0 0 20 20" aria-hidden="true">' + SHAPES[group.shape] + '</svg>';
    }

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

    /** A PC errata event's title without its sets ("PC errata: 22 cards (sets 1, 2, 3)") */
    function title_of(e)
    {
        return e.kind === 'pc-errata' ? String(e.title).replace(/\s*\(sets? [^)]*\)\s*$/, '') : e.title;
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

    /**
     * An erratum: a link to the card's revision (on the PC errata blueprint's page for a PC
     * erratum, as [[card.n]] links go), its hover the card before and after
     */
    function errata_link(c, pc)
    {
        var code = card_code(/_\d+$/.test(String(c.card)) ? c.card : (c.gemp || c.card)),
            n = parseInt(c.revision, 10),
            info = S.revisions[code];
        if(!/^\d+_\d+$/.test(code)) {
            return esc(c.title || c.card);
        }
        var page = pc && info && info.errata ? info.errata : code,
            href = Routing.generate('cards_zoom', {card_code: page}) + (isNaN(n) ? '' : '#v' + n),
            pair = '';
        if(info && !isNaN(n)) {
            var after = info.v[n], before = null;
            for(var k = n - 1; k >= 0 && !before; k--) {
                before = info.v[k] || null;
            }
            if(before && after) {
                pair = ' data-hover-pair="' + esc(before + '|' + after) + '"';
            } else if(after) {
                pair = ' data-image="' + esc(after) + '"';
            }
        }
        return '<a class="card-tip" data-code="' + esc(code) + '" href="' + esc(href) + '"' + pair + '>' + esc(c.title || code) + '</a>';
    }

    /** An address the Wayback Machine keeps, for a site that's gone; about the date given */
    function archived(url, when)
    {
        if(!DEAD.test(url)) {
            return url;
        }
        var stamp = String(when || '').replace(/\D/g, '').slice(0, 14) || '2';
        return 'https://web.archive.org/web/' + stamp + '/' + url;
    }

    /** Text with its addresses as links: [url text] (wiki style), bare http(s), and a gone site's "dgma.com/…" */
    function linkify(text, when)
    {
        var out = esc(text);
        out = out.replace(/\[(https?:\/\/[^\s\]]+) ([^\]]+)\]/g, function (m, url, label) {
            return '<a href="' + archived(url, when) + '" target="_blank" rel="noopener">' + label + '</a>';
        });
        out = out.replace(/(^|[\s(;])((?:https?:\/\/)?(?:www\.)?(?:decipher\.com|dgma\.com|[\w.]*fanhq\.com)\/[^\s)<]+|https?:\/\/[^\s)<]+)/g, function (m, lead, url) {
            if(/href="$/.test(lead)) {
                return m;
            }
            var full = /^https?:/.test(url) ? url : 'http://' + url;
            return lead + '<a href="' + esc(archived(full, when)) + '" target="_blank" rel="noopener">' + url + '</a>';
        });
        return out;
    }

    /* ---- geometry ---- */

    /** The micro-formats the Red Book column draws: each main format's, within its span */
    function redbook_spans()
    {
        var d = S.data, spans = [];
        d.primary.forEach(function (span) {
            d.microformats.forEach(function (m) {
                if(m.family !== span.family) {
                    return;
                }
                var a = m.start > span.start ? m.start : span.start,
                    m_end = m.end || d.range.end, s_end = span.end || d.range.end,
                    b = m_end < s_end ? m_end : s_end;
                if(a < b) {
                    spans.push({micro: m, start: a, end: b});
                }
            });
        });
        return spans;
    }

    /**
     * y as a function of the date: piecewise linear. A year with no events is one short row;
     * one with events is to scale, cut at the Red Book micro-formats' edges, and a piece too
     * short to hold a micro-format's name stretched until it does
     */
    function geom(spans)
    {
        var d = S.data, pad = 30, busy = {}, seg = [], years = [], off = pad,
            r0 = Date.parse(d.range.start), r1 = Date.parse(d.range.end), cuts = {};
        d.events.forEach(function (e) { busy[+String(e.date).slice(0, 4)] = true; });
        spans.forEach(function (s) {
            cuts[Date.parse(s.start)] = true;
            cuts[Date.parse(s.end)] = true;
        });
        var cut_times = Object.keys(cuts).map(Number).sort(function (a, b) { return a - b; });
        for(var yr = +d.range.start.slice(0, 4); yr <= +d.range.end.slice(0, 4); yr++) {
            var a = Math.max(Date.UTC(yr, 0, 1), r0), b = Math.min(Date.UTC(yr + 1, 0, 1), r1), top = off;
            if(b <= a) {
                continue;
            }
            if(!busy[yr]) {
                seg.push({a: a, b: b, top: off, h: QUIET_ROW});
                off += QUIET_ROW;
            } else {
                var points = [a].concat(cut_times.filter(function (x) { return x > a && x < b; }), [b]);
                for(var i = 0; i + 1 < points.length; i++) {
                    var h = Math.max((points[i + 1] - points[i]) / DAY * ZOOM, MIN_SPAN);
                    seg.push({a: points[i], b: points[i + 1], top: off, h: h});
                    off += h;
                }
            }
            years.push({yr: yr, a: a, b: b, top: top, h: off - top, quiet: !busy[yr]});
        }
        function y(s)
        {
            s = String(s);
            var time = Date.parse(s.length === 4 ? s + '-01-01' : (s.length === 7 ? s + '-01' : s)),
                g = _.find(seg, function (g) { return time < g.b; }) || seg[seg.length - 1];
            return g.top + Math.min(1, Math.max(0, (time - g.a) / (g.b - g.a))) * g.h;
        }
        return {y: y, years: years, height: off + pad};
    }

    function columns()
    {
        var cols = [{id: 'axis', name: '', kind: 'axis', w: COLW.axis}, {id: 'events', name: t('events'), kind: 'events', w: COLW.events}], x = 0;
        S.data.columns.forEach(function (c) {
            var key = 'formats.timeline.col.' + c.id, name = Translator.trans(key);
            cols.push($.extend({}, c, {name: name !== key ? name : c.name, w: COLW[c.kind] || 150}));
        });
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
        var d = S.data, spans = redbook_spans(), G = S.G = geom(spans), layout = columns(), cols = layout.cols, width = layout.width;
        main.innerHTML = '';
        S.rects = {};
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
        function col(id) { return _.find(cols, function (c) { return c.id === id; }); }

        // the dates at the left (their labels kept in view), the years and months across
        var axis = el('div', 'rb-tl-axis');
        axis.style.height = G.height + 'px';
        body.appendChild(axis);
        G.years.forEach(function (g) {
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

        // Gemp's best fit for each era: only those in Gemp's hall, which anyone can play
        var gc = col('gemp');
        if(gc) {
            d.gemp.forEach(function (g) {
                if(S.hall.indexOf(g.code) < 0) {
                    return;
                }
                var r = rect(body, G.y(g.start), G.y(g.end || d.range.end), gc.x + 4, gc.w - 8, 'is-gemp is-fit-' + (g.fit || 'exact'), g.name, g.code);
                r.title = g.name + ' (' + g.code + '): ' + t('fit.' + (g.fit || 'exact')) + (g.note ? '\n' + g.note : '');
                $(r).data('select', {type: 'gemp', item: g});
            });
        }

        // Red Book: the main format's micro-formats
        var rc = col('redbook');
        if(rc) {
            spans.forEach(function (s) {
                var m = s.micro,
                    r = rect(body, G.y(s.start), G.y(s.end), rc.x + 4, rc.w - 8, 'is-redbook', m.name, fmt_date(m.start) + ' – ' + (m.end ? fmt_date(m.end) : '…'));
                r.title = m.name;
                $(r).data('select', {type: 'micro', item: m});
                S.rects[m.id] = r;
            });
        }

        // the events: a line, a marker each (side by side on the same day, one for a group's
        // several), labels kept apart
        var ec = col('events'), spine = el('div', 'rb-tl-spine'), last = -1e9, day_groups = {},
            svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg'), hl = el('div', 'rb-tl-hl'),
            label_x = ec.x + 20 + DOT_STEP * 2 + DOT / 2 + 6;
        spine.style.left = (ec.x + 19) + 'px';
        spine.style.height = G.height + 'px';
        body.appendChild(spine);
        svg.setAttribute('class', 'rb-tl-leaders');
        svg.setAttribute('width', width);
        svg.setAttribute('height', G.height);
        body.appendChild(svg);
        body.appendChild(hl);
        d.events.forEach(function (e) {
            var group = group_of(e.kind), my = G.y(e.date), ly = Math.max(my - LH / 2, last + LH),
                label = el('div', 'rb-tl-lab'), groups = day_groups[e.date] = day_groups[e.date] || [],
                nth = groups.indexOf(group.id), mark = null;
            last = ly;
            if(nth < 0) {
                nth = groups.push(group.id) - 1;
                mark = el('div', 'rb-tl-mark');
                mark.innerHTML = shape(group);
                mark.style.left = (ec.x + 20 + nth * DOT_STEP) + 'px';
                mark.style.zIndex = 3 + nth;
                mark.style.top = my + 'px';
                mark.title = S.data.eventKinds[e.kind] || e.kind;
            }
            label.style.left = label_x + 'px';
            label.style.top = ly + 'px';
            label.style.width = (ec.x + ec.w - label_x - 6) + 'px';
            label.innerHTML = '<b>' + esc(e.shown_date || e.date) + '</b>' + esc(title_of(e));
            label.title = (e.shown_date || e.date) + ' — ' + title_of(e);
            if(Math.abs(ly + LH / 2 - my) > 2) {
                var path = document.createElementNS('http://www.w3.org/2000/svg', 'path'), x0 = ec.x + 20 + (groups.length - 1) * DOT_STEP + DOT / 2;
                path.setAttribute('d', 'M' + x0 + ' ' + my + ' L' + (label_x - 8) + ' ' + my + ' L' + (label_x - 2) + ' ' + (ly + LH / 2));
                svg.appendChild(path);
            }
            $(label).data('select', {type: 'event', item: e}).data('line', my).addClass('rb-tl-event');
            if(mark) {
                $(mark).data('select', {type: 'event', item: e}).data('line', my).data('label', label).addClass('rb-tl-event');
                body.appendChild(mark);
            }
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
        set_hash(null);
        var d = S.data,
            groups = GROUPS.map(function (g) {
                return '<li>' + shape(g) + esc(t('group.' + g.id)) + '</li>';
            }).join(''),
            cols = ['block', 'competitive', 'permissive', 'gemp', 'redbook'].map(function (id) {
                return '<dt>' + esc(t('col.' + id)) + '</dt><dd>' + esc(t('col_desc.' + id)) + '</dd>';
            }).join('');
        pane().html('<h2>' + esc(t('overview.title')) + '</h2><p>' + esc(t('overview.intro')) + '</p>'
            + '<h3>' + esc(t('events')) + '</h3><ul class="rb-tl-legend">' + groups + '</ul>'
            + '<h3>' + esc(t('columns')) + '</h3><dl>' + cols + '</dl>'
            + '<p class="rb-muted">' + esc(t('overview.note')) + '</p>'
            + '<p class="rb-muted rb-tl-generated">' + esc(t('generated', {date: String(d.generatedAt || '').slice(0, 10), events: d.events.length, micro: d.microformats.length})) + '</p>');
    }

    function select(choice, node)
    {
        if(!choice) {
            return;
        }
        $('.rb-tl-grid .is-sel').removeClass('is-sel');
        node = node || (choice.type === 'micro' ? S.rects[choice.item.id] : null);
        node && $(node).addClass('is-sel');
        pane().html('<a href="#" class="rb-tl-back">← ' + esc(t('overview.back')) + '</a>' + detail(choice));
        $('#tl-panel').scrollTop(0);
        set_hash(choice.type === 'micro' ? choice.item.id : null);
    }

    /** The address bar names the micro-format shown (#<micro-format>), with no history entry */
    function set_hash(id)
    {
        var hash = id ? '#' + id : '';
        if(window.location.hash !== hash && window.history.replaceState && document.readyState === 'complete') {
            window.history.replaceState(window.history.state, '', window.location.pathname + window.location.search + hash);
        }
    }

    function sources(list, when)
    {
        if(!list || !list.length) {
            return '<p class="rb-muted">' + esc(t('no_source')) + '</p>';
        }
        return list.map(function (s) {
            var where = '';
            if(s.url) {
                // the address in an address with words before it ("card data; http://…")
                var url = (String(s.url).match(/https?:\/\/\S+/) || [null])[0];
                where = url ? '<a href="' + esc(archived(url, s.captured || when)) + '" target="_blank" rel="noopener">' + esc(url) + '</a>' : esc(s.url);
            }
            where += s.clause ? (where ? '<br>' : '') + esc(t('clause')) + ' <code>' + esc(s.clause) + '</code>' : '';
            where += s.captured ? ' <span class="rb-muted">(' + esc(s.captured) + ')</span>' : '';
            return '<div class="rb-tl-src">' + where + (s.note ? '<div>' + linkify(s.note, s.captured || when) + '</div>' : '') + (s.quote ? '<q>' + esc(s.quote) + '</q>' : '') + '</div>';
        }).join('');
    }

    function card_list(cards)
    {
        if(!cards || !cards.length) {
            return '';
        }
        return '<ul>' + cards.map(function (c) {
            return '<li>' + card_link(c.card, c.title || c.name) + ' <code>' + esc(c.card) + '</code>' + (c.change ? ' — ' + esc(c.change) : '') + '</li>';
        }).join('') + '</ul>';
    }

    /** Errata: each with its before-and-after hover; what was changed and why */
    function errata_list(cards, pc, when)
    {
        return '<ul class="rb-tl-errata">' + cards.map(function (c) {
            return '<li>' + errata_link(c, pc) + (c.change ? ' — ' + esc(c.change) : '') + (c.note ? '<div class="rb-muted">' + linkify(c.note, when) + '</div>' : '') + '</li>';
        }).join('') + '</ul>';
    }

    /** The errata in force for a micro-format's card pool on its first day: {decipher, pc} */
    function errata_in_force(m)
    {
        var sets = {}, decipher = {}, pc = {};
        (m.sets || []).forEach(function (s) { sets[String(s)] = true; });
        S.data.events.forEach(function (e) {
            if(e.date > m.start || (e.kind !== 'errata' && e.kind !== 'pc-errata')) {
                return;
            }
            e.cards.forEach(function (c) {
                if(!sets[String(c.set)]) {
                    return;
                }
                if(e.kind === 'errata') {
                    decipher[c.card + '.' + c.revision] = c;
                } else {
                    pc[c.gemp || c.card] = c;
                }
            });
        });
        var by_title = function (c) { return String(c.title || '').replace(/^[•·]/, ''); };
        return {decipher: _.sortBy(_.values(decipher), by_title), pc: _.sortBy(_.values(pc), by_title)};
    }

    function event_link(e) { return '<a href="#" data-ev="' + esc(e.id) + '">' + esc(title_of(e)) + '</a>'; }

    function format_name(id)
    {
        var f = _.find(S.data.formats, function (f) { return f.id === id; });
        return f ? f.name : id;
    }

    function gemp_link(code)
    {
        return S.hall.indexOf(code) >= 0 ? '<a href="' + esc(Routing.generate('formats')) + '#format-' + esc(code) + '"><code>' + esc(code) + '</code></a>' : '<code>' + esc(code) + '</code>';
    }

    function detail(choice)
    {
        var d = S.data, x = choice.item;
        if(choice.type === 'event') {
            var affects = (x.affects || []).map(format_name).join(', ') || '—', cards = '';
            if(x.kind === 'errata' || x.kind === 'pc-errata') {
                cards = '<h3>' + esc(t('cards')) + '</h3>' + errata_list(x.cards || [], x.kind === 'pc-errata', x.date);
            } else if(x.changes && x.changes.length) {
                cards = '<h3>' + esc(t('changes')) + '</h3>' + card_list(x.changes.map(function (c) { return {card: c.card, title: c.title || c.name, change: c.change}; }));
            } else if(x.cards && typeof x.cards[0] === 'object') {
                cards = '<h3>' + esc(t('cards')) + '</h3>' + card_list(x.cards);
            }
            return '<h2>' + esc(title_of(x)) + '</h2><dl><dt>' + esc(t('date')) + '</dt><dd>' + esc(fmt_date(x.shown_date || x.date)) + '</dd>'
                + '<dt>' + esc(t('kind')) + '</dt><dd>' + esc(d.eventKinds[x.kind] || x.kind) + '</dd>'
                + '<dt>' + esc(t('affects')) + '</dt><dd>' + esc(affects) + '</dd></dl>'
                + (x.detail ? '<p>' + linkify(x.detail, x.date) + '</p>' : '') + cards
                + '<h3>' + esc(t('sources')) + '</h3>' + sources(x.sources, x.date);
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
                + (x.gemp ? '<dt>' + esc(t('gemp_code')) + '</dt><dd>' + gemp_link(x.gemp) + '</dd>' : '')
                + '</dl>'
                + (x.note ? '<p>' + linkify(x.note, x.start) + '</p>' : '') + (x.reprintsKeepLegal ? '<p>' + esc(x.reprintsKeepLegal) + '</p>' : '')
                + (x.source ? '<h3>' + esc(t('source')) + '</h3>' + sources([x.source], x.start) : '')
                + '<h3>' + esc(t('micro_count', {count: micro.length})) + '</h3><ul>' + micro.map(function (m) {
                    return '<li><a href="#' + esc(m.id) + '" data-micro="' + esc(m.id) + '">' + esc(m.name) + '</a>: ' + esc(fmt_date(m.start)) + ' – ' + esc(m.end ? fmt_date(m.end) : '…') + '</li>';
                }).join('') + '</ul>';
        }
        if(choice.type === 'gemp') {
            return '<h2>' + esc(x.name) + '</h2><dl><dt>' + esc(t('gemp_code')) + '</dt><dd>' + gemp_link(x.code) + '</dd>'
                + '<dt>' + esc(t('era')) + '</dt><dd>' + esc(fmt_date(x.start)) + ' – ' + esc(x.end ? fmt_date(x.end) : '…') + '</dd>'
                + '<dt>' + esc(t('fit_label')) + '</dt><dd>' + esc(t('fit.' + (x.fit || 'exact'))) + '</dd></dl>' + (x.note ? '<p>' + esc(x.note) + '</p>' : '');
        }
        if(choice.type === 'micro') {
            var errata = errata_in_force(x),
                lists = Object.keys(x.lists || {}).map(function (k) {
                    var v = x.lists[k];
                    return '<li>' + esc(d.listNames && d.listNames[k] || k) + ': ' + esc(t('list_count', {count: v.n, date: fmt_date(v.since)})) + '</li>';
                }).join(''),
                rulings = (x.rulings || []).map(function (r) {
                    return '<li><a href="#" data-ev="' + esc(r.id) + '">' + esc(r.title) + '</a> — ' + esc(t('since', {date: fmt_date(r.since)})) + '</li>';
                }).join(''),
                starts = (x.startEvents || []).map(function (id) { return _.find(d.events, function (e) { return e.id === id; }); })
                    .filter(Boolean).map(function (e) { return '<li>' + event_link(e) + '</li>'; }).join(''),
                collapsible = function (title, list, pc) {
                    return list.length ? '<details class="rb-tl-more"><summary>' + esc(title) + ' <span class="rb-tl-count">' + list.length + '</span></summary>' + errata_list(list, pc, x.start) + '</details>' : '';
                };
            return '<h2 class="rb-tl-micro-title">' + esc(x.name) + ' <a class="rb-tl-permalink" href="#' + esc(x.id) + '" title="' + esc(t('permalink')) + '">#</a></h2>'
                + '<dl><dt>' + esc(t('format')) + '</dt><dd>' + esc(format_name(x.format)) + '</dd>'
                + '<dt>' + esc(t('from')) + '</dt><dd>' + esc(fmt_date(x.start)) + '</dd><dt>' + esc(t('to')) + '</dt><dd>' + esc(x.end ? fmt_date(x.end) : t('open_ended')) + '</dd>'
                + '<dt>' + esc(t('sets')) + '</dt><dd>' + esc((x.sets || []).join(', ')) + '</dd><dt>' + esc(t('sites')) + '</dt><dd>' + esc(x.sites || '—') + '</dd></dl>'
                + (starts ? '<h3>' + esc(t('starts_with')) + '</h3><ul>' + starts + '</ul>' : '')
                + collapsible(t('decipher_errata'), errata.decipher, false)
                + (x.pcErrata != null ? collapsible(t('pc_errata'), errata.pc, true) : '')
                + (x.extraCards && x.extraCards.length ? '<h3>' + esc(t('also_legal')) + '</h3>' + card_list(x.extraCards) : '')
                + (lists ? '<h3>' + esc(t('lists')) + '</h3><ul>' + lists + '</ul>' : '')
                + (rulings ? '<h3>' + esc(t('rulings')) + '</h3><ul>' + rulings + '</ul>' : '');
        }
        return '';
    }

    /* ---- a micro-format's address ---- */

    /**
     * The micro-format an address names: its id; else, an id since changed (a span split, or
     * two in one month named to the day), the family's micro-format that starts in that month
     * (or on that day), or failing that the one in force then
     */
    function micro_for(id)
    {
        var all = S.data.microformats, exact = _.find(all, function (m) { return m.id === id; });
        if(exact || !id) {
            return exact || null;
        }
        var parts = /^(.+)-(\d{4}-\d{2})(-\d{2})?$/.exec(id);
        if(!parts) {
            return null;
        }
        var family = parts[1], when = parts[2] + (parts[3] || ''),
            mine = all.filter(function (m) { return m.family === family; });
        return _.find(mine, function (m) { return m.start.indexOf(when) === 0; })
            || _.find(mine, function (m) { var day = when.length === 7 ? when + '-01' : when; return m.start <= day && (!m.end || m.end > day); })
            || null;
    }

    /** Goes to the micro-format the address names: shown, scrolled to and lit up */
    function follow_hash()
    {
        var id = decodeURIComponent(window.location.hash.replace(/^#/, '')),
            m = micro_for(id);
        if(!m) {
            return false;
        }
        select({type: 'micro', item: m});
        var main = $('#tl-main')[0], r = S.rects[m.id],
            y = r ? r.offsetTop + r.offsetHeight / 2 : S.G.y(m.start);
        main.scrollTop = Math.max(0, y - main.clientHeight / 2);
        if(r) {
            main.scrollLeft = Math.max(0, r.offsetLeft + r.offsetWidth - main.clientWidth + 20);
            $(r).addClass('is-highlight');
            setTimeout(function () { $(r).removeClass('is-highlight'); }, 3000);
        }
        return true;
    }

    ui.on_dom_loaded = function on_dom_loaded()
    {
        var page = $('#format-timeline');
        if(!page.length || !page.data('url')) {
            return;
        }
        S.hall = page.data('hall') || [];
        S.revisions = page.data('revisions') || {};
        var main = document.getElementById('tl-main');
        $.getJSON(page.data('url')).done(function (data) {
            S.data = data;
            render(main);
            if(!follow_hash()) {
                overview();
            }
        }).fail(function () {
            $(main).html('<p class="rb-tl-empty">' + esc(t('failed')) + '</p>');
        });
        $('#tl-panel').on('click', '.rb-tl-back', function (event) {
            event.preventDefault();
            overview();
        }).on('click', '[data-ev]', function (event) {
            event.preventDefault();
            var id = $(this).data('ev'),
                e = _.find(S.data.events, function (x) { return x.id === id; });
            e && select({type: 'event', item: e}, $('.rb-tl-lab').filter(function () { return $(this).data('select') && $(this).data('select').item === e; })[0]);
        }).on('click', '[data-micro], .rb-tl-permalink', function (event) {
            event.preventDefault();
            var m = micro_for($(this).data('micro') || $(this).attr('href').replace(/^#/, ''));
            m && select({type: 'micro', item: m});
        });
        $(window).on('hashchange', function () {
            S.data && follow_hash();
        });
        $(document).on('keydown', function (event) {
            if(event.key === 'Escape' && S.data && !$('.modal.in').length) {
                overview();
            }
        });
    };

})(app.ui, jQuery);
