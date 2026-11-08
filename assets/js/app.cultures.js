/* global app */
/**
 * Culture colours (Ketura, 2026-11-03). The palette lives in style.css as
 * --rb-culture-<code> (main) and --rb-culture-<code>-light, and the card templates' own
 * colours as --rb-culture-<code>-base1 and the like; this reads it, so the colours are kept
 * in one place. The second argument: true for the light one, or the name of a template
 * colour ('base1').
 */
(function app_cultures(cultures)
{
    var cache = {};

    cultures.color = function color(code, light)
    {
        var key = (code || '').replace(/_/g, '-') + (light === true ? '-light' : (light ? '-' + light : ''));
        if(!(key in cache)) {
            cache[key] = key ? getComputedStyle(document.documentElement).getPropertyValue('--rb-culture-' + key).trim() : '';
        }
        return cache[key] || (light ? '#D9D4CA' : '#8A8578');
    };

})(app.cultures = {});
