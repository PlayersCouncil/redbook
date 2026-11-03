/* global app, _ */
/**
 * Keyword, "Mentions" and Quick Search matching for the deckbuilder's card filters.
 *
 * INTERIM: these are the PC wiki's Advanced Search patterns (Template:CardSearch),
 * ported to JavaScript regular expressions and run in the browser. Ketura's plan
 * (2026-11-02) is a generated search cache in lotrtcg-card-data that tools load
 * instead; when it exists this file is replaced by a lookup into it, and nothing
 * else in the deckbuilder needs to change (the filters only call the functions below).
 *
 * MySQL's RLIKE is case-insensitive, so every pattern here uses the "i" flag.
 */
(function app_card_terms(terms)
{
    terms.KEYWORDS = ['Ambush', 'Archer', 'Battleground', 'Besieger', 'Burglar', 'Corsair', 'Cunning', 'Damage +1', 'Damage +2',
        'Defender +1', 'Dwelling', 'Easterling', 'Enduring', 'Engine', 'Fierce', 'Forest', 'Fortification', 'Hunter', 'Knight',
        'Lurker', 'Machine', 'Marsh', 'Mountain', 'Mounted', 'Muster', 'Pipeweed', 'Plains', 'Ranger', 'Ring-bearer', 'Ring-bound',
        'River', 'Roaming', 'Sanctuary', 'Search', 'Southron', 'Spell', 'Stealth', 'Tale', 'Tentacle', 'Toil', 'Tracker',
        'Twilight', 'Unbound', 'Underground', 'Unhasty', 'Valiant', 'Villager', 'Warg-rider', 'Weather', 'Wise'];

    /** Keywords as other cards mention them (no bonus values) */
    terms.USES_KEYWORDS = _.uniq(_.map(terms.KEYWORDS, function (k) { return k.replace(/ \+\d$/, ''); }));

    function esc(s)
    {
        return s.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    }

    function text(card)
    {
        return card.text || '';
    }

    var ANY = '(an?|X|\\d|all|every|each)',
        SIDE = '(Free Peoples |Shadow )?',
        CULT = '(\\[\\w+\\] )?',
        OWNER = "(your|its owners|(your|an) opponent's|his or her|their)";

    function re(source)
    {
        return new RegExp(source, 'i');
    }

    /* ---- has / uses ---- */

    /**
     * The card has the keyword: "Keyword." at the start of the text or after another
     * sentence, plus the wiki's special cases (Ambush (N), Hunter N, Ring-bound, Unbound).
     */
    terms.hasKeyword = function hasKeyword(card, keyword)
    {
        var t = text(card);
        if(keyword === 'Ring-bound' && ((card.name === 'Frodo' || card.name === 'Sam') || card.can_bear_ring)) {
            return true;
        }
        if(keyword === 'Unbound') {
            return card.type_code === 'companion' && card.name !== 'Frodo' && card.name !== 'Sam'
                && !re('(?<!\\w )Ring-bound\\.').test(t);
        }
        if(keyword === 'Ambush') {
            return re('(?<!\\w )Ambush \\(\\d+\\)\\.').test(t);
        }
        if(keyword === 'Hunter') {
            return re('(?<!\\w )Hunter \\d+\\.').test(t);
        }
        return re('(?<!\\w )' + esc(keyword) + '\\.').test(t);
    };

    /** Another card's keyword, mentioned mid-sentence */
    terms.usesKeyword = function usesKeyword(card, keyword)
    {
        var t = text(card);
        if(keyword === 'Archer') {
            return re('(?<=\\w )archer\\b').test(t);
        }
        return re('(?<=\\w )' + esc(keyword)).test(t);
    };

    terms.usesCulture = function usesCulture(card, cultureName)
    {
        return re('\\[' + esc(cultureName) + ']').test(text(card));
    };

    terms.usesType = function usesType(card, typeName)
    {
        return re('(?<!this )' + esc(typeName)).test(text(card));
    };

    terms.usesRace = function usesRace(card, race)
    {
        var t = text(card);
        return re(esc(race)).test(t)
            || (race === 'Dwarf' && /Dwarves/i.test(t))
            || (race === 'Elf' && /Elves/i.test(t));
    };

    terms.usesItemClass = function usesItemClass(card, itemClass)
    {
        return re(esc(itemClass)).test(text(card));
    };

    /** An event of that phase, or any card with a "Phase:" action */
    terms.hasPhase = function hasPhase(card, phase)
    {
        if(card.type_code === 'event' && _.contains(terms.subtypes(card), phase)) {
            return true;
        }
        return re(esc(phase) + ':').test(text(card));
    };

    terms.subtypes = function subtypes(card)
    {
        return card.subtypes ? _.map(card.subtypes.split(','), function (s) { return s.trim(); }) : [];
    };

    /* ---- Quick Search: the wiki's "advanced options" ---- */

    function by(pattern, extra)
    {
        var r = re(pattern);
        return function (card) {
            return r.test(text(card)) || !!(extra && extra(card));
        };
    }

    function collInfo(list)
    {
        return function (card) { return _.contains(list, card.coll_info); };
    }

    var discard = 'discard (up to )?' + ANY + ' ';
    terms.QUICK = [
        {group: 'Burdens', items: [
            ['auto-corruption', 'Auto-corruption', by('corrupt')],
            ['add-burdens', 'Add burdens', by('add (up to )?(a|X|\\d) burden')],
            ['prevent-burden-removal', 'Prevent burden removal', by('(burden[^\\.]*(can|may) ?not (be )?remove|about to remove[^\\.]*burdens?,)')],
            ['remove-burdens', 'Remove burdens', by('remove (up to )?(a|X|\\d) burden')],
            ['prevent-burden-adding', 'Prevent burden adding', by('(burden[^\\.]*(can|may) ?not (be )?add|about to add[^\\.]*burdens?,)')],
            ['spot-burdens', 'Spot burdens', by('(spot ' + ANY + ' burden|burden you (can )? spot|\\(or \\d burdens\\)|for each burden)')]
        ]},
        {group: 'Card manipulation', items: [
            ['look-top', 'Look at top of deck', by('(look at the top (\\w+ )?cards? of ' + OWNER + ' draw deck)')],
            ['reveal-top', 'Reveal top of deck', by('(reveal the top (\\w+ )?cards? of ' + OWNER + ' draw deck)')],
            ['place-top', 'Place on top of deck', by("(on top of ((or beneath )?your|its owners|(your|an) opponent's|his or her|their) draw deck)")],
            ['place-bottom', 'Place on bottom of deck', by('((on the bottom of|beneath) ' + OWNER + ' draw deck)')],
            ['shuffle-into', 'Shuffle into deck', by('shuffle (up to )?' + ANY + '[^\\.]* into ' + OWNER + ' draw deck')],
            ['discard-own-deck', 'Discard from your deck', by('discards? (up to )?(the|an?|X|\\d|all|every|each)[^\\.]* (from|of) (the top of )?your draw deck')],
            ['discard-opp-deck', "Discard from opponent's deck", by("discards? (up to )?(the|an?|X|\\d|all|every|each)[^\\.]* (from|of) (the top of )?(its owners|(your|an) opponent's|his|his or her|their) draw deck")],
            ['discard-opp-hand', "Discard from opponent's hand", by("(discards? (up to )?" + ANY + "[^\\.]* from (its owners|(your|an) opponent's|the shadow player's|his|his or her|their) hand|((choose|make) )?(an opponent|the shadow player|the free peoples player|that player)( to)? discard[^\\.]*from hand)|owner discards")],
            ['reveal-opp-hand', "Reveal opponent's hand", by("(reveal (up to )?" + ANY + "[^\\.]* from (its owners|(your|an) opponent's|his|his or her|their) hand|reveal (your|an) opponent's hand)")]
        ]},
        {group: 'Culture tokens', items: [
            ['add-tokens', 'Add tokens', by('(add|place|reinforce) (up to )?' + ANY + ' ' + SIDE + CULT + '(culture )?token')],
            ['reinforce-tokens', 'Reinforce tokens', by('(reinforce) (up to )?' + ANY + ' ' + SIDE + CULT + '(culture )?token')],
            ['remove-tokens', 'Remove tokens', by('(remove) (up to )?' + ANY + ' ' + SIDE + CULT + '(culture )?token')],
            ['remove-tokens-others', 'Remove tokens from others', by('(remove) (up to )?' + ANY + ' ' + SIDE + CULT + '(culture )?tokens? (?!from here)(?!from this card)(?!here)')],
            ['spot-tokens', 'Spot tokens', function (card) {
                return re('(spot) (up to )?' + ANY + ' ' + SIDE + CULT + '(culture )?tokens? (?!from here)(?!from this card)(?!here)').test(text(card))
                    || re('for each ' + SIDE + CULT + '(culture )?tokens? (?!from here)(?!from this card)(?!here)').test(text(card));
            }]
        ]},
        {group: 'Cycling', items: [
            ['draw-cards', 'Draw cards', function (card) {
                var t = text(card);
                return (re('draw (up to )?' + ANY + ' card').test(t) && !re('opponent shuffle[^\\.]*draw deck and draw').test(t)
                    && !re('(Free Peoples |Shadow )?player (must |may )?draw').test(t)) || /muster/i.test(t);
            }],
            ['recover-into-hand', 'Recover cards into hand', by('take [^\\.]*into hand')],
            ['discard-own-hand', 'Discard from your hand', function (card) {
                var t = text(card);
                return (re('discard [^\\.]*from hand').test(t) && !re('(opponent|owner|(Free Peoples |Shadow )?player)[^\\.]* discard[^\\.]*from hand').test(t))
                    || /muster/i.test(t);
            }]
        ]},
        {group: 'Initiative', items: [
            ['initiative-bonus', 'Initiative bonus', by('((while|if) (you|shadow) (have|has) initiative)')],
            ['shadow-initiative', 'Shadow initiative manipulation', by('((?<!if )(?<!if the )shadow (player )?has initiative|to have initiative)')],
            ['freeps-initiative-loss', 'Free Peoples initiative loss bonus', function (card) {
                return card.side === 'free_peoples' && re('(you lose initiative|shadow has initiative)').test(text(card));
            }]
        ]},
        {group: 'Move limit', items: [
            ['increase-move-limit', 'Increase move limit', by('move limit (for this turn )?(is )?\\+')],
            ['decrease-move-limit', 'Decrease move limit', by('move limit (for this turn )?(is )?-')],
            ['force-move', 'Force move', by('move again')],
            ['force-stop', 'Force stop', by('prevent[^\\.]*moving again')]
        ]},
        {group: 'Recursion', items: [
            ['take-from-discard', 'Take cards into hand from discard', by('take [^\\.]*into hand from[^\\.]*discard')],
            ['take-from-deck', 'Take cards into hand from deck', by('take [^\\.]*into hand from[^\\.]*draw deck')],
            ['play-from-discard', 'Play cards from discard', by('play (up to )?' + ANY + ' ' + CULT + SIDE + '\\w* from your discard pile')],
            ['play-from-deck', 'Play cards from deck', by('play (up to )?' + ANY + ' ' + CULT + SIDE + '\\w* from your draw deck')]
        ]},
        {group: 'Signets', items: [
            ['uses-aragorn', 'Uses Aragorn signet', by('Aragorn signet')],
            ['uses-frodo', 'Uses Frodo signet', by('Frodo signet')],
            ['uses-gandalf', 'Uses Gandalf signet', by('Gandalf signet')],
            ['uses-theoden', 'Uses Théoden signet', by('Th[eé]oden signet')]
        ]},
        {group: 'Site control', items: [
            ['take-control', 'Take control of a site', function (card) { return card.side !== 'free_peoples' && re('(take control|to control)').test(text(card)); }],
            ['liberate', 'Liberate a site', by('liberate')],
            ['prevent-control', 'Prevent site control', by('about to (take )?control')],
            ['control-bonus', 'Your site control bonus', by('(you control)')],
            ['no-control-bonus', 'No site control bonus', by('(no opponent control)')]
        ]},
        {group: 'Site manipulation', items: [
            ['play-site', 'Play a site', by("((play|replace) the fellowship's|replace it with your site|replace a site)")],
            ['prevent-replacement', 'Prevent site replacement', by('not (be )?replace')]
        ]},
        {group: 'Skirmishing', items: [
            ['ally-participation', 'Ally participation', by('(participates? in (archery fire and )?skirmish)')],
            ['cancel-skirmish', 'Cancel a skirmish', by('(Cancel [^\\.]*skirmish)')],
            ['dodge-skirmish', 'Dodge a skirmish', by('(remove [^\\.]*from a skirmish)', collInfo(['4C310', '4C314', '4C322', '17U103', '7R129']))],
            ['replace-skirmish', 'Replace a skirmish', by('replace[^\\.]*skirmish')],
            ['overwhelm-protection', 'Overwhelm protection', by('(overwhelmed unless)')]
        ]},
        {group: 'Stats', items: [
            ['add-strength', 'Add strength', by('strength (of [^\\d\\.\\+-]* )?\\+')],
            ['remove-strength', 'Remove strength', by('strength (of [^\\d\\.\\+-]* )?-')],
            ['add-resistance', 'Add resistance', by('resistance (of [^\\d\\.\\+-]* )?\\+')],
            ['remove-resistance', 'Remove resistance', by('resistance (of [^\\d\\.\\+-]* )?-')],
            ['add-site-number', 'Add site number', by('site number (of [^\\d\\.\\+-]* )?\\+')],
            ['remove-site-number', 'Remove site number', by('site number (of [^\\d\\.\\+-]* )?-')]
        ]},
        {group: 'Targeted removal', items: [
            ['ally-removal', 'Ally removal', by(discard + CULT + '(\\w+ or )?ally', collInfo(['8R103']))],
            ['companion-removal', 'Companion removal', by(discard + CULT + '(\\w+ or )?companion(?! from hand)')],
            ['minion-removal', 'Minion removal', by(discard + CULT + '(\\w+ or )?minion')],
            ['follower-removal', 'Follower removal', by(discard + CULT + '(\\w+ or )?follower', collInfo(['4R32', '8R103']))],
            ['condition-removal', 'Condition removal', by(discard + SIDE + CULT + '(\\w+ or ' + SIDE + ')?condition', collInfo(['4R32', '8R103']))],
            ['artifact-removal', 'Artifact removal', by(discard + SIDE + CULT + '(\\w+ or ' + SIDE + ')?artifact', collInfo(['4R32', '8R103']))],
            ['possession-removal', 'Possession removal', by(discard + SIDE + CULT + '(\\w+ or ' + SIDE + ')?possession', collInfo(['4R32', '8R103']))]
        ]},
        {group: 'Threats', items: [
            ['add-threats', 'Add threats', by('add (up to )?(a|X|\\d) threat')],
            ['prevent-threat-removal', 'Prevent threat removal', by('(threat[^\\.]*(can|may) ?not (be )?remove|about to remove[^\\.]*threats?,)')],
            ['remove-threats', 'Remove threats', by('remove (up to )?(a|X|\\d) threat')]
        ]}
    ];

    var quick_by_code = {};
    _.each(terms.QUICK, function (group) {
        _.each(group.items, function (item) {
            quick_by_code[item[0]] = {code: item[0], label: item[1], test: item[2]};
        });
    });

    terms.quick = function quick(code)
    {
        return quick_by_code[code] || null;
    };

})(app.card_terms = {});
