# vivutio/front-desk-module

**The front desk for vivutio.** A day at a place's desk: the arrivals still
expected, the guests in house, those due out, those who left and those who
never came; checking in, with the units each stay is given, checking out, and
no-shows.

## Contents

- [Install](#install)
- [How it works](#how-it-works)
- [Who may do what](#who-may-do-what)
- [Development](#development)
- [Licence](#licence)

## Install

In a vivutio installation:

```bash
composer require vivutio/front-desk-module
php bin/console doctrine:migrations:migrate
```

Its recipe registers the bundle and mounts its pages with
`config/routes/front_desk.yaml`, a file the installation owns.

## How it works

The desk names no other module. It works from whatever the installation's
packages offer through the core's stay contracts (`Vivutio\Contracts\Stay`):

- **stays**, guests expected at a place for some nights, each with the units
  it takes (the property module offers its confirmed bookings);
- **units**, what a place has to put guests in, by kind and how many (the
  property module offers its room types: Tented Room 1 to 20).

The desk keeps only its own record of each stay, by its kind and id: when it
was checked in and out, or that its guests never came, and the units it was
given.

- **Checking in**: from the arrival day until before the departure, with as
  many units of each kind as the stay takes, each one the place has and none a
  guest in house holds.
- **Checking out**: any guest in house, due out or leaving early.
- **No-shows**: an expected stay not yet in, from its arrival day; the mark is
  taken back if they come after all.

It puts itself in the shell through the core's seams (`Vivutio\Contracts\Shell`):

- **the menu**: the desk, for whoever holds `front_desk.read`;
- **the dashboard**: over every place the person may open, today, two figures
  in the band (stays in house tonight, in how many places; arrivals, and how
  many are checked in) and two cards, *Arriving today* (expected first, then
  checked in) and *Leaving today* (still in house first, then checked out),
  each the first five and how many in all, with one door to the desk. A stay
  does not say how many guests it brings, so stays are counted, not guests.

## Who may do what

| Pair | Who |
|---|---|
| `front_desk.read` | The places it serves and a place's board |
| `front_desk.record` | Checking in and out, and no-shows |

Both are a module's pairs, held where the person's department, or one they
support, allows them too, and asked about the place, so where a person's
permissions apply narrows them. The suite extends the core's authority test
base; `tests/authority-table.md` is the reviewed table.

## Development

```bash
composer update
composer check
```

## Licence

AGPL-3.0-or-later. See [LICENSE](LICENSE).
