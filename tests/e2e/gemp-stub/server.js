// A stand-in for Gemp's deck endpoints in the e2e stack (phase3/08), so the
// browser tests get the same answers every run without a real Gemp.
//
//   POST /gemp-lotr-server/deck/formats       recorded from test.lotrtcgpc.net
//   GET  /gemp-lotr-server/hall/formats/json  recorded from test.lotrtcgpc.net
//   POST /gemp-lotr-server/deck/statsJson     rule-of-thumb validation (below)
//   POST /gemp-lotr-server/deck/convertJson   Decipher -> PC errata from recorded/errata-map.json
//   GET  /share/deck                          a shared deck's page, recorded from play.lotrtcgpc.net
//   GET  /gemp-lotr-server/deck/libraryHtml   a library deck's page, recorded from play.lotrtcgpc.net
//
// statsJson checks only what the tests rely on, with Gemp's own wording: a
// Ring-bearer and Ring, the minimum draw deck size, equal Free Peoples and
// Shadow counts, and 9 sites. Sides come from the card data (set by RED_BOOK_CARDS,
// default the app's public API). Everything else is accepted. It is not Gemp.
//
// Re-record the formats with: node record.js (needs network access to Gemp).

const http = require('http');
const fs = require('fs');
const path = require('path');

const PORT = process.env.PORT || 8080;
const CARDS_URL = process.env.RED_BOOK_CARDS || 'http://app/api/public/cards/';
const read = (name) => JSON.parse(fs.readFileSync(path.join(__dirname, 'recorded', name), 'utf8'));
const deckFormats = read('deck-formats.json');
const hallFormats = read('hall-formats.json');
const errataMap = read('errata-map.json');

let sides = null; // blueprint ID -> side
function loadSides() {
  return new Promise((resolve) => {
    if (sides) return resolve(sides);
    http.get(CARDS_URL, (res) => {
      let body = '';
      res.on('data', (c) => (body += c));
      res.on('end', () => {
        try {
          sides = {};
          for (const card of JSON.parse(body)) sides[card.code] = card.side;
        } catch (e) {
          sides = null;
        }
        resolve(sides || {});
      });
    }).on('error', () => resolve({}));
  });
}

function parseForm(body) {
  const out = {};
  for (const pair of body.split('&')) {
    if (!pair) continue;
    const [k, v = ''] = pair.split('=');
    out[decodeURIComponent(k.replace(/\+/g, ' '))] = decodeURIComponent(v.replace(/\+/g, ' '));
  }
  return out;
}

function parseDeck(contents) {
  const parts = (contents || '').split('|');
  if (parts.length < 4 || parts.length > 5) return null;
  const list = (s) => (s || '').split(',').map((x) => x.trim()).filter(Boolean);
  return { ringBearer: parts[0] || null, ring: parts[1] || null, sites: list(parts[2]), drawDeck: list(parts[3]), map: parts[4] || null };
}

function formatFor(code) {
  const f = deckFormats.Formats[code] || deckFormats.Formats.rev_tow_sta;
  return f;
}

async function stats(form) {
  const deck = parseDeck(form.deckContents);
  if (!deck) return [400, { error: 'Invalid deck contents' }];
  const format = formatFor(form.targetFormat);
  const sideOf = await loadSides();
  let fp = 0, shadow = 0;
  for (const code of deck.drawDeck) {
    if (sideOf[code] === 'shadow') shadow++; else if (sideOf[code] === 'free_peoples') fp++;
  }
  const problems = [];
  if (!deck.ringBearer) problems.push("Deck doesn't have a Ring-bearer");
  if (!deck.ring) problems.push("Deck doesn't have a One Ring");
  let size = '';
  if (deck.drawDeck.length < format.minimumDeckSize) size += `Deck contains below minimum number of cards: ${deck.drawDeck.length}<${format.minimumDeckSize}.\n`;
  if (format.validateShadowFPCount && fp !== shadow) size += 'Deck contains different number of Shadow and Free peoples cards.\n';
  if (size) problems.push(size);
  if (deck.sites.length !== 9) problems.push(`Deck has ${deck.sites.length} sites instead of 9.\n`);
  const pc = !!(hallFormats.formats.find((f) => f.code === format.code) || {}).errata?.pc;
  return [200, {
    formatCode: format.code, formatName: format.name, freePeoplesCount: fp, shadowCount: shadow,
    valid: problems.length === 0, problems,
    validWithErrata: pc ? problems.length === 0 : null, problemsWithErrata: pc ? problems : null,
  }];
}

function convert(form) {
  const deck = parseDeck(form.deckContents);
  if (!deck) return [400, { error: 'Invalid deck contents' }];
  const format = formatFor(form.targetFormat);
  const swap = (c) => (c && errataMap[c]) || c;
  const out = {
    ringBearer: swap(deck.ringBearer), ring: swap(deck.ring), sites: deck.sites.map(swap),
    drawDeck: deck.drawDeck.map(swap), map: deck.map,
  };
  out.deckContents = [out.ringBearer || '', out.ring || '', out.sites.join(','), out.drawDeck.join(',')]
    .concat(out.map ? [out.map] : []).join('|');
  return [200, { formatCode: format.code, formatName: format.name, ...out }];
}

http.createServer((req, res) => {
  let body = '';
  req.on('data', (c) => (body += c));
  req.on('end', async () => {
    const url = req.url.replace(/^\/gemp-lotr-server/, '').split('?')[0];
    const form = parseForm(body);
    let result;
    if (url === '/deck/formats') result = [200, deckFormats];
    else if (url === '/hall/formats/json') result = [200, hallFormats];
    else if (url === '/deck/statsJson' && req.method === 'POST') result = await stats(form);
    else if (url === '/deck/convertJson' && req.method === 'POST') result = convert(form);
    else if (url === '/health') result = [200, { ok: true }];
    else if ((url === '/share/deck' || url === '/deck/libraryHtml') && req.method === 'GET') {
      res.writeHead(200, { 'Content-Type': 'text/html; charset=UTF-8' });
      res.end(fs.readFileSync(path.join(__dirname, 'recorded', url === '/share/deck' ? 'share-deck.html' : 'library-deck.html')));
      return;
    }
    else result = [404, { error: 'not in the stub' }];
    res.writeHead(result[0], { 'Content-Type': 'application/json' });
    res.end(JSON.stringify(result[1]));
  });
}).listen(PORT, () => console.log(`Gemp stub on ${PORT}`));
