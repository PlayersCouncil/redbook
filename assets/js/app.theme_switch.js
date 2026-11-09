/* Red Book theme switch (Ketura, 2026-11-09; the design's theme/theme-switch.js): light | auto |
   dark, stored as localStorage "pc-theme" and applied as html.pc-dark, the same key and class
   as wiki.lotrtcgpc.net. The head snippet is in base.html.twig, the buttons in its top bar.

   1. Put the HEAD SNIPPET inline in <head>, before any stylesheet, so the page never flashes
      the wrong theme:

   <script>(function(){var h=document.documentElement,t="auto";try{t=localStorage.getItem("pc-theme")||t;}catch(e){}
   if(t==="dark"||(t==="auto"&&window.matchMedia&&matchMedia("(prefers-color-scheme: dark)").matches))h.classList.add("pc-dark");
   h.classList.add("theme-"+t);})();</script>

   2. Markup, left of the language switcher in the top bar:

   <div class="rb-theme-switch" role="group" aria-label="Colour theme">
     <button type="button" data-theme-set="light" title="Light"><span class="fas fa-sun"></span></button>
     <button type="button" data-theme-set="auto"  title="Match system"><span class="fas fa-adjust"></span></button>
     <button type="button" data-theme-set="dark"  title="Dark"><span class="fas fa-moon"></span></button>
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
        var buttons = document.querySelectorAll('[data-theme-set]');
        for (var i = 0; i < buttons.length; i++) {
            buttons[i].setAttribute('aria-pressed', buttons[i].getAttribute('data-theme-set') === mode ? 'true' : 'false');
        }
        // for what draws its own colours, like the charts (Red Book, 2026-11-09)
        if (changed) document.dispatchEvent(new CustomEvent('rb-theme', {detail: {dark: dark}}));
    }

    document.addEventListener('click', function (e) {
        var b = e.target.closest && e.target.closest('[data-theme-set]');
        if (!b) return;
        mode = b.getAttribute('data-theme-set');
        write(mode);
        apply();
    });
    if (mq) {
        var onChange = function () { if (mode === 'auto') apply(); };
        if (mq.addEventListener) mq.addEventListener('change', onChange); else if (mq.addListener) mq.addListener(onChange);
    }
    apply();
})();
