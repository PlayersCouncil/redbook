(function app_card_modal(card_modal, $)
{

    var modal = null;

    /**
     * @memberOf card_modal
     */
    card_modal.display_modal = function display_modal(event, element)
    {
        event.preventDefault();
        $(element).qtip('destroy', true);
        fill_modal($(element).data('code'));
    };

    /**
     * @memberOf card_modal
     */
    card_modal.typeahead = function typeahead(event, card)
    {
        fill_modal(card.code);
        $('#cardModal').modal('show');
    };

    /** How many copies the deckbuilder offers: 1 for the Ring, sites and maps, else the format's limit */
    card_modal.max_quantity = function max_quantity(card)
    {
        if(app.deck.role_for_card(card) !== 'draw') {
            return 1;
        }
        var format = app.deck.get_format();
        return format ? format.maximum_same_name : 4;
    };

    function fill_modal(code)
    {
        var card = app.data.cards.findById(code),
                modal = $('#cardModal');
        if(!card)
            return;
        var info = '<div class="card-faction">' + app.format.faction(card) + '</div>'
          + '<div class="card-info">' + app.format.info(card) + '</div>'
          + '<div class="card-traits">' + app.format.traits(card) + '</div>'
          + '<div class="card-text">' + app.format.text(card) + '</div>'
          + '<div class="card-pack">' + app.format.pack(card) + '</div>';
        if (card.kind === 'errata') {
            info += '<div class="card-errata-short">' + Translator.trans('card.kind.errata_short') + '</div>';
        }

        modal.data('code', code);
        modal.find('.card-modal-link').attr('href', card.url);
        modal.find('h3.modal-title').html(app.format.name(card));
        modal.find('.modal-image').html(card.image_url ? '<img class="img-responsive" src="' + card.image_url + '">' : '');
        modal.find('.modal-info').html(info);

        var qtyelt = modal.find('.modal-qty');
        if(qtyelt.length && app.deck.get_id && app.deck.get_id() && $('#deck-save-id').length) {
            var max = card_modal.max_quantity(card),
                indeck = app.deck.get_quantity(card.code),
                qtyHtml = '';
            for(var i = 0; i <= max; i++) {
                qtyHtml += '<label class="btn btn-default' + (i === indeck ? ' active' : '') + '"><input type="radio" name="qty" value="' + i + '">' + i + '</label>';
            }
            qtyelt.html(qtyHtml);
        } else if(qtyelt.length) {
            qtyelt.closest('.row').remove();
        }
    }

    $(function ()
    {

        $('body').on({click: function (event)
            {
                var element = $(this);
                if(event.shiftKey || event.altKey || event.ctrlKey || event.metaKey) {
                    event.stopPropagation();
                    return;
                }
                card_modal.display_modal(event, element);
            }}, '.card');

    })

})(app.card_modal = {}, jQuery);
