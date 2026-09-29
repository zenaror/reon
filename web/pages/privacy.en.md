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

REON is a **free, non-commercial community project**. There is no company
behind it, no legal entity, and no single person's name or postal address to
publish — and that is the honest description, not an omission. What answers for
the data is the project itself, through the people who run the server.

Brazilian law (LGPD art. 41) expects a named data protection officer where one
is required. It is not required here: the ANPD's small-scale-agent rules
(Resolution CD/ANPD nº 2/2022) cover a non-profit processing on a small scale,
which is what this is. **A contact channel is still expected, and that one is
not optional** — see Contact below.

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
inside the game itself. **The website never asks for those.** There is no field
for them anywhere on it — the cartridge sends them in its own format, and the
server accepts what arrives or the game does not work.

The website does ask for one thing of its own: an **optional date of birth**, at
sign-up or later on your account page. It exists for a single purpose, stated
plainly because a date of birth is a strong identifier and deserves the
explanation: it decides whether your results may appear in the public rankings.
It is never displayed to anyone, nothing else on the site reads it, and you
can finish signing up without it. Once it is saved it **cannot be changed or
removed from your account page** — a date that could be retyped at will would
not keep anyone out of the rankings — and it is deleted, with everything else,
when you delete your account. It is **not** the age the game sends — that one you type inside the
cartridge, and nobody checks it.

None of it is checked. Nothing verifies that the postcode exists, that it
matches the region, or that the age is true; the game does not check either.
The postcode also arrives cut short — the Japanese region sends three digits,
so `04162` reaches the server as `041`, and the full postcode cannot be
rebuilt from it. Geography only ever appears in the rankings: the Battle
Tower and the Trade Corner do not use it.

The age deserves its own warning. It is typed inside the game by the player,
and the game never updates it afterwards — no birthday moves it. A number
entered once stays as it was while the person grows older, so the age REON
holds is not only unverified, it may simply be out of date. REON therefore
uses it in one direction only: to keep someone **out** of what is public. It
never lets anyone in. See **Children** below.

**They are stored as they arrive, and here is what each is for.** The gender
and the age are part of what the game publishes in a ranking entry — the
cartridge asks for them and prints them back, so discarding them on arrival
would break the feature rather than tidy it. The postcode is different: it never
leaves the server and is never shown. It is used as a **grouping key** — it
decides which local ranking you are placed in, nothing more. That is why only
three digits are needed, and why only three arrive.

### Mail

Messages are held on the server until your game or the webmail collects
them. Deleted mail stays in the trash for 30 days and is then removed.

### Logs

