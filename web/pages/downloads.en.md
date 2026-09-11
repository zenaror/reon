# Downloads

<!-- Editing this page: see web/pages/README.md. Replace each href="#"
     (and each option's value="#") with the real link when the file is
     published. -->

Everything you need to get online, in one place. Not sure what to pick?
Start with the [guide](/guide.php).

## Your mobile_config.bin

Your personal `mobile_config.bin` is tied to your account. Download it once and
keep it; the same file works on every device you own.

<p class="when-signed-in"><a class="reon-chrome-btn" href="/user/adapter_config.php" download="mobile_config.bin">Download your mobile_config.bin</a></p>
<p class="when-signed-out"><a class="reon-chrome-btn" href="/login.php?next=%2Fdownloads.php">Log in to download your mobile_config.bin</a></p>

It is also on [Your Account](/user/summary.php), and the
[guide](/guide.php#setup) says where to put it.

## Emulators

<div class="reon-downloads">
  <div class="reon-download">
    <h3>mGBA</h3>
    <p>Our build of mGBA with Mobile Adapter GB support. Pick your platform:</p>
    <select class="reon-download__pick" aria-label="Platform">
      <option value="#" data-note="Nothing to install: unzip and run mGBA.exe.">Windows</option>
      <option value="#" data-note="Unzip and start it with mgba-qt.sh.">Linux</option>
      <option value="#" data-note="For a 3DS with custom firmware. .3dsx and .cia included.">Nintendo 3DS</option>
    </select>
    <p class="reon-download__note"></p>
    <a class="reon-chrome-btn" href="#" data-download-for="pick">Download</a>
  </div>
</div>
<!-- BGB + libmobile-bgb is deliberately not listed yet: on the PC only mGBA
     is offered for now, its setup is the simpler one. -->

<div class="reon-note">These are unofficial builds. Please do not report
problems with them to the emulators' original authors.</div>

## Real hardware

**PicoAdapterGB** is firmware for a Raspberry Pi Pico that stands in for the
Mobile Adapter. Find your board below, then pick the wiring you built.

- **Pico W / Pico 2 W:** the board has Wi-Fi built in.
- **Pico / Pico 2 + ESP module:** a board without Wi-Fi, with an ESP module
  attached for the network.
- **Wiring:** either the REON pinout, or the pinout of the stacksmashing board.

<div class="reon-downloads">
  <div class="reon-download reon-download--wide" data-builds='{
    "pico|w|reon":   {"href": "#", "note": "picow-reon.uf2"},
    "pico|w|sm":     {"href": "#", "note": "picow-sm.uf2"},
    "pico|esp|reon": {"href": "#", "note": "pico-esp-reon.uf2"},
    "pico|esp|sm":   {"href": "#", "note": "pico-esp-sm.uf2"},
    "pico2|w|reon":  {"href": "#", "note": "pico2w-reon.uf2"},
    "pico2|esp|reon":{"href": "#", "note": "pico2-esp-reon.uf2"}
  }' data-unavailable="No build for this combination yet.">
    <h3>PicoAdapterGB</h3>
    <div class="reon-download__picks">
      <label>Board
        <select class="reon-download__pick" data-pick="board">
          <option value="pico">Pico</option>
          <option value="pico2">Pico 2</option>
        </select>
      </label>
      <label>Network
        <select class="reon-download__pick" data-pick="radio">
          <option value="w">Wi-Fi on board (Pico W)</option>
          <option value="esp">ESP module</option>
        </select>
      </label>
      <label>Wiring
        <select class="reon-download__pick" data-pick="pinout">
          <option value="reon">REON pinout</option>
          <option value="sm">stacksmashing pinout</option>
        </select>
      </label>
    </div>
    <p class="reon-download__note"></p>
    <a class="reon-chrome-btn" href="#" data-download-for="pick">Download</a>
  </div>
</div>

<div class="reon-note">To install: hold BOOTSEL while plugging the Pico in,
then copy the <code>.uf2</code> onto the drive that appears. [To be written:
first-time Wi-Fi setup and where to put <code>mobile_config.bin</code>.]</div>

## Games and patches

[To be written: patches that restore each game's online features, and
where to get them.]

## For developers

<div class="reon-downloads">
  <div class="reon-download">
    <h3>Mobile Adapter GB test suite</h3>
    <p>A test ROM that exercises an adapter against REON, in GBDK and RGBDS builds.</p>
    <a class="reon-chrome-btn" href="#">Download</a>
  </div>
</div>
