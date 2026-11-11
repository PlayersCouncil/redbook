/* global app, _ */

/*
 * Drag to reorder (Ketura, 2026-11-11), shared: the starting fellowships (their rows, and the
 * companions in each) and the library editor's decks. Pointer events, so it works the same
 * with a mouse, a pen or a finger:
 * - by a handle (options.handle): a press on it starts at once (the handle is the place for
 *   it, so it never scrolls the page: touch-action none in its CSS)
 * - by the item itself: a mouse moves it once dragged a few pixels (a plain click still
 *   clicks); a finger holds still for a moment first (long-press), so a swipe still scrolls
 * The item moves through the list as it's dragged, and done(item) runs once it's let go
 * somewhere new. Escape puts it back.
 *
 * options: items (selector, within the container), handle (selector within an item; none:
 * the item itself), axis ('y' for a column, 'x' for a row that may wrap), ignore (selector:
 * presses there never start a drag, as buttons in an item), done(item)
 */
(function app_sortable(sortable, $)
{
    var SLOP = 5, HOLD_MS = 350;

    sortable.attach = function attach(container, options)
    {
        container = $(container);
        var axis = options.axis || 'y',
            drag = null;

        function siblings()
        {
            return container.children(options.items).not(drag.item);
        }

        /** Before or after whichever sibling the pointer is over */
        function place(x, y)
        {
            var target = null, before = true;
            siblings().each(function () {
                var r = this.getBoundingClientRect();
                if(axis === 'y') {
                    if(y >= r.top && y <= r.bottom) {
                        target = this;
                        before = y < r.top + r.height / 2;
                        return false;
                    }
                } else if(y >= r.top && y <= r.bottom && x >= r.left && x <= r.right) {
                    target = this;
                    before = x < r.left + r.width / 2;
                    return false;
                }
            });
            if(target) {
                before ? $(target).before(drag.item) : $(target).after(drag.item);
            }
        }

        function start()
        {
            drag.on = true;
            drag.item.addClass('is-dragging');
            container.addClass('is-sorting');
            $('body').addClass('rb-sorting');
            // no card hovers while dragging: they'd cover where it's going (Ketura, 2026-11-11)
            app.tip && app.tip.dismiss && app.tip.dismiss();
        }

        function finish(cancel)
        {
            var d = drag;
            drag = null;
            clearTimeout(d.timer);
            $(document).off('.rbsort');
            if(!d.on) {
                return;
            }
            d.item.removeClass('is-dragging');
            container.removeClass('is-sorting');
            $('body').removeClass('rb-sorting');
            if(cancel) {
                d.next.length ? d.next.before(d.item) : d.parent.append(d.item);
                return;
            }
            // a click right after a drag is the drag's, not the item's
            d.item.one('click', function (event) { event.preventDefault(); event.stopImmediatePropagation(); });
            setTimeout(function () { d.item.off('click'); }, 0);
            if(d.item.index() !== d.index) {
                options.done && options.done(d.item);
            }
        }

        container.on('pointerdown', options.handle ? options.items + ' ' + options.handle : options.items, function (event) {
            var e = event.originalEvent;
            if(drag || (e.pointerType === 'mouse' && e.button !== 0) || (options.ignore && $(event.target).closest(options.ignore).length)) {
                return;
            }
            var item = $(this).closest(options.items);
            drag = {item: item, x: e.clientX, y: e.clientY, on: false, index: item.index(), next: item.next(), parent: item.parent(), timer: null};
            if(options.handle) {
                event.preventDefault();
                start();
            } else if(e.pointerType !== 'mouse') {
                drag.timer = setTimeout(function () { drag && start(); }, HOLD_MS);
            }
            $(document).on('pointermove.rbsort', function (ev) {
                var p = ev.originalEvent;
                if(!drag) {
                    return;
                }
                if(!drag.on) {
                    var moved = Math.abs(p.clientX - drag.x) > SLOP || Math.abs(p.clientY - drag.y) > SLOP;
                    if(moved && p.pointerType === 'mouse') {
                        start();
                    } else if(moved) {
                        // a finger that moves before the hold is scrolling
                        return finish(true);
                    } else {
                        return;
                    }
                }
                ev.preventDefault();
                place(p.clientX, p.clientY);
            }).on('pointerup.rbsort pointercancel.rbsort', function (ev) {
                drag && finish(ev.type === 'pointercancel');
            }).on('keydown.rbsort', function (ev) {
                if(ev.key === 'Escape' && drag) {
                    finish(true);
                }
            });
        });
        // the browser's own dragging of links and pictures, and a long-press's menu, stay out of it
        container.on('dragstart', options.items, function (event) { event.preventDefault(); });
        container.on('contextmenu', options.items, function (event) {
            if(drag) {
                event.preventDefault();
            }
        });
        // while held, a finger doesn't scroll the page
        container[0].addEventListener('touchmove', function (event) {
            if(drag && drag.on) {
                event.preventDefault();
            }
        }, {passive: false});
    };

})(app.sortable = {}, jQuery);
