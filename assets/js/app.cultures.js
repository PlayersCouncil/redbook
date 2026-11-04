/* global app */
/**
 * Culture colours (Ketura, 2026-11-03). The palette lives in style.css as
 * --rb-culture-<code> (main) and --rb-culture-<code>-light; this reads it, so the
 * colours are kept in one place.
 */
(function app_cultures(cultures)
{
    var cache = {};

    cultures.color = function color(code, light)
    {
        var key = (code || '').replace(/_/g, '-') + (light ? '-light' : '');
        if(!(key in cache)) {
            cache[key] = key ? getComputedStyle(document.documentElement).getPropertyValue('--rb-culture-' + key).trim() : '';
        }
        return cache[key] || (light ? '#D9D4CA' : '#8A8578');
    };

})(app.cultures = {});
