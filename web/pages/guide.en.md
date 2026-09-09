# Get started with REON

<!-- Editing this page: see web/pages/README.md. Text in [brackets] is a
     placeholder still to be written. -->

Welcome! This page gets you from "I have a Game Boy game" to "I am online
with it", step by step. No prior knowledge needed. If anything goes wrong,
jump to [Troubleshooting](#troubleshooting) at the bottom.

## What you need

Two things:

1. **A way to run the game with a Mobile Adapter.** Either:
   - an emulator that knows about the Mobile Adapter GB (for example our
     build of **mGBA**, for PC and 3DS), **or**
   - a real Game Boy Color / Game Boy Advance and a substitute Mobile
     Adapter (for example a **PicoAdapterGB**).
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

<div class="reon-note">※ Closed that window without pressing the button?
No problem. You can download `config.bin` again at any time from
<a href="/user/summary.php">Your Account</a>.</div>

Keep a copy somewhere safe. You only need to download it **once**: the
same file works on every device you own, and you will need it again if
you reinstall your emulator or set up another device.

### 3. Give `config.bin` to your emulator

**mGBA**

1. Open a game that supports the Mobile Adapter GB.
2. In the menu, open **Mobile Game Boy Adapter…**.
3. Go to the **Settings** tab and press **Load config file**.
4. Pick the `config.bin` you downloaded. That's it!

**BGB**

1. Download **BGB** from its official site ([bgb.bircd.org](https://bgb.bircd.org/))
   and unzip it anywhere.
2. From the [Downloads](/downloads.php) page, get **mobile-windows.exe**
   (Windows) or **mobile-linux** (Linux). Put it in a folder of its own.
3. Put your `config.bin` in that same folder, next to the program. Keep
   the name `config.bin`.
4. Open BGB, load your game, then **right-click the BGB window → Link →
   Listen**. Leave the default port.
5. Open a terminal / command prompt in the program's folder and run:
   - Windows: `mobile-windows.exe --dns1 152.67.55.127 --relay 152.67.55.127`
   - Linux: `./mobile-linux --dns1 152.67.55.127 --relay 152.67.55.127`
     (on Linux, run `chmod +x mobile-linux` once first)
6. The window shows a line like `[device-auth] Pairing code: ED32-E9B2`.
   That code is how this computer appears in
   [Connected devices](/user/devices.php) on your account, so you can
   tell your devices apart, or block one.
7. Keep that window open while you play. In the game, use the mobile
   features as normal: the first time you connect, the program links this
   computer to your account by itself.

<div class="reon-note">If the program says <code>Could not connect
(127.0.0.1:8765)</code>, BGB is not listening yet: do step 4 first, then
run the program again.</div>

**3DS (mGBA)**

[To be written.]

**Real hardware (PicoAdapterGB)**

[To be written.]

### You're almost set!

Once the Mobile Adapter is set up, every supported game can reach REON.
Some games also have settings of their own, inside the game. Find yours
in the next section and see how to get it working, and what you can do
online. Get excited!

## Get started with games

### Pokémon Crystal

**Setup**

First, check two things:

- Your Pokémon Crystal ROM is the **patched** version. When the patched
  game boots you will see the **Mobile System GB** logo before the intro
  animation.
- On that logo screen, you should see a flashing message that says
  **Checking Mobile Adapter**. It is a good idea to plug the Mobile Adapter
  in *before* starting the game, and to leave it in until you are done.

Then get past the title screen. You will find a new menu item called
**MOBILE**. Select it and follow the game's instructions. Get ready for a
side of Pokémon Crystal you have never seen before!

**What you can do**

- **Battle and trade over Mobile:** [to be written]
- **The PokéCom Center in Goldenrod:**
  - **Trade Corner:** [to be written]
  - **Pokémon News Machine:** [to be written]
- **Battle Tower:** [to be written]
- **Mobile Stadium:** [to be written]

### Mario Kart: Super Circuit

[To be written.]

### Game Boy Wars 3

[To be written.]

<!-- Next game: copy the Pokémon Crystal section above and follow the
     same shape: "Setup", then "What you can do". -->

## Troubleshooting

### Error messages

[To be written: one entry per message the game can show, what it means,
what to do.]

### Frequently asked questions

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
