# Seeded Libraries

Seeded libraries are the PC's official groupings of decks (the starter decks, an event's decks,
a set's preconstructed decks), made from a manifest rather than by hand (Ketura, 2026-11-09).
Each becomes an **official, public library** owned by an organization account, the PC's own
unless the manifest names another. Librarians and admins look after them like any official
library.

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
tags and decks follow the manifest. That overwrites edits made on the site, as a new revision
in the library's history (Ketura, 2026-11-09), so a re-import made by mistake is undone with
Restore on the editor's History tab. Its likes, favourites and comments stay.

## Format

The full schema, with every field's limits, is `_planning/schema/seed-library.schema.json`
(and `10-seed-library-schema.md` beside it).

```json
{
  "schemaVersion": 1,
  "source": "gemp-starters",
  "owner": "Decipher",
  "ownerName": "Decipher",
  "libraries": [
    {
      "id": "fotr-starters",
      "name": "The Fellowship of the Ring Starter Decks",
      "description": "The two **starter decks** from the first set.",
      "notes": "Markdown, as the library's notes.",
      "tags": ["starter-decks", "fotr"],
      "curator": "someusername",
      "decks": [
        {"seed": {"source": "gemp", "sourceId": "starter-gandalf"}, "annotation": "Markdown commentary on this deck."},
        {"decklist": 1234}
      ]
    }
  ]
}
```

- `source`: up to 30 characters; names the manifest's family. `id`: up to 100, unique in it.
- `owner` (optional): the username of the organization account that owns the libraries; the
  PC's (`APP_OFFICIAL_ACCOUNT`) when left out. It's made if it doesn't exist yet, shown as
  `ownerName`. It must be an organization's account, not a person's (see below).
- A deck is either a seeded decklist, by its batch's `source` and its own `sourceId`, as the
  seed deck batch gave them (`PublishedDeckSource`), or any published decklist by its number. A deck not found, or
  withdrawn, is left out and listed in the command's report; the rest still import.
- The decks keep the manifest's order.
- `curator` (optional): the username credited as "Curated by" on the library; the library
  still belongs to the owner account. A curator not found is reported and left off.

## Organization accounts

Decipher's and the Players Council's accounts are organization accounts (Ketura, 2026-11-09):
like historic accounts, nobody logs in as them. They're disabled with no usable password, can't
be claimed or merged, and only own things. Admins edit their profiles (name, bio, colour) with
the Edit Profile button on the account's page. One is made the first time it's needed (Make
Official, a seed import), or by hand:

```
docker exec -u www-data redbook php bin/console app:account:organization Decipher "Decipher"
docker exec -u www-data redbook php bin/console app:account:organization PlayersCouncil "Players Council" --convert
```

`--convert` turns an existing ordinary account into an organization's (its login stops working
and its roles are dropped); without it the command refuses one.

## Official libraries made from a user's

An admin's **Make Official** on a public user library copies it into a new official library
owned by the official account (`APP_OFFICIAL_ACCOUNT`, `PlayersCouncil` by default), crediting
the user as curator and linking back to the original (Ketura, 2026-11-09). The user's library
stays theirs. Librarians look after the copy; **Refresh from Original** copies the original's
contents over it again.
