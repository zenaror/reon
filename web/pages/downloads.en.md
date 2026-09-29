# Downloads

<!-- Editing this page: see web/pages/README.md. Replace each href="#"
     (and each option's value="#") with the real link when the file is
     published. -->

Everything you need to get online, in one place. Not sure what to pick?
Start with the [guide](/guide.php) — it also hands you your
`mobile_config.bin`, which is the one file every device here needs.

## Emulators

<div class="reon-downloads">
  <div class="reon-download">
    <h3>mGBA</h3>
    <p>Our build of mGBA with Mobile Adapter GB support. Pick your platform:</p>
    <select class="reon-download__pick" aria-label="Platform">
      <option value="#" data-note="Nothing to install: unzip and run mGBA.exe.">Windows</option>
      <option value="#" data-note="Unzip and start it with mgba-qt.sh.">Linux</option>
    </select>
    <p class="reon-download__note"></p>
    <a class="reon-chrome-btn" href="#" data-download-for="pick">Download</a>
  </div>
</div>
<div class="reon-note">※ The same emulator also has builds for other devices.
Those are not handed out here — get them, and their instructions, from
<a href="https://github.com/zenaror/mgba">the project on GitHub</a>.</div>
<!-- Only the PC builds are offered from this page; every other build is a
     link to the repository that maintains it, so there is no second copy
     here to go stale. BGB + libmobile-bgb is deliberately absent from the
     whole site for now: mGBA covers the same ground with a simpler setup. -->

<div class="reon-note">These are unofficial builds. Please do not report
problems with them to the emulators' original authors.</div>

<!-- A seção "Real hardware" saiu daqui: esta página é só para o que se
     baixa dela, e o PicoAdapterGB não se baixa. Ele é citado no guia, em
     "Other ways to connect", apontando para
     https://github.com/zenaror/PicoAdapterGB -->

## Game patches

<div class="reon-note"><strong>Do you need a patch?</strong> Not if you already
have the <strong>Japanese</strong> version of the game. Those were made to go
online, so they connect to the server just as they are.</div>

The patches here are for the other versions. A patch is a small file that
holds a list of changes: you give it your own copy of the game, it makes the
changes, and you get a new version. Some add the online part to a game that
never had it; some translate the game (Game Boy Wars 3 becomes English).
Nothing here is, or contains, a software backup: you bring your own.

To use one, pick the game below and get the exact original it names. Open
that original and the patch together in a patching program (for example
[Floating IPS](https://github.com/Alcaro/Flips)) and save the result. If the
program says no, your copy is a different edition from the one the patch was
made for; compare it with the original's SHA-1 shown below, which works like
a fingerprint. The patched file's SHA-1 is the fingerprint the finished game
should have.

<!--reon:patches-->

<!-- A seção "For developers" (ROM de teste do Mobile Adapter GB) foi tirada
     por ora, a pedido do dono. O texto anterior era:
       "A test ROM that exercises an adapter against REON, in GBDK and RGBDS
        builds." -- com o download ainda por publicar. -->
