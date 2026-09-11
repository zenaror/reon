# Privacy Policy

<!-- Editing this page: see web/pages/README.md. Text in [brackets] is a
     decision still to be made, not a phrasing still to be polished.
     Everything outside brackets describes what the service does today; if
     the code changes, this page has to change with it. -->

<div class="reon-note reon-note--warn">※ <strong>Draft.</strong> This is a
first version, written to describe what REON actually collects today rather
than what a privacy policy usually says. It has not been reviewed by a
lawyer, and several decisions it depends on have not been made yet — those
are marked in [brackets].</div>

## Who is responsible

[Decision: the person or entity answerable for the data, and the address
where they can be reached. Brazilian law (LGPD art. 41) also expects a named
data protection officer where one is required.]

## What REON collects

### Your account

- The **username** and **e-mail address** you sign up with.
- Your **website password**, stored only as a one-way hash. Nobody can read
  it back, not even us.
- Your **game login password** — the one inside your `mobile_config.bin` —
  stored in a form the server can read.

That last one deserves a plain explanation, because it is unusual and it is
not an oversight. The Mobile Adapter proves who it is by sending an MD5 of a
challenge combined with that password. To check the answer, the server has to
combine the same challenge with the same password, which means it has to hold
the password itself. The protocol was designed in 2001 and cannot be changed
without the games stopping working. Two things follow: **that password must
not be one you use anywhere else**, and you can replace it at any time from
[Your Account](/user/summary.php).

### Your devices

Each emulator or adapter that connects gets its own **pairing key** and a
connection counter, so you can see and block your devices on the
[Connected devices](/user/devices.php) page.

### What your game sends

REON receives what the cartridge uploads. Depending on the game that
includes your in-game name, ranking scores, Battle Tower and Trade Corner
records, and the messages the game lets you write.

Some games also upload the **age, gender and postcode** that were entered
inside the game itself. REON stores those as they arrive. [Decision: whether
to keep storing them, discard them on arrival, or ask first — nothing depends
on them today.]

### Mail

Messages are held on the server until your game or the webmail collects
them. Deleted mail stays in the trash for 30 days and is then removed.

### Logs

The server records connections, administrative actions, and outbound mail,
each with an IP address and a time. This is how abuse is spotted and how
faults are traced.

## What is public

The ranking pages, the Trade Corner and the Battle Tower are **readable
without signing in**, and show the name your game uploaded, your sub-region,
and any message the game let you write. This is how the original service
worked. Treat anything your game uploads as public.

## Where it is kept, and who else sees it

The server is in **São Paulo, Brazil**.

Mail that leaves REON for a real internet address is handed to **Brevo**, a
mail provider in **France**, which delivers it. Mail between REON players
never leaves the server. Sending to an outside address is therefore an
international transfer of whatever that message contains (LGPD ch. V, GDPR
ch. V) — a reason to keep game mail inside the game.

Nothing is sold, and nothing is handed to advertisers.

## How long it is kept

- Game records (rankings, Battle Tower, Trade Corner) expire on their own
  after their own window, as the original service did.
- Deleted mail: 30 days.
- [Decision: how long connection logs, administrative logs, outbound-mail
  logs and device counters are kept. Today they are kept indefinitely, which
  is the one thing on this page that is a plain gap rather than a design
  choice.]

## Notifications you cannot delete

Service notifications — account changes, device blocks, administrative
actions — are kept as a permanent record and cannot be removed from your
account. This is deliberate: it is the log of what was done to your account,
and an account holder who could erase it could also erase the evidence of a
break-in. [Decision: the written justification this rests on, since art. 18
of the LGPD gives a right to deletion.]

## Your rights

Under the LGPD (art. 18) and the GDPR (arts. 15–22) you may ask what is held
about you, ask for it to be corrected, ask for a copy, and ask for it to be
deleted.

Said plainly, because a policy that promises buttons that do not exist is
worse than one that does not: **account deletion and data export are not
built yet.** Until they are, the way to exercise any of these rights is to
write to the contact above, and it is handled by hand. [Decision: the
deadline REON commits to — the LGPD's default is 15 days.]

What does work today: you can change your e-mail and password, roll your game
login password, and name or block any device, all from
[Your Account](/user/summary.php).

## Children

[Decision: whether REON sets a minimum age. The games this service revives
were made for children, so this is not a formality.]

## Changes to this policy

When the service changes what it collects, this page changes with it, and
the date below changes. [Decision: whether existing accounts are notified.]

---

*Draft of 11 September 2026.*
