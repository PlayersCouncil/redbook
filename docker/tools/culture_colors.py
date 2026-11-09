#!/usr/bin/env python3
"""
Culture colours from the card templates (Ketura, 2026-11-03).

For each culture, three cards from sets 5-13, where the templates are most strongly
coloured, are read from the card CDN: characters (companion, ally or minion) first, other
cards only to make up the three. Cards on a template of their own are skipped (SKIP: the
Balrog's fiery red is not Moria's).

- primary: the title bar's colour gradient, at whichever end of the bar is more saturated
  (just right of the twilight cost, or the right edge past the culture icon). Two-tone
  bars (Dwarven, Rohan, Shire) carry the culture's hue at one end only.
- secondary: the collector's-info patch at the lower right of the text box, a near-solid
  tint of the culture's parchment. Its darker half (the lettering) is dropped.

The art, the text box and the left-side icons are never sampled.

Prints CSS custom properties for assets/css/tokens.css (the "culture colours" block,
which is where the site reads them from). Run it with the card list the database gives:

  docker exec redbook_db mariadb -uredbook -p... redbook -N -e "
    select cu.code, c.code, c.image_url from card c
    join culture cu on cu.id = c.culture_id join type t on t.id = c.type_id
    join pack p on p.id = c.pack_id
    where c.kind = 'card' and cast(p.code as unsigned) between 5 and 13 and c.image_url is not null
    order by cu.code, t.code not in ('companion', 'ally', 'minion'), cast(p.code as unsigned), c.position" > cards.tsv
  python3 docker/tools/culture_colors.py cards.tsv

Needs Pillow. Card images are 357x497.
"""
import colorsys
import io
import statistics
import sys
import urllib.request
from collections import defaultdict

from PIL import Image

PER_CULTURE = 3
# The Balrog (and his whip) are on a fiery template of their own, not Moria's (Ketura, 2026-11-03)
SKIP = {'6_76', '12_79', '12_80'}
# not a culture with a template of its own
SKIP_CULTURES = {'the_one_ring'}
TITLE_ENDS = [(50, 22, 74, 60), (333, 18, 340, 60)]
COLLECTOR_PATCH = (300, 456, 336, 466)


def hsv(p):
    return colorsys.rgb_to_hsv(*[c / 255 for c in p])


def median(pixels):
    return tuple(int(statistics.median(c)) for c in zip(*pixels))


def hex_of(c):
    return '#%02X%02X%02X' % c


def load(url):
    with urllib.request.urlopen(url, timeout=30) as r:
        return Image.open(io.BytesIO(r.read())).convert('RGB')


def culture_colours(images):
    ends = []
    for box in TITLE_ENDS:
        pix = [p for im in images for p in im.crop(box).getdata() if hsv(p)[2] > 0.2]
        pix.sort(key=lambda p: -(hsv(p)[1] * hsv(p)[2]))
        ends.append(median(pix[:max(1, len(pix) * 3 // 10)]))
    primary = max(ends, key=lambda c: hsv(c)[1])
    patch = []
    for im in images:
        raw = sorted(im.crop(COLLECTOR_PATCH).getdata(), key=sum)
        patch += raw[len(raw) // 2:]
    return primary, median(patch)


def main(path):
    urls = defaultdict(list)
    for line in open(path, encoding='utf-8'):
        culture, code, url = line.rstrip('\n').split('\t')
        if code not in SKIP and culture not in SKIP_CULTURES and len(urls[culture]) < PER_CULTURE:
            urls[culture].append(url)
    for culture in sorted(urls):
        primary, secondary = culture_colours([load(u) for u in urls[culture]])
        name = culture.replace('_', '-')
        print(f'    --rb-culture-{name}: {hex_of(primary)}; --rb-culture-{name}-light: {hex_of(secondary)};')


if __name__ == '__main__':
    main(sys.argv[1])
