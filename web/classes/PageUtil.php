<?php
	require_once("TemplateUtil.php");
	require_once("SessionUtil.php");
	require_once("ServiceStatusUtil.php");
	require_once("PatchListUtil.php");
	require_once(__DIR__."/../vendor/autoload.php");

	use League\CommonMark\CommonMarkConverter;

	// Static site pages (the guide, downloads) written as Markdown files in
	// web/pages/, one per language: <slug>.<locale>.md, falling back to
	// <slug>.en.md. No database, no admin panel: editing a page is editing
	// the file, and the images it shows live in htdocs/images/pages/. See
	// web/pages/README.md for the conventions.
	class PageUtil {

		const PAGES_DIR = __DIR__."/../pages";
		const DEFAULT_LOCALE = "en";
		// Bump when render() changes what it outputs for the same Markdown
		// (the converter options, an upgrade of the library).
		const CACHE_VERSION = "1";

		// Renders /pages/<slug>.<locale>.md through the portal layout. The
		// first "# heading" of the file is the page title; the rest is the
		// body. $nav_item names the section (navbar highlight + gem colour).
		public static function show($slug, $nav_item) {
			$locale = SessionUtil::getInstance()->getLocale();
			$file = self::fileFor($slug, $locale);
			$services = ServiceStatusUtil::getInstance()->getAll();

			if ($file === null) {
				http_response_code(404);
				echo TemplateUtil::render("page", [
					"nav_item" => $nav_item,
					"page_title" => null,
					"html" => null,
					"services" => $services,
				]);
				return;
			}

			[$title, $markdown] = self::split(file_get_contents($file));
			echo TemplateUtil::render("page", [
				"nav_item" => $nav_item,
				"page_title" => $title,
				"html" => PatchListUtil::fill(self::renderCached($file, $markdown)),
				"services" => $services,
				"page_file" => basename($file),
			]);
		}

		// For a page that draws its own frame (the game hubs): the rendered
		// HTML of pages/<slug>.<locale>.md, or null without a file. The slug
		// may carry one folder, e.g. "games/pokemon".
		public static function html($slug) {
			$locale = SessionUtil::getInstance()->getLocale();
			$file = self::fileFor($slug, $locale);
			if ($file === null) return null;
			[, $markdown] = self::split(file_get_contents($file));
			return PatchListUtil::fill(self::renderCached($file, $markdown));
		}

		// render() through a small file cache. Converting a page's Markdown
		// cost about as much as the rest of the request put together (the
		// guide served half as many pages per second as the home page), and
		// the text only changes when someone edits the file. The key is the
		// file's own path, modification time and size, so an edit -- or a
		// deploy that copies the new file over -- is picked up on the next
		// request with nothing to clear. No writable temp dir: it just
		// renders each time, as before.
		private static function renderCached($file, $markdown) {
			$root = TemplateUtil::cacheRoot();
			if ($root === null) return self::render($markdown);

			$mtime = @filemtime($file);
			$key = sha1(self::CACHE_VERSION."|".$file."|".$mtime."|".strlen($markdown));
			$path = $root."/pages/".$key.".html";
			$html = @file_get_contents($path);
			if ($html !== false) return $html;

			$html = self::render($markdown);
			if (!is_dir($root."/pages")) @mkdir($root."/pages", 0700, true);
			// Written then renamed: a request that reads while another writes
			// never sees half a page.
			$tmp = $path.".".getmypid().".tmp";
			if (@file_put_contents($tmp, $html) !== false) @rename($tmp, $path);
			return $html;
		}

		// The file for this locale, else the English one, else nothing.
		public static function fileFor($slug, $locale) {
			if (!preg_match('#^[a-z0-9-]+(/[a-z0-9-]+)?$#', $slug)) return null;
			foreach (array_unique([$locale, self::DEFAULT_LOCALE]) as $l) {
				$path = self::PAGES_DIR."/".$slug.".".$l.".md";
				if (is_file($path)) return $path;
			}
			return null;
		}

		// ["Title", "body markdown"]: the first level-1 heading is the title
		// and is not repeated in the body (the layout draws it as the page's
		// pickup bar).
		public static function split($markdown) {
			$markdown = str_replace("\r\n", "\n", (string)$markdown);
			if (preg_match('/^#[ \t]+(.+?)[ \t]*\n/', $markdown, $m)) {
				return [trim($m[1]), substr($markdown, strlen($m[0]))];
			}
			return [null, $markdown];
		}

		public static function render($markdown) {
			// Unlike news posts these files are part of the site's source,
			// written by whoever can already edit its templates, so inline
			// HTML is allowed: a <video>, a two-column table, an <a id> anchor.
			$converter = new CommonMarkConverter([
				"html_input" => "allow",
				"allow_unsafe_links" => false,
			]);
			return (string)$converter->convert((string)$markdown);
		}
	}
?>
