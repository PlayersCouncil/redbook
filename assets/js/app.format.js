(function app_format(format, $)
{

    /**
     * Subtypes (race / class / keywords line)
     * @memberOf format
     */
    format.traits = function traits(card)
    {
        return card.subtypes || '';
    };

    /**
     * @memberOf format
     */
    format.name = function name(card)
    {
        return (card.is_unique ? '<span class="card-unique">&bull;</span>' : "") + (card.label || card.name);
    };

    /**
     * Culture and side
     * @memberOf format
     */
    format.faction = function faction(card)
    {
        var text = '';
        if(card.culture_code) {
            text += '<span class="culture-symbol culture-' + card.culture_code + '"></span> ' + card.culture_name + '. ';
        }
        if(card.side) {
            text += Translator.trans('side.' + card.side) + '. ';
        }
        return text;
    };

    /**
     * @memberOf format
     */
    format.pack = function pack(card)
    {
        return card.pack_name + ' #' + card.position + (card.coll_info ? ' (' + card.coll_info + ')' : '') + '. ';
    };

    function stat(key, value)
    {
        return value === null || value === undefined || value === '' ? '' : Translator.trans('card.info.' + key) + ': ' + value + '. ';
    }

    /**
     * Type and the stats that apply to it; mirrors templates/Search/card-props.html.twig
     * @memberOf format
     */
    format.info = function info(card)
    {
        var text = '<span class="card-type">' + card.type_name + '. </span>';
        if(card.type_code !== 'site') {
            text += stat('twilight', card.twilight);
        }
        text += stat('strength', card.strength) + stat('strength', card.strength_mod);
        text += stat('vitality', card.vitality) + stat('vitality', card.vitality_mod);
        text += stat('resistance', card.resistance) + stat('resistance', card.resistance_mod);
        if(card.site_number !== null && card.site_number !== undefined) {
            text += stat(card.type_code === 'ally' ? 'home_site' : 'site', card.site_number + (card.site_block ? ' ' + card.site_block : ''));
        }
        text += stat('site', card.site_number_mod);
        text += stat('shadow_number', card.shadow_number);
        text += stat('signet', card.signet);
        return text;
    };

    /**
     * @memberOf format
     */
    format.text = function text(card)
    {
        var text = card.text || '';
        text = text.replace(/\[([A-Za-z][A-Za-z -]{1,20})\]/g, function (match, symbol) {
            var code = symbol.toLowerCase().replace(/[^a-z0-9]+/g, '_').replace(/^_|_$/g, '');
            if(code === 'wraith') {
                code = 'ringwraith'; // the culture's code; card text writes [WRAITH]
            }
            return '<span class="culture-symbol culture-' + code + '" title="' + symbol + '">' + symbol + '</span>';
        });
        text = text.split("\n").join('</p><p>');
        return '<p>' + text + '</p>';
    };

})(app.format = {}, jQuery);
