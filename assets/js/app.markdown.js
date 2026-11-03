/* global app */
/**
 * Markdown previews. Everything goes through render(), which also turns [[card]] links
 * into card links (app.cardlinks.js).
 */
(function app_markdown(markdown, $)
{

    markdown.render = function render(text)
    {
        return app.cardlinks ? app.cardlinks.markdown(text) : marked(text || '');
    };

    markdown.setup = function setup(textarea, preview)
    {
        $(textarea).on('change keyup', function ()
        {
            $(preview).html(markdown.render($(textarea).val()));
        });
        $(textarea).trigger('change');
        // card names resolve once the card data is in
        $(document).on('data.app', function () { $(textarea).trigger('change'); });
    };

    markdown.refresh = function refresh(textarea, preview)
    {
        $(preview).html(markdown.render($(textarea).val()));
    };

    markdown.update = function update(text_markdown, preview)
    {
        $(preview).html(markdown.render(text_markdown));
    };

})(app.markdown = {}, jQuery);
