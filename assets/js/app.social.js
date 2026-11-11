/* global app, Translator, Routing, _, moment */

/*
 * Likes and favourites (Ketura, 2026-11-09), shared by a decklist's reader and a library's
 * view: the toggle buttons. The comments are app.comments' (2026-11-11).
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

})(app.social = {}, jQuery);
