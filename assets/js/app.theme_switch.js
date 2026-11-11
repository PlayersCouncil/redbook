/* Red Book theme switch (Ketura, 2026-11-09; the design's theme/theme-switch.js; one button
   cycling Auto, Dark, Light since 2026-11-11): light | auto | dark, stored as localStorage "pc-theme" and applied as html.pc-dark, the same key and class
   as wiki.lotrtcgpc.net. The head snippet is in base.html.twig, the buttons in its top bar.

   1. Put the HEAD SNIPPET inline in <head>, before any stylesheet, so the page never flashes
      the wrong theme:

   <script>(function(){var h=document.documentElement,t="auto";try{t=localStorage.getItem("pc-theme")||t;}catch(e){}
   if(t==="dark"||(t==="auto"&&window.matchMedia&&matchMedia("(prefers-color-scheme: dark)").matches))h.classList.add("pc-dark");
   h.classList.add("theme-"+t);})();</script>

   2. Markup, left of the language switcher in the top bar (base.html.twig's theme_switch):

   <div class="rb-theme-switch">
     <button type="button" class="rb-theme-cycle" data-theme-cycle aria-label="Colour theme"
             data-label-auto="Auto" data-label-dark="Dark" data-label-light="Light"
             data-next="Theme: %current%. Click for %next%."><span class="fas fa-adjust"></span></button>
   </div>

   3. Load this file anywhere after the markup. */
(function () {
    var html = document.documentElement;
    var mq = window.matchMedia ? window.matchMedia('(prefers-color-scheme: dark)') : null;
    function read() { try { return localStorage.getItem('pc-theme') || 'auto'; } catch (e) { return 'auto'; } }
    function write(v) { try { localStorage.setItem('pc-theme', v); } catch (e) { /* private mode: session-only */ } }
    var mode = read();

    function apply() {
        var dark = mode === 'dark' || (mode === 'auto' && !!(mq && mq.matches)),
            changed = dark !== html.classList.contains('pc-dark');
        html.classList.toggle('pc-dark', dark);
        html.classList.remove('theme-light', 'theme-dark', 'theme-auto');
        html.classList.add('theme-' + mode);
        // the one button shows the theme in use, and says what a click gives (Ketura, 2026-11-11)
        var icons = {auto: 'fa-adjust', dark: 'fa-moon', light: 'fa-sun'},
            cycles = document.querySelectorAll('[data-theme-cycle]');
        for (var i = 0; i < cycles.length; i++) {
            var b = cycles[i], icon = b.querySelector('.fas'),
                label = b.getAttribute('data-label-' + mode) || mode,
                next = b.getAttribute('data-label-' + NEXT[mode]) || NEXT[mode];
            if (icon) icon.className = 'fas ' + icons[mode];
            b.setAttribute('title', (b.getAttribute('data-next') || '%current% / %next%').replace('%current%', label).replace('%next%', next));
            b.setAttribute('data-mode', mode);
        }
        // for what draws its own colours, like the charts (Red Book, 2026-11-09)
        if (changed) document.dispatchEvent(new CustomEvent('rb-theme', {detail: {dark: dark}}));
    }

    // each click the next: Auto, Dark, Light, Auto (Ketura, 2026-11-11)
    var NEXT = {auto: 'dark', dark: 'light', light: 'auto'};
    document.addEventListener('click', function (e) {
        var b = e.target.closest && e.target.closest('[data-theme-cycle]');
        if (!b) return;
        mode = NEXT[mode] || 'auto';
        write(mode);
        apply();
    });
    if (mq) {
        var onChange = function () { if (mode === 'auto') apply(); };
        if (mq.addEventListener) mq.addEventListener('change', onChange); else if (mq.addListener) mq.addListener(onChange);
    }
    apply();
})();