The server records connections, administrative actions, and outbound mail,
each with an IP address and a time. It also keeps an activity log of what
accounts do on the service: sign-ups, sign-ins (and failed ones), password
and e-mail changes, account deletion, what the game downloads and uploads, and
trades. Each entry carries your account number and the time — not your IP
address (the web server's own log has that), and never your e-mail address, a
password, or anything you typed. This is how abuse is spotted and how faults
are traced.

## What is public

The ranking pages, the Trade Corner and the Battle Tower are **readable
without signing in**. The rankings show the name your game uploaded, your
**state or province** — picked from a fixed list inside the game, not your
postcode — and the message the game let you assemble.

That message is not free text: the game builds it from a **fixed word list**
of its own, and you choose from that list rather than typing. There is no way
to put a name, an address or a phone number into it.

This is how the original service worked, and it is the one thing on this
service that is deliberately public. So REON does not do it to you by
default: **a new account is not in the rankings.** Appearing there is
something you switch on — at sign-up, with an optional box, or later on
[Your Account](/user/summary.php). Nothing else about your game changes while
it is off: your results are still recorded and still count for you, they are
just not shown to anybody else.

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
- Connection logs (the web server's): **14 days**, already enforced by
  rotation.
- The activity log described above: **14 days**, same rotation.
- Connection records in the system journal: **30 days**.
- Database backups: a copy of the databases is made every night and kept
  **7 days**, on the same server, readable only by the administrator. It
  protects against a mistake or a corrupted table; it holds everything the
  live database holds, which is why an account you delete is gone from it
  only when the backups that contain it age out.
- Outbound-mail records, which hold a recipient and a subject line: **90 days**.
  The shortest window on this list, because a subject line is the most revealing
  thing in it.
- Administrative actions — the record of what was done to accounts: **1 year**.
- Device counters are not a log: the counter itself has to persist or the
  anti-replay check that protects your account stops working. So the row stays,
  and the **IP address in it is cleared after 30 days**.
- Notification history is yours to clear whenever you like. What nobody clears
  expires after **1 year**.
- Tournament recordings: **15 days**. When an administrator turns tournament
  mode on, the relay writes what passes between two linked consoles so the match
  can be turned into a replay afterwards. The mode is off unless switched on,
  the files never leave the admin panel, and they are deleted after the window —
  the intent is to fetch one right after the match, not to keep an archive.

These are enforced, not intended. A nightly job applies the database windows and
the journal has a time limit of its own — if a period here ever stops matching
what the system does, the system is the bug.

## Notifications

Service notifications — account changes, device blocks, administrative
actions — are listed on your notifications page, and **you can clear them
whenever you like**. Clearing removes the list only: the message or the trade an
entry pointed at stays where it was.

This paragraph used to say the opposite, and it was wrong from 12 September
2026, when the button was built, until 25 September, when this was noticed. It
described notifications as a permanent record that could not be removed, and
justified that as protecting the evidence of a break-in. The button won that
argument: the record belongs to the person it is about.

## Your rights

Under the LGPD (art. 18) and the GDPR (arts. 15–22) you may ask what is held
about you, ask for it to be corrected, ask for a copy, and ask for it to be
deleted.

All of that is on [Your Account](/user/summary.php), and none of it needs to
go through a person:

- **Download my data** hands over everything the server holds about the
  account as a single file, your mail included. Device keys and the relay
  token are named but not written out — they are live credentials, and a copy
  of them in a file you carry around is a copy that did not exist before.
- **Delete account** removes the account and everything attached to it: mail,
  devices and their keys, and what the games recorded. There is no undo and
  no grace period, so it asks for your password and for the account name
  typed by hand.

  Two things about it are worth knowing before you use it, because neither is
  obvious.

  **Copies already downloaded to other people's game cartridges are beyond
  reach.** Your ranking entry is removed from the server at once, but a player
  who had already downloaded that issue keeps it in their cartridge until they
  fetch a new one. Nothing on a server can reach into a Game Boy that is not
  connected, and no promise here should pretend otherwise.

  **A deleted account stays in the nightly backup until it ages out.** The
  live database forgets it at once; the copies of the database taken each night
  (see "How long it is kept") are not edited, so the account disappears from
  them within seven days, when the last one that contained it is deleted.

  **The address cannot start a new account for six months.** That window exists
  to stop accounts being cycled — deleted and recreated in quick succession. We
  do not keep the address to do it: what is kept is a one-way fingerprint of it,
  which can answer "is this address blocked?" and nothing else, and which is
  itself deleted when the six months end. If you try to register in the
  meantime, the form says nothing unusual and an e-mail to that address explains
  why — telling a stranger at the form that an address is blocked would tell
  them an account once existed there.
- **Rankings** are off until you turn them on. While they are off, what
  your game uploads is kept and still counts for you, but is shown to nobody.
  Switching it off again hides what is already there; switching it back on
  brings it back.
- You can change your e-mail and password, roll your game login password, and
  name or block any device.

For anything not covered by those, write to the contact above and it is
handled by hand. [Decision: the deadline REON commits to. The LGPD's default of
**15 days** is the obvious answer and the one to take unless there is a reason
not to.]

## Children

The games this service revives were made for children, so this is not a
formality.

Two things are already in place:

- **Nothing about you is public unless you ask for it.** The rankings are the
  only public surface, and a new account is not in them.
- **Under 13, the rankings are closed.** Two independent signals can say so,
  and either is enough: the date of birth on the account, and the age set
  inside your game. Both are used only to keep someone out, never to let anyone
  in, and both are applied to records already stored rather than only to new
  ones.
- **What a ranking entry actually publishes** is the reason this is not left to
  choice. It is not only a score: the game prints your **age, your gender, your
  region and your name together, on one line**, to every other player who opens
  that ranking.

The game's age is weak on its own: self-declared, unchecked, and never updated
by the game — a number typed once sits there ageing while the player grows up.
The date of birth is better on both counts, because it ages by itself and it is
known before the first upload rather than after. Neither is verified, so both
are used in the protective direction only. An age or date inflated to get past
the rule protects nobody, but that person was not protected before either.

**There is no minimum age for having an account.** Anyone may register, and
nothing about the account is public. What the age governs is the one public
surface, the rankings — and that is a deliberate choice: shutting children out
of a service that asks nothing of them would protect no one, while publishing
their age and region to strangers would harm them.

## Changes to this policy

When the service changes what it collects, this page changes with it, and
the date below changes. **Existing accounts are told**, through the same
notification system the service already uses for everything else, and the notice
stays in your history until you clear it.

Expect that to be rare. What changes on REON is content and game support — new
material, better coverage of a game — not what it collects or who sees it. A
change to this page is an event, not a routine.

---

*Draft of 29 September 2026.*
