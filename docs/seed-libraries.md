# Seeded Libraries

Seeded libraries are the PC's official groupings of decks (the starter decks, an event's decks,
a set's preconstructed decks), made from a manifest rather than by hand (Ketura, 2026-11-09).
Each becomes an **official, public library** owned by the manifest's owner account, the PC's
own. Librarians and admins look after them like any official library.

## Running it

Put manifests in `docker/data/seed-libraries/` on the host (mounted read-only at
`var/seed-libraries`), then:

```
docker exec -u www-data redbook php bin/console app:import:seed-libraries
docker exec -u www-data redbook php bin/console app:import:seed-libraries --dry-run
docker exec -u www-data redbook php bin/console app:import:seed-libraries var/seed-libraries/gemp-starters.json
```

Import the decks a manifest names first (`app:import:seed-decks`). Re-running is safe: each
library is keyed by the manifest's `source` and its own `id`, and on re-import its name, texts,
tags and decks follow the manifest (edits made on the site to a seeded library's fields are
overwritten; its likes, favourites and comments stay).

The owner account must exist before the first import (make it once, e.g. `PlayersCouncil`).

## Format

```json
{
  "source": "gemp-starters",
  "owner": "PlayersCouncil",
  "libraries": [
    {
      "id": "fotr-starters",
      "name": "The Fellowship of the Ring Starter Decks",
      "description": "The two **starter decks** from the first set.",
      "notes": "Markdown, as the library's notes.",
      "tags": ["starter-decks", "fotr"],
      "decks": [
        {"seed": {"source": "gemp", "id": "starter-gandalf"}, "annotation": "Markdown commentary on this deck."},
        {"decklist": 1234}
      ]
    }
  ]
}
```

- `source`: up to 30 characters; names the manifest's family. `id`: up to 100, unique in it.
- A deck is either a seeded decklist, by the `source` and `id` it was seeded with
  (`PublishedDeckSource`), or any published decklist by its number. A deck not found, or
  withdrawn, is left out and listed in the command's report; the rest still import.
- The decks keep the manifest's order.
