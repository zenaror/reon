/* Shared "themed side background" driver.
 *
 * Several themed pages (Game Boy Wars, Mario Kart, and the pokecrystal pages)
 * paint fixed decorative panels in the margins beside the centered content.
 * They all measured the content column the same way and exposed its left/right
 * insets as CSS custom properties; this consolidates that duplicated logic.
 *
 *   initReonSideBackgrounds("gbwars");
 *   initReonSideBackgrounds("trade-corner", { bodyClass: "honor-roll-theme" });
 *   initReonSideBackgrounds("trade-corner", { bodyClass: "rankings-theme",
 *       onApply: function (left, right) { ... extra vars ... } });
 *
 * Sets `--<prefix>-nav-side-left` / `--<prefix>-nav-side-right` and
 * `--<prefix>-viewport-width` (the layout viewport, scrollbar excluded) on <html>.
 * options.bodyClass  — class added to <body> on boot (optional).
 * options.onApply    — callback(sideLeft, sideRight) for page-specific vars.
 */
(function () {
  function initReonSideBackgrounds(prefix, options) {
    options = options || {};

    function anchorEl() {
      return document.querySelector(
        "header.navbar.container-xxl, header.navbar, main#content.container-xxl, #content.container-xxl, .container-xxl"
      );
    }

    function apply() {
      var anchor = anchorEl();
      if (!anchor) {
        return;
      }
      var rect = anchor.getBoundingClientRect();
      // clientWidth, not innerWidth: innerWidth includes a classic vertical
      // scrollbar, while position:fixed panels end where the scrollbar
      // begins -- the right panel came out ~15px too wide and its inner
      // edge (the dotted border) slid under the white content box.
      var viewportWidth = document.documentElement.clientWidth || window.innerWidth;
      var sideLeft = Math.max(0, Math.round(rect.left));
      // Derived from the rounded left side and the container's width, not
      // rounded on its own: with a classic scrollbar the container sits at a
      // half pixel (e.g. 132.5px on a 1585px viewport) and rounding both
      // sides gave 133 + 133 for 265px of margin. The Rankings banner hangs
      // off the left side, so its right frame line then landed 1px right of
      // the right rail's. This keeps left + width + right exact.
      var sideRight = Math.max(0, viewportWidth - sideLeft - Math.round(rect.width));
      var style = document.documentElement.style;
      style.setProperty("--" + prefix + "-viewport-width", viewportWidth + "px");
      style.setProperty("--" + prefix + "-nav-side-left", sideLeft + "px");
      style.setProperty("--" + prefix + "-nav-side-right", sideRight + "px");
      if (typeof options.onApply === "function") {
        options.onApply(sideLeft, sideRight);
      }
    }

    function boot() {
      if (options.bodyClass) {
        document.body.classList.add(options.bodyClass);
      }
      apply();
      window.addEventListener("resize", apply, { passive: true });
      window.addEventListener("load", apply, { passive: true });
      if (document.fonts && typeof document.fonts.ready === "object") {
        document.fonts.ready.then(apply);
      }
    }

    if (document.readyState === "loading") {
      document.addEventListener("DOMContentLoaded", boot);
    } else {
      boot();
    }
  }

  window.initReonSideBackgrounds = initReonSideBackgrounds;
})();
