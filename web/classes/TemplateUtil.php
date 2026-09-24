<?php
	require_once dirname(__DIR__)."/vendor/autoload.php";
	require_once("SessionUtil.php");
	
	class TemplateUtil {

		private static $instance;
		private $twig;
		private static $translator = null;

		private final function  __construct() {
			$loader = new \Twig\Loader\FilesystemLoader(dirname(__DIR__)."/templates");
			$this->twig = new \Twig\Environment($loader, [
				'cache' => false,
				'auto_reload' => true,
			]);
			$this->twig->addExtension(new \Symfony\Bridge\Twig\Extension\TranslationExtension(self::getTranslator()));

			// Cache-busting: append the file's modification time as ?v= so changed
			// assets are re-fetched automatically, instead of hand-bumped version
			// strings. Assets live under htdocs/ and are referenced by their URL path
			// (e.g. asset('/css/main.css')). Missing file => return the path unversioned
			// so the asset still loads.
			$this->twig->addFunction(new \Twig\TwigFunction('asset', function ($path) {
				$file = dirname(__DIR__) . '/htdocs' . $path;
				$mtime = @filemtime($file);
				return $mtime ? $path . '?v=' . $mtime : $path;
			}));
		}

		public static function render($template, $vars = null) {
			if(!isset(self::$instance)) {
				self::$instance = new TemplateUtil();
			}
			if (!isset($vars)) $vars = array();
			$vars["session_active"] = SessionUtil::getInstance()->isSessionActive();
			$vars["curr_locale"] = SessionUtil::getInstance()->getLocale();
			$vars["curr_username"] = SessionUtil::getInstance()->getUsername();
			// So the account menu can offer the admin panel to the people who
			// have it, instead of the panel being a URL you have to know.
			$vars["curr_is_admin"] = SessionUtil::getInstance()->isAdmin();
			// Path of the page being rendered, so the side menu can tell
			// which of its entries is the current page.
			$vars["current_path"] = parse_url($_SERVER["REQUEST_URI"] ?? "/", PHP_URL_PATH) ?: "/";

			// Every form the site renders carries this, and every POST
			// handler demands it back. Injected here so no template can
			// forget it by not being handed the value.
			require_once(__DIR__."/CsrfUtil.php");
			$vars["csrf_token"] = CsrfUtil::token();
			$vars["csrf_field"] = CsrfUtil::FIELD;

			// Read from UserUtil so the criteria the forms display are the
			// same numbers the validator enforces.
			require_once(__DIR__."/UserUtil.php");
			$vars["password_min"] = UserUtil::PASSWORD_MIN_CHARS;
			$vars["password_max"] = UserUtil::PASSWORD_MAX_BYTES;

			// Mail counts are injected globally so the navigation can show
			// them on every page, not only inside the webmail. Two queries,
			// and only for a signed-in visitor.
			if ($vars["session_active"] && isset($_SESSION["user_id"])) {
				require_once(__DIR__."/MailUtil.php");
				$mail = MailUtil::getInstance();
				$vars["mail_count"] = $mail->countForUser($_SESSION["user_id"]);
				$vars["mail_new"] = $mail->countNewForUser($_SESSION["user_id"]);

				// The bell's count, for the same reason: it belongs to the
				// header, which every page renders.
				require_once(__DIR__."/NotificationUtil.php");
				$vars["notify_new"] = NotificationUtil::getInstance()->countUnread($_SESSION["user_id"]);
			} else {
				$vars["mail_count"] = 0;
				$vars["mail_new"] = 0;
				$vars["notify_new"] = 0;
			}

			// The mobile_config.bin "passport" modal: shown once, on whichever page
			// a visitor happens to land on first, until dismissed. Checked
			// globally for the same reason mail counts are -- a signed-in
			// visitor can land on any page after logging in, not just one.
			$vars["show_passport_modal"] = false;
			if ($vars["session_active"] && isset($_SESSION["user_id"])) {
				require_once(__DIR__."/DBUtil.php");
				$db = DBUtil::getInstance()->getDB();
				$stmt = $db->prepare("select passport_seen_at is null as unseen from sys_users where id = ?");
				$userId = (int)$_SESSION["user_id"];
				$stmt->bind_param("i", $userId);
				$stmt->execute();
				$row = $stmt->get_result()->fetch_assoc();
				$vars["show_passport_modal"] = $row && (int)$row["unseen"] === 1;
			}

			return self::$instance->twig->render($template.".twig", $vars);
		}

		public static function translate(?string $id, array $parameters = [], ?string $domain = null, ?string $locale = null): string {
			return self::getTranslator()->trans($id, $parameters, $domain, $locale);
		}

		public static function getTranslator() {
			if (self::$translator !== null) {
				return self::$translator;
			}

			$locale = SessionUtil::getInstance()->getLocale();
			$translator = new \Symfony\Component\Translation\Translator($locale);
			$translator->setFallbackLocales(['en']);

			if (!class_exists('\Symfony\Component\Translation\Loader\YamlFileLoader')) {
				error_log("Translation YAML loader class unavailable; locale resources cannot be loaded.");
				self::$translator = $translator;
				return self::$translator;
			}

			$translator->addLoader('yaml', new \Symfony\Component\Translation\Loader\YamlFileLoader());

			$supported_locales = ['en', 'es', 'de', 'ja', 'it', 'fr', 'pt-br'];
			foreach ($supported_locales as $l) {
				$path = dirname(__DIR__) . '/locales/' . $l . '.yml';
				if (!is_file($path)) {
					continue;
				}

				try {
					if (class_exists('\Symfony\Component\Yaml\Yaml')) {
						$raw = @file_get_contents($path);
						if (!is_string($raw) || $raw === '') {
							throw new \RuntimeException("Locale file is empty or unreadable");
						}

						$sanitized = preg_replace('/^\xEF\xBB\xBF/', '', $raw);
						$sanitized = preg_replace('/^\s*---\s*(\r?\n)/m', '', $sanitized);
						$sanitized = preg_replace('/^\s*\.\.\.\s*(\r?\n)/m', '', $sanitized);
						\Symfony\Component\Yaml\Yaml::parse($sanitized);
					}

					$translator->addResource('yaml', $path, $l);
				} catch (\Throwable $e) {
					error_log("Skipping invalid locale YAML [{$l}] at {$path}: " . $e->getMessage());
				}
			}

			self::$translator = $translator;
			return self::$translator;
		}

	}
?>
