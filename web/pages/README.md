# Site pages

Each file here is one page of the site, written in Markdown:

| File | URL |
|---|---|
| `guide.en.md` | `/guide.php` |
| `downloads.en.md` | `/downloads.php` |
| `games/pokemon.en.md` | text sections of `/pokemon/` |
| `games/gbwars.en.md` | text sections of `/gbwars/` |
| `games/mariokart.en.md` | text sections of `/mariokart/` |

The guide holds only what is the same for every game (adapter setup,
errors, FAQ). Anything about one game goes in that game's file: each
`## Section` there becomes a section of the game's page and a button in
its menu, placed before the live part (services, map gallery, rankings).
These files have no `# Title` line; the game page already has one.

Editing a page is editing the file. Nothing to rebuild, nothing to
restart: the next request reads the new text.

## Conventions

- The first line is `# Title` — it becomes the page's title bar and is
  not repeated in the body.
- `## Section` headings are listed in the "Contents" box at the top of
  the page and get a jump anchor. `### Sub-section` headings do not.
- One file per language: `guide.pt-br.md`, `guide.ja.md`, … using the
  same codes as the site's language menu (`en`, `ja`, `de`, `es`, `it`,
  `fr`, `pt-br`). A language without its own file shows the English one.
- Images go in `htdocs/images/pages/` and are referenced as
  `![what it shows](/images/pages/filename.png)`.
- Inline HTML is allowed, for what Markdown cannot do. Two blocks have
  styles ready in `css/pages.css`:

```html
<div class="reon-note">A tip in a box (the ※ asides).</div>

<!-- one of the two shows, depending on whether the visitor is signed in;
     the login link's next= brings them back here afterwards -->
<p class="when-signed-in"><a class="reon-chrome-btn" href="/user/adapter_config.php" download="mobile_config.bin">Download</a></p>
<p class="when-signed-out"><a class="reon-chrome-btn" href="/login.php?next=%2Fguide.php">Log in to download</a></p>
<div class="reon-note reon-note--warn">A warning in a box.</div>

<div class="reon-downloads">
  <div class="reon-download">
    <h3>Name</h3>
    <p>One line about it.</p>
    <a class="reon-chrome-btn" href="…">Download</a>
  </div>

  <!-- one download with several builds: a picker drives the button -->
  <div class="reon-download">
    <h3>Name</h3>
    <select class="reon-download__pick">
      <option value="…/windows.zip" data-note="Shown under the picker.">Windows</option>
      <option value="…/linux.tar.gz">Linux</option>
    </select>
    <p class="reon-download__note"></p>
    <a class="reon-chrome-btn" href="…/windows.zip" data-download-for="pick">Download</a>
  </div>

  <!-- several pickers: their values joined with "|" look up a build in
       data-builds; a missing combination disables the button and shows
       data-unavailable -->
  <div class="reon-download reon-download--wide"
       data-builds='{"pico|reon": {"href": "…", "note": "file.uf2"}}'
       data-unavailable="No build for this combination yet.">
    <h3>Name</h3>
    <div class="reon-download__picks">
      <label>Board <select class="reon-download__pick"><option value="pico">Pico</option></select></label>
      <label>Wiring <select class="reon-download__pick"><option value="reon">REON</option></select></label>
    </div>
    <p class="reon-download__note"></p>
    <a class="reon-chrome-btn" href="#" data-download-for="pick">Download</a>
  </div>
</div>
```

To add a page: create `web/pages/<slug>.en.md`, then a two-line
`htdocs/<slug>.php` like `guide.php`, and if it should be in the menus,
add it to `templates/main.twig` and `templates/_portal_sidebar.twig`.
