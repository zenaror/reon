# Site pages

Each file here is one page of the site, written in Markdown:

| File | URL |
|---|---|
| `guide.en.md` | `/guide.php` |
| `downloads.en.md` | `/downloads.php` |

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
<div class="reon-note reon-note--warn">A warning in a box.</div>

<div class="reon-downloads">
  <div class="reon-download">
    <h3>Name</h3>
    <p>One line about it.</p>
    <a class="reon-chrome-btn" href="…">Download</a>
  </div>
</div>
```

To add a page: create `web/pages/<slug>.en.md`, then a two-line
`htdocs/<slug>.php` like `guide.php`, and if it should be in the menus,
add it to `templates/main.twig` and `templates/_portal_sidebar.twig`.
