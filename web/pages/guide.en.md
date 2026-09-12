# Get started with REON

<!-- Editing this page: see web/pages/README.md. Text in [brackets] is a
     placeholder still to be written. -->

Welcome! This page gets you from "I have a Game Boy game" to "I am online
with it", step by step. No prior knowledge needed. If anything goes wrong,
jump to [Troubleshooting](#troubleshooting) at the bottom.

## What you need

Two things:

1. **A way to run the game with a Mobile Adapter.** Either:
   - an emulator that knows about the Mobile Adapter GB — 
     [our build of **mGBA**](/downloads.php#emulators) for the PC is the
     one this guide walks through, **or**
   - a real Game Boy Color / Game Boy Advance and a substitute Mobile
     Adapter, such as a
     [**PicoAdapterGB**](https://github.com/zenaror/PicoAdapterGB).
2. **The game you want to play.** Some games need a small patch to bring
   their online features back — the patches are on
   [Downloads](/downloads.php#games-and-patches). What each game can do
   online, and how to reach it, is under
   [Get started with games](#get-started-with-games).

<div class="reon-note">※ The step-by-step below is for <strong>mGBA on a
PC</strong>, which supports everything REON offers. The other builds are
listed under <strong>Other ways to connect</strong>, at the end of Setup,
each pointing at the project that maintains it.</div>

## Setup

### 1. Make an account

Sign up on the [sign-up page](/signup.php) and follow the instructions.

<div class="reon-note">※ Everything online goes through the account you make.
That is one way the Mobile GB System differs from later services such as the
Nintendo Wi-Fi Connection: there are no "friend codes" tied to one device
that you cannot control. Your account is yours, on any device.</div>

### 2. Download your `mobile_config.bin`

Right after signing up you will see a large message with a big blue
**mobile_config.bin** button. Press it. This small file is what your emulator (or
adapter) needs to find REON.

<p class="when-signed-in"><a class="reon-chrome-btn" href="/user/adapter_config.php" download="mobile_config.bin">Download your mobile_config.bin</a></p>
<p class="when-signed-out"><a class="reon-chrome-btn" href="/login.php?next=%2Fguide.php%23setup">Log in to download your mobile_config.bin</a></p>

<div class="reon-note">※ Closed that window without pressing the button?
No problem. The button above, and <a href="/user/summary.php">Your
Account</a>, hand you the same file at any time.</div>

Keep a copy somewhere safe. You only need to download it **once**: the
same file works on every device you own, and you will need it again if
you reinstall your emulator or set up another device.

### 3. Give `mobile_config.bin` to your emulator

**mGBA (PC)**

1. [Download mGBA](/downloads.php#emulators) for your system and open a
   game that supports the Mobile Adapter GB.
2. In the menu, open **Mobile Game Boy Adapter…**.
3. Go to the **Settings** tab and press **Load config file**.
4. Pick the `mobile_config.bin` you downloaded. That's it!

### Other ways to connect

This guide covers **mGBA on a PC**, because that is the shortest path from
nothing to playing. The other builds work too, and each one is documented
where it is developed:

- **Other mGBA versions** — the same emulator also builds for the
  **Nintendo 3DS**, the **Wii** and the **Switch**.
  [Get them and read their instructions on GitHub](https://github.com/zenaror/mgba).
- **PicoAdapterGB** — a real adapter you build yourself, for a real Game Boy.
  [Get it and read its instructions on GitHub](https://github.com/zenaror/PicoAdapterGB).

<div class="reon-note">※ Whichever you use, the `mobile_config.bin` from
step 2 is the same file. Set one up and the rest of this site works the
same.</div>

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

When a game cannot get online it shows a message, and behind that message
there is a code like `10-000`. Type that code into the
**[adapter error codes](/errors.php)** page to look it up. Codes that mean
the same thing are listed together, under one answer.

### Frequently asked questions

<!-- Only mGBA on a PC gets step-by-step instructions here, and mGBA
     supports everything the service offers. The other builds are named
     under "Other ways to connect" with nothing but a link to the
     repository that maintains them: no steps and no download button, so
     there is no second copy here to drift out of date.

     libmobile-bgb / BGB is deliberately absent from the whole site for
     now -- mGBA covers the same ground with an easier setup. Put it back
     only when someone asks for it. -->

**Do I need to download `mobile_config.bin` again?**
Only if you lost it. The same file works on all your devices, and a fresh
copy is always available from [Your Account](/user/summary.php).

**I use the same `mobile_config.bin` on my PC and my 3DS. Is that OK?**
Yes. Each device gets its own **pairing code**, which you can see on the
device and on your [Connected devices](/user/devices.php) page. You can
give each one a name there, and block one you no longer use.

**The game says "BLOCKED".**
That device was blocked on your Connected devices page. Unblock it there
and try again.
