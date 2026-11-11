/* global app, jQuery */

/*
 * Cheers, fire and crown (Ketura, 2026-11-11), shared by a decklist's reader, a library's view
 * and the comments: a .rb-reactions group (Social/reactions.html.twig) of three buttons, one
 * per step of the viewer's level (1 Cheers, 2 on fire, 3 crowned).
 * - A step is usable once the one under it is taken: the fire after a Cheers, the crown after
 *   the fire. Until then it waits tucked behind the Cheers, unless someone has used it.
 * - Clicking a step taken already takes it back, and the ones above it; clicking the next one
 *   up takes it.
 * - Everything starts with its author's own Cheers, which they may take back; their fire and
 *   crown show only as counts (data-own).
 * The group keeps its state in data-level, data-own and data-logged.
 */
(function app_reactions(reactions, $)
{
    /**
     * Draws the group: options {level, own, logged, counts: [cheers, fires, crowns]}, each kept
     * on the group when given; what's left out stays as it was
     */
    reactions.render = function render(group, options)
    {
        group = $(group);
        options = options || {};
        if(options.level !== undefined) {
            group.attr('data-level', options.level);
        }
        if(options.own !== undefined) {
            group.attr('data-own', options.own ? '' : null);
        }
        if(options.logged !== undefined) {
            group.attr('data-logged', options.logged ? '' : null);
        }
        var level = parseInt(group.attr('data-level'), 10) || 0,
            own = group.is('[data-own]'),
            logged = group.is('[data-logged]');
        group.find('[data-tier]').each(function () {
            var button = $(this),
                tier = parseInt(button.data('tier'), 10),
                count = options.counts ? options.counts[tier - 1] : parseInt(button.find('.num').text(), 10) || 0,
                on = level >= tier,
                usable = logged && (tier === 1 || (!own && level >= tier - 1));
            if(options.counts) {
                button.find('.num').text(count);
            }
            button.toggleClass('is-on', on).attr('aria-pressed', on ? 'true' : 'false')
                .toggleClass('is-tucked', tier > 1 && !usable && count === 0)
                .attr('title', button.data(usable ? (on ? 'title-off' : 'title-on') : (own && tier > 1 ? 'title-own' : 'title-locked')));
            if(button.is('button')) {
                button.prop('disabled', !usable);
            }
        });
    };

    /** The viewer's level on a group */
    reactions.level = function level(group)
    {
        return parseInt($(group).attr('data-level'), 10) || 0;
    };

    /**
     * Sends the clicks on the groups within root: send(group, level) posts the new level and
     * answers {level, cheers, fires, crowns}. A click while one is out is ignored.
     */
    reactions.bind = function bind(root, send)
    {
        $(root).on('click', '.rb-reactions button[data-tier]', function (event) {
            event.preventDefault();
            var button = $(this),
                group = button.closest('.rb-reactions');
            if(button.prop('disabled') || group.hasClass('processing')) {
                return;
            }
            var tier = parseInt(button.data('tier'), 10),
                to = reactions.level(group) >= tier ? tier - 1 : tier;
            group.addClass('processing').data('touched', true);
            send(group, to).done(function (data) {
                reactions.render(group, {level: data.level, counts: [data.cheers, data.fires, data.crowns]});
            }).always(function () {
                group.removeClass('processing');
            });
        });
    };

})(app.reactions = {}, jQuery);
