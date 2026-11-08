/* global app, _ */

/**
 * A bar between two panes, dragged to resize them (Ketura, 2026-11-07): the publish form and
 * My Decks so far, the other paned pages as they come. Each page remembers its width;
 * double-clicking the bar puts the default back.
 *
 * options:
 *   key        where the width is kept (localStorage)
 *   min, max   the bounds; max a function, as it depends on the window
 *   current()  the pane's width now
 *   direction  1 when the pane is left of the bar (dragging right widens it), -1 when right
 *   set(width) applies it; null for the default
 */
(function ui_splitter(ui, $)
{
    ui.splitter = function splitter(bar, options)
    {
        bar = $(bar);
        if(!bar.length) {
            return;
        }
        var dragging = false, x0 = 0, w0 = 0;
        function apply(width)
        {
            var w = Math.round(Math.max(options.min, Math.min(options.max(), width)));
            options.set(w);
            return w;
        }
        function restore()
        {
            try {
                var saved = parseInt(localStorage.getItem(options.key), 10);
                if(saved) {
                    apply(saved);
                }
            } catch(e) {}
        }
        restore();
        bar.on('pointerdown', function (event)
        {
            var e = event.originalEvent;
            if(e.button !== 0) {
                return;
            }
            event.preventDefault();
            this.setPointerCapture(e.pointerId);
            dragging = true;
            x0 = e.clientX;
            w0 = options.current();
            $('body').addClass('rb-resizing');
        });
        bar.on('pointermove', function (event)
        {
            if(dragging) {
                var w = apply(w0 + (event.originalEvent.clientX - x0) * options.direction);
                try { localStorage.setItem(options.key, w); } catch(e) {}
            }
        });
        bar.on('pointerup pointercancel lostpointercapture', function ()
        {
            if(dragging) {
                dragging = false;
                $('body').removeClass('rb-resizing');
                $(window).trigger('resize');
            }
        });
        bar.on('dblclick', function ()
        {
            try { localStorage.removeItem(options.key); } catch(e) {}
            options.set(null);
            $(window).trigger('resize');
        });
        // a narrower window keeps the width within it
        $(window).on('resize', _.debounce(function () { if(!dragging) { restore(); } }, 150));
    };

})(app.ui, jQuery);
