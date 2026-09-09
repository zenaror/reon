# Get started with REON

<!-- Editing this page: see web/pages/README.md. Text in [brackets] is a
     placeholder still to be written. -->

Welcome! This page gets you from "I have a Game Boy game" to "I am online
with it", step by step. No prior knowledge needed. If anything goes wrong,
jump to [Troubleshooting](#troubleshooting) at the bottom.

## What you need

Two things:

1. **A way to run the game with a Mobile Adapter.** Either:
   - an emulator that knows about the Mobile Adapter GB (for example
     [our build of **mGBA**](/downloads.php#emulators), for PC and 3DS), **or**
   - a real Game Boy Color / Game Boy Advance and a substitute Mobile
     Adapter (for example a [**PicoAdapterGB**](/downloads.php#real-hardware)).
2. **The game you want to play.** Some games need a small patch to bring
   their online features back. See [Get started with games](#get-started-with-games).

<div class="reon-note">For now this guide covers the emulator. Instructions
for real hardware will follow.</div>

## Setup

### 1. Make an account

Sign up on the [sign-up page](/signup.php) and follow the instructions.

<div class="reon-note">※ Everything online goes through the account you make.
That is one way the Mobile GB System differs from later services such as the
Nintendo Wi-Fi Connection: there are no "friend codes" tied to one device
that you cannot control. Your account is yours, on any device.</div>

### 2. Download your `config.bin`

Right after signing up you will see a large message with a big blue
**config.bin** button. Press it. This small file is what your emulator (or
adapter) needs to find REON.

<p class="when-signed-in"><a class="reon-chrome-btn" href="/user/adapter_config.php" download="config.bin">Download your config.bin</a></p>
<p class="when-signed-out"><a class="reon-chrome-btn" href="/login.php?next=%2Fguide.php%23setup">Log in to download your config.bin</a></p>

<div class="reon-note">※ Closed that window without pressing the button?
No problem. The button above, and <a href="/user/summary.php">Your
Account</a>, hand you the same file at any time.</div>

Keep a copy somewhere safe. You only need to download it **once**: the
same file works on every device you own, and you will need it again if
you reinstall your emulator or set up another device.

### 3. Give `config.bin` to your emulator

**mGBA (PC)**

1. [Download mGBA](/downloads.php#emulators) for your system and open a
   game that supports the Mobile Adapter GB.
2. In the menu, open **Mobile Game Boy Adapter…**.
3. Go to the **Settings** tab and press **Load config file**.
4. Pick the `config.bin` you downloaded. That's it!

**3DS (mGBA)**

[To be written.] The build is on the [Downloads](/downloads.php#emulators)
page.

**Real hardware (PicoAdapterGB)**

[To be written.] The firmware for each board is on the
[Downloads](/downloads.php#real-hardware) page.

### You're almost set!

Once the Mobile Adapter is set up, every supported game can reach REON.
Some games also have settings of their own, inside the game. Find yours
in the next section and see how to get it working, and what you can do
online. Get excited!

## Get started with games

The adapter is set up once; each game has its own page with its own
setup steps and what you can do online:

- [Pokémon Crystal](/pokemon/)
- [Mario Kart: Super Circuit](/mariokart/)
- [Game Boy Wars 3](/gbwars/)

<!-- Game-specific text lives in web/pages/games/<game>.<locale>.md, not
     here. This page is only what is the same for every game. -->

## Troubleshooting

### Error messages

[To be written: one entry per message the game can show, what it means,
what to do.]

### Frequently asked questions

<!-- BGB (libmobile-bgb) steps were removed on purpose for now: on the
     PC the guide shows only mGBA, so nobody is sent down the harder
     setup by mistake. Add them back here when the time comes. -->

**Do I need to download `config.bin` again?**
Only if you lost it. The same file works on all your devices, and a fresh
copy is always available from [Your Account](/user/summary.php).

**I use the same `config.bin` on my PC and my 3DS. Is that OK?**
Yes. Each device gets its own **pairing code**, which you can see on the
device and on your [Connected devices](/user/devices.php) page. You can
give each one a name there, and block one you no longer use.

**The game says "BLOCKED".**
That device was blocked on your Connected devices page. Unblock it there
and try again.
