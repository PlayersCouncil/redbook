/* global app, _ */
/**
 * Game symbols in markdown, in the browser (Ketura, 2026-11-07): previews, and the editor's
 * symbol button. src/Services/MarkdownSymbols.php does the same when a text is saved:
 * change the two together.
 *
 *   [Moria] [Gondor] [Uruk-hai] ...   a culture's symbol, by name, any case
 *   [Towers] [King]                   the Towers and King block symbols
 *   1T..9T  1K..9K                    a number then the block symbol; [1T] and 1[T] too
 *   (0)..(9) (X)                      a twilight cost
 */
(function app_symbols(symbols)
{
    var CULTURES = {
        dunland: ['dunland', 'Dunland'], dwarven: ['dwarven', 'Dwarven'], elven: ['elven', 'Elven'],
        gandalf: ['gandalf', 'Gandalf'], gollum: ['gollum', 'Gollum'], gondor: ['gondor', 'Gondor'],
        isengard: ['isengard', 'Isengard'], men: ['men', 'Men'], moria: ['moria', 'Moria'],
        orc: ['orc', 'Orc'], raider: ['raider', 'Raider'], ringwraith: ['ringwraith', 'Ringwraith'],
        rohan: ['rohan', 'Rohan'], sauron: ['sauron', 'Sauron'], shire: ['shire', 'Shire'],
        theonering: ['the-one-ring', 'The One Ring'], urukhai: ['uruk-hai', 'Uruk-hai']
    };
    var BLOCKS = {t: ['towers', 'Towers Block', '[Towers]'], k: ['king', 'King Block', '[King]']};
    var CODE = /(```[\s\S]*?```|~~~[\s\S]*?~~~|`[^`\n]*`)/;

    function icon(cls, name, original)
    {
        return '<span class="rb-sym" data-tip="' + _.escape(name) + '"><span class="rb-sym-icon ' + cls + '"></span>'
            + '<span class="sr-only">' + _.escape(original) + '</span></span>';
    }

    function block(letter, original)
    {
        return icon('rb-sym-' + BLOCKS[letter][0], BLOCKS[letter][1], original);
    }

    function twilight(value, original)
    {
        return icon('rb-sym-twilight rb-sym-twilight-' + value, value.toUpperCase() + ' Twilight', original);
    }

    function bracketed(inner, original)
    {
        var m = /^([1-9])([tk])$/i.exec(inner);
        if(m) {
            return m[1] + block(m[2].toLowerCase(), original);
        }
        var key = inner.toLowerCase().replace(/[^a-z]/g, '');
        if(CULTURES[key]) {
            return icon('rb-sym-' + CULTURES[key][0], CULTURES[key][1], original);
        }
        if(key === 'towers' || key === 'tower') {
            return block('t', original);
        }
        return key === 'king' ? block('k', original) : null;
    }

    /** Swaps each symbol outside code for a token: [text, {token: html}] */
    symbols.protect = function protect(text)
    {
        var tokens = {}, n = 0,
            keep = function (html) { var token = 'rbsym' + (n++) + 'x'; tokens[token] = html; return token; },
            parts = (text || '').split(CODE);
        for(var i = 0; i < parts.length; i += 2) {
            parts[i] = parts[i]
                .replace(/\[([A-Za-z][A-Za-z \-']{0,20}|[1-9][TtKk])\](?![(\[:])/g, function (all, inner) {
                    var html = bracketed(inner, all);
                    return html === null ? all : keep(html);
                })
                .replace(/(?<![\w\]])([1-9])\[([TtKk])\]/g, function (all, num, letter) { return keep(num + block(letter.toLowerCase(), all)); })
                .replace(/(?<![\w\[])([1-9])([TK])(?![\w\]])/g, function (all, num, letter) { return keep(num + block(letter.toLowerCase(), all)); })
                .replace(/(?<!\])\(([0-9Xx])\)/g, function (all, value) { return keep(twilight(value.toLowerCase(), all)); });
        }
        return [parts.join(''), tokens];
    };

    symbols.restore = function restore(html, tokens)
    {
        return html.replace(/rbsym\d+x/g, function (token) { return tokens[token] || token; });
    };

    /**
     * Every symbol for the editor's menu, as {group, text (what goes in), html (the icon),
     * name}: the cultures, the blocks, then twilight 0 to 9 and X
     */
    symbols.list = function list()
    {
        var rows = _.map(_.sortBy(_.values(CULTURES), function (c) { return c[1]; }), function (c) {
            return {group: 'cultures', text: '[' + c[1] + ']', html: icon('rb-sym-' + c[0], c[1], '[' + c[1] + ']'), name: c[1]};
        });
        _.each(['t', 'k'], function (letter) {
            rows.push({group: 'blocks', text: BLOCKS[letter][2], html: block(letter, BLOCKS[letter][2]), name: BLOCKS[letter][1]});
        });
        _.each('0123456789x'.split(''), function (value) {
            var text = '(' + value.toUpperCase() + ')';
            rows.push({group: 'twilight', text: text, html: twilight(value, text), name: value.toUpperCase() + ' Twilight'});
        });
        return rows;
    };

})(app.symbols = {});
