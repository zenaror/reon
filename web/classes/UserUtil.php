<?php
	use PHPMailer\PHPMailer\PHPMailer;
	use PHPMailer\PHPMailer\SMTP;
	use PHPMailer\PHPMailer\Exception;

	require_once dirname(__DIR__)."/vendor/autoload.php";
	require_once("DBUtil.php");
	require_once("ConfigUtil.php");
	require_once("TemplateUtil.php");
	
	class UserUtil {

		private static $instance;

		private final function  __construct() {
		}

		public static function getInstance() {
			if(!isset(self::$instance)) {
				self::$instance = new UserUtil();
			}
			return self::$instance;
		}
		
		public function registerUser($email, $username, $password, $passwordConfirm) {
			
		}
		
		public function changePassword($password, $newPassword, $newPasswordConfirm) {
			if ($newPassword != $newPasswordConfirm) return 3;
			if (!self::$instance->verifyPassword($password)) return 1;
			
			if (!self::$instance->setPassword($_SESSION["user_id"], $newPassword)) return 2;
			
			return 0;
		}
		
		private function verifyPassword($password) {
			$db = DBUtil::getInstance()->getDB();
			$stmt = $db->prepare("select password from sys_users where id = ?");
			$stmt->bind_param("i", $_SESSION["user_id"]);
			$stmt->execute();
			$passwordDb = $stmt->get_result()->fetch_assoc()["password"];
			if (password_verify($password, $passwordDb)) return true;
			return false;
		}
		
		// Minutes between two e-mails to the same address, for both the reset
		// and the signup flows. It exists to stop someone hammering the form
		// and burying the recipient, not to stop them registering: past the
		// window a fresh link is always sent.
		const EMAIL_THROTTLE_MINUTES = 15;

		// The password rules, in one place. The signup, reset and change forms
		// read these to draw the criteria list, so what the page promises and
		// what this function enforces cannot drift apart.
		//
		// The upper bound is bcrypt's: it truncates at 72 bytes, so accepting
		// more would be pretending the extra characters count for something.
		const PASSWORD_MIN_CHARS = 8;
		const PASSWORD_MAX_BYTES = 71;

		private function validatePasswordConstraints($password) {
			if (mb_strlen($password, "UTF-8") < self::PASSWORD_MIN_CHARS) return false;
			if (strlen($password) > self::PASSWORD_MAX_BYTES) return false;
			return true;
		}
		
		private function setPassword($id, $password) {
			if (!self::$instance->validatePasswordConstraints($password)) return false;
			
			$password_hash = self::$instance->getPasswordHash($password);
			$db = DBUtil::getInstance()->getDB();
			$stmt = $db->prepare("update sys_users set password = ? where id = ?");
			$stmt->bind_param("si", $password_hash, $id);
			$stmt->execute();
			
			return true;
		}
		
		private function getPasswordHash($password) {
			return password_hash($password, PASSWORD_DEFAULT);
		}
		
		public function requestEmailChangeAction($password, $newEmail) {
			if (!self::$instance->validateEmail($newEmail)) return 2;
			if (!self::$instance->verifyPassword($password)) return 1;
			
			$db = DBUtil::getInstance()->getDB();
			$stmt = $db->prepare("select count(*) from sys_users where email = ?");
			$stmt->bind_param("s", $newEmail);
			$stmt->execute();
			if ($stmt->get_result()->fetch_assoc()["count(*)"] != 0) return 0;
			
			$key = base64_encode(random_bytes(36));
			$stmt = $db->prepare("replace into sys_email_change (user_id, new_email, secret) values (?,?,?)");
			$stmt->bind_param("iss", $_SESSION["user_id"], $newEmail, $key);
			$stmt->execute();
			
			self::$instance->sendConfirmationEmail($_SESSION["user_id"], $key, $newEmail);
			
			return 0;
		}
		
		public function confirmEmailChangeAction($id, $key) {
			$db = DBUtil::getInstance()->getDB();
			$stmt = $db->prepare("select new_email from sys_email_change where user_id = ? and secret = ? and time > date_sub(now(), interval 1 day)");
			$stmt->bind_param("is", $id, $key);
			$stmt->execute();
			$new_email = $stmt->get_result()->fetch_assoc()["new_email"];
			if (!isset($new_email)) return 1;
			
			$stmt = $db->prepare("delete from sys_email_change where user_id = ? and secret = ?");
			$stmt->bind_param("is", $id, $key);
			$stmt->execute();
			
			$stmt = $db->prepare("update sys_users set email = ? where id = ?");
			$stmt->bind_param("si", $new_email, $id);
			$stmt->execute();
			
			return 0;
		}
		
		private function validateEmail($email) {
			return filter_var($email, FILTER_VALIDATE_EMAIL, FILTER_FLAG_EMAIL_UNICODE);
		}
		
		private function sendConfirmationEmail($id, $key, $email) {
			$hostname = ConfigUtil::getInstance()->getConfig()["hostname"];
			$email_domain = ConfigUtil::getInstance()->getConfig()["email_domain"];
			$from = "noreply@".$email_domain;
			$subject = "REON email address confirmation";
			$message = TemplateUtil::render("/email/confirmation_email", [
				"hostname" => $hostname,
				"id" => $id,
				"key" => urlencode($key)
			]);
			
			self::$instance->sendUtf8Email($email, $from, $subject, $message);
			
			return 0;
		}
		
		// $template and $subject are overridable so the signup form can reuse
		// this whole flow -- lookup, rate limit, token, send -- to tell someone
		// their address is already registered, without a second copy of it.
		public function sendPasswordResetEmail($email, $template = "/email/forgot_password_email", $subject = "REON account password reset") {
			$db = DBUtil::getInstance()->getDB();
			
			$stmt = $db->prepare("select id from sys_users where email = ?");
			$stmt->bind_param("s", $email);
			$stmt->execute();
			$row = $stmt->get_result()->fetch_assoc();
			if (!isset($row)) return 1;
			$user_id = $row["id"];
			
			// The column is "timestamp"; this said "time", so the query threw
			// and password reset failed for everyone, every time.
			$stmt = $db->prepare("select count(*) from sys_password_reset where user_id = ? and timestamp > date_sub(now(), interval " . self::EMAIL_THROTTLE_MINUTES . " minute)");
			$stmt->bind_param("i", $user_id);
			$stmt->execute();
			if ($stmt->get_result()->fetch_assoc()["count(*)"] > 0) return 2;
			
			$key = base64_encode(random_bytes(36));
			$stmt = $db->prepare("replace into sys_password_reset (user_id, secret) values (?, ?)");
			$stmt->bind_param("is", $user_id, $key);
			$stmt->execute();
			
			$hostname = ConfigUtil::getInstance()->getConfig()["hostname"];
			$email_domain = ConfigUtil::getInstance()->getConfig()["email_domain"];
			$from = "noreply@".$email_domain;
			$message = TemplateUtil::render($template, [
				"hostname" => $hostname,
				"id" => $user_id,
				"key" => urlencode($key)
			]);
			
			self::$instance->sendUtf8Email($email, $from, $subject, $message);
			
			return 0;
		}
		
		private function sendUtf8Email($to, $from, $subject, $message) {
			$mail = new PHPMailer();
			$mail->CharSet = PHPMailer::CHARSET_UTF8;
			$mail->Encoding = PHPMailer::ENCODING_QUOTED_PRINTABLE;

			$smtp_host = ConfigUtil::getInstance()->getConfig()["smtp_host"];
			if (!isset($smtp_host) || $smtp_host == "") {
				$mail->isSendmail();
			} else {
				$mail->isSMTP();
				$mail->Host = $smtp_host;
				$mail->Port = ConfigUtil::getInstance()->getConfig()["smtp_port"];
				$mail->SMTPAuth = ConfigUtil::getInstance()->getConfig()["smtp_auth"];
				if ($mail->SMTPAuth) {
					$mail->Username = ConfigUtil::getInstance()->getConfig()["smtp_user"];
					$mail->Password = ConfigUtil::getInstance()->getConfig()["smtp_pass"];
				}
				switch (ConfigUtil::getInstance()->getConfig()["smtp_secure"]) {
					case 'smtps': $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS; break;
					case 'starttls': $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS; break;
				}
			}

			$mail->setFrom($from);
			$mail->addAddress($to);
			$mail->Subject = $subject;
			$mail->msgHTML($message);
			$mail->send();
		}
		
		public function resetPassword($id, $key, $password, $passwordConfirm) {
			$verify_success = self::$instance->verifyResetPassword($id, $key);
			if ($verify_success != 0) return 1;
			if ($password != $passwordConfirm) return 2;
			
			if (!self::$instance->setPassword($id, $password)) return 3;
			
			$stmt = $db->prepare("delete from sys_password_reset where user_id = ? and secret = ?");
			$stmt->bind_param("is", $id, $key);
			$stmt->execute();
			
			return 4;
		}
		
		public function verifyResetPassword($id, $key) {
			$db = DBUtil::getInstance()->getDB();
			$stmt = $db->prepare("select count(*) from sys_password_reset where user_id = ? and secret = ? and timestamp > date_sub(now(), interval 1 day)");
			$stmt->bind_param("is", $id, $key);
			$stmt->execute();
			if ($stmt->get_result()->fetch_assoc()["count(*)"] == 0) return 1;
			
			return 0;
		}
		
		public function rerollLoginPassword() {
			$new_password = self::$instance->generateLogInPassword();
			$db = DBUtil::getInstance()->getDB();
			$stmt = $db->prepare("update sys_users set log_in_password = ? where id = ?");
			$stmt->bind_param("si", $new_password, $_SESSION["user_id"]);
			$stmt->execute();
		}
		
		private function generateLogInPassword() {
			$allowed_chars = "0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ";
			$password = "";
			for ($i = 0; $i < 8; $i++) {
				$password .= $allowed_chars[random_int(0, strlen($allowed_chars) - 1)];
			}
			if (trim($password, substr($allowed_chars, 0, 10)) === "") {
				// all digits, add a random letter.
				// this has less than a .00005% chance of happening, but hey.
				$password[random_int(0, 7)] = $allowed_chars[random_int(10, strlen($allowed_chars) - 1)];
			} elseif (trim($password, substr($allowed_chars, 10)) === "") {
				// all letters, add a random digit.
				// this has nearly a 24.5% chance of happening.
				$password[random_int(0, 7)] = $allowed_chars[random_int(0, 9)];
			}
			return $password;
		}
		
		public function sendSignupEmailAction($policiesAccepted, $email) {
			if (!isset($policiesAccepted)) return 1;
			if (!self::$instance->validateEmail($email)) return 2;
			
			$db = DBUtil::getInstance()->getDB();
			
			// Already registered. Previously this returned silently, so the
			// page said "we sent you an email" and nothing arrived -- someone
			// who had forgotten they had an account had no way to find out.
			//
			// Sending in both cases keeps the page's answer uninformative to
			// anyone probing for addresses, since it is the same either way,
			// while the person who actually owns the address always gets
			// something. The reset flow's own five-minute limit applies here
			// too, so this cannot be used to flood an inbox.
			$stmt = $db->prepare("select id from sys_users where email = ?");
			$stmt->bind_param("s", $email);
			$stmt->execute();
			$row = $stmt->get_result()->fetch_assoc();
			if (isset($row)) {
				self::$instance->sendPasswordResetEmail(
					$email,
					"/email/signup_existing_account",
					"REON account already exists"
				);
				return 0;
			}

			$stmt = $db->prepare("select count(*) from sys_signup where email = ? and timestamp > date_sub(now(), interval " . self::EMAIL_THROTTLE_MINUTES . " minute)");
			$stmt->bind_param("s", $email);
			$stmt->execute();
			if ($stmt->get_result()->fetch_assoc()["count(*)"] > 0) return 0;
			
			$key = base64_encode(random_bytes(36));

			// A registration already started for this address gets its row
			// refreshed rather than a second one alongside it. One live link
			// per address: issuing a new one retires the old, so a mailbox
			// with several of these never leaves the reader guessing which
			// still works.
			$stmt = $db->prepare("select id from sys_signup where email = ? order by id desc limit 1");
			$stmt->bind_param("s", $email);
			$stmt->execute();
			$pending = $stmt->get_result()->fetch_assoc();

			if ($pending) {
				$signup_id = (int)$pending["id"];
				$stmt = $db->prepare("update sys_signup set secret = ?, timestamp = now() where id = ?");
				$stmt->bind_param("si", $key, $signup_id);
				$stmt->execute();
				$template = "/email/signup_pending";
				$subject = "REON registration still pending";
			} else {
				$stmt = $db->prepare("insert into sys_signup (email, secret) values (?, ?)");
				$stmt->bind_param("ss", $email, $key);
				$stmt->execute();
				$signup_id = $db->insert_id;
				$template = "/email/signup";
				$subject = "REON Sign-up Request";
			}

			$hostname = ConfigUtil::getInstance()->getConfig()["hostname"];
			$email_domain = ConfigUtil::getInstance()->getConfig()["email_domain"];
			$from = "noreply@".$email_domain;
			$message = TemplateUtil::render($template, [
				"hostname" => $hostname,
				"id" => $signup_id,
				"key" => urlencode($key)
			]);
			
			self::$instance->sendUtf8Email($email, $from, $subject, $message);
			
			return 0;
		}
		
		public function verifySignupRequest($id, $key) {
			$db = DBUtil::getInstance()->getDB();
			$stmt = $db->prepare("select email from sys_signup where id = ? and secret = ? and timestamp > date_sub(now(), interval 1 day)");
			$stmt->bind_param("ss", $id, $key);
			$stmt->execute();
			$email = $stmt->get_result()->fetch_assoc()["email"];
			//if (!isset($email) return 1;
			
			return $email;
		}
		
		public function completeSignupAction($id, $key, $reonEmail, $password, $passwordConfirm, $tradeRegions, $customPokemonNewsOptIn) {
			$email = self::$instance->verifySignupRequest($id, $key);

			$result = self::$instance->createUser($email, $reonEmail, $password, $passwordConfirm, $tradeRegions, $customPokemonNewsOptIn);
			if ($result > 0) {
				return $result;
			}
            
            $db = DBUtil::getInstance()->getDB();
			$stmt = $db->prepare("delete from sys_signup where email = ?");
			$stmt->bind_param("s", $email);
			$stmt->execute();

			// Sent last, and its failure does not fail the signup: the account
			// exists either way, and refusing a finished registration because
			// a courtesy e-mail bounced would be absurd.
			self::$instance->sendWelcomeEmail($email, $reonEmail);

			return 0;
		}

		// Confirms the registration landed and, more usefully, tells the person
		// the two addresses their account answers on -- the pair is the one
		// thing they need later and the one thing the signup form shows only
		// while they are filling it in.
		private function sendWelcomeEmail($email, $username) {
			$cfg = ConfigUtil::getInstance()->getConfig();

			$db = DBUtil::getInstance()->getDB();
			$stmt = $db->prepare("select dion_email_local, dion_ppp_id, log_in_password from sys_users where username = ? limit 1");
			$stmt->bind_param("s", $username);
			$stmt->execute();
			$row = $stmt->get_result()->fetch_assoc();
			if (!$row) return;

			$message = TemplateUtil::render("/email/welcome", [
				"hostname" => $cfg["hostname"],
				"username" => $username,
				"dion_email" => $row["dion_email_local"]."@".$cfg["email_domain_dion"],
				"external_email" => $username."@".$cfg["email_domain"],
				"external_email_short" => $row["dion_email_local"]."@".$cfg["email_domain"],
				"dion_id" => $row["dion_ppp_id"],
			]);

			self::$instance->sendUtf8Email($email, "noreply@".$cfg["email_domain"], "Welcome to REON", $message);
		}

		// $username is the name the person picked (up to 20 characters). The
		// 8-character in-game form is derived from it here rather than being
		// chosen, so the person never has to think about the adapter's limit.
		public function createUser($email, $username, $password, $passwordConfirm, $tradeRegions = "e,f,d,s,i,p,u,j", $customPokemonNewsOptIn = 0) {
			if (!isset($email)) return 1;
			if (!self::$instance->isUsernameValidAndFree($username)) return 2;
			if ($password != $passwordConfirm) return 3;
			if (!self::$instance->validatePasswordConstraints($password)) return 4;
   
            if (!in_array($tradeRegions,array("e,f,d,s,i,p,u,j","efdsipu,j","efdsipuj")))
                $tradeRegions = "e,f,d,s,i,p,u,j";
			
			$opt_in = ($customPokemonNewsOptIn == 1) ? 1 : 0;

			$password_hash = self::$instance->getPasswordHash($password);
			$dion_ppp_id = self::$instance->generatePPPId();
			$log_in_password = self::$instance->generateLogInPassword();
			$db = DBUtil::getInstance()->getDB();

			// Every game-facing lookup (POP3 login, relay policy, Mario Kart)
			// keys off dion_email_local, so it has to be unique on its own even
			// though nobody picks it directly.
			$dion_email_local = self::$instance->deriveDionLocal($username);
			if ($dion_email_local === "") return 2;

			$stmt = $db->prepare("insert into sys_users (email, username, password, dion_ppp_id, dion_email_local, log_in_password, money_spent, trade_region_allowlist, custom_pokemon_news_opt_in) values (?,?,?,?,?,?,0,?,?)");
			$stmt->bind_param("sssssssi", $email, $username, $password_hash, $dion_ppp_id, $dion_email_local, $log_in_password, $tradeRegions, $opt_in);
			$stmt->execute();

			require_once("RelayUtil.php");
			RelayUtil::getInstance()->provisionForUser($db->insert_id);

			return 0;
		}
		
		const USERNAME_MIN = 3;
		const USERNAME_MAX = 20;
		const DION_LOCAL_LEN = 8;

		// The name the person actually picks and is known by. Free-form within
		// [a-z0-9], unlike dion_email_local which is pinned to 8 characters by
		// the adapter's fixed-width EEPROM field.
		public function isUsernameValidAndFree($username) {
			$len = strlen($username);
			if ($len < self::USERNAME_MIN || $len > self::USERNAME_MAX) return false;
			if (!preg_match("/^[a-z0-9]+$/", $username)) return false;

			$db = DBUtil::getInstance()->getDB();
			$stmt = $db->prepare("select count(*) from sys_users where username = ?");
			$stmt->bind_param("s", $username);
			$stmt->execute();
			return $stmt->get_result()->fetch_assoc()["count(*)"] == 0;
		}

		// Derives the 8-character form the games use from a username. First
		// come, first served: the first person whose name starts with "darkshad"
		// keeps that as their in-game address, and later arrivals get trailing
		// digits substituted in ("darksha1", then "darksh10" once single digits
		// run out) so nobody is ever turned away over a prefix they didn't pick.
		//
		// Returns "" only if even the numbered variants are exhausted.
		public function deriveDionLocal($username) {
			$base = str_pad(substr($username, 0, self::DION_LOCAL_LEN), self::DION_LOCAL_LEN, "0");
			if ($this->isDionEmailValidAndFree($base)) return $base;

			for ($digits = 1; $digits < self::DION_LOCAL_LEN; $digits++) {
				$stem = substr($base, 0, self::DION_LOCAL_LEN - $digits);
				$limit = (int)str_repeat("9", $digits);
				for ($n = 1; $n <= $limit; $n++) {
					$candidate = $stem . str_pad((string)$n, $digits, "0", STR_PAD_LEFT);
					if ($this->isDionEmailValidAndFree($candidate)) return $candidate;
				}
			}
			return "";
		}

		// Suggests a username derived from the address the person signed up
		// with, as a starting point they can overwrite. Only the full name
		// needs to be free here -- the 8-character in-game form is derived by
		// deriveDionLocal(), which resolves its own collisions.
		//
		// Never returns blank: the pre-filled value is what teaches the format.
		public function suggestUsername($email) {
			$local = strstr((string)$email, "@", true);
			if ($local === false) $local = (string)$email;

			$base = preg_replace("/[^a-z0-9]/", "", strtolower($local));
			if ($base === "") $base = "reon";
			$base = substr($base, 0, self::USERNAME_MAX);
			if (strlen($base) < self::USERNAME_MIN) {
				$base = str_pad($base, self::USERNAME_MIN, "0");
			}

			if ($this->isUsernameValidAndFree($base)) return $base;

			for ($n = 1; $n <= 999; $n++) {
				$suffix = (string)$n;
				$stem = substr($base, 0, self::USERNAME_MAX - strlen($suffix));
				$candidate = $stem . $suffix;
				if ($this->isUsernameValidAndFree($candidate)) return $candidate;
			}

			return "reon" . random_int(100000, 999999);
		}

		// Public wrapper so the signup page can check a name as it's typed,
		// against the same rule the submit path enforces -- the live answer and
		// the final answer can't disagree.
		public function isUsernameAvailable($username) {
			return $this->isUsernameValidAndFree($username);
		}

		private function isDionEmailValidAndFree($email_local) {
			if (strlen($email_local) != 8) return false;
			if (!preg_match("/^[a-z0-9]+$/", $email_local)) return false;
			
			$db = DBUtil::getInstance()->getDB();
			$stmt = $db->prepare("select count(*) from sys_users where dion_email_local = ?");
			$stmt->bind_param("s", $email_local);
			$stmt->execute();
			if ($stmt->get_result()->fetch_assoc()["count(*)"] != 0) return false;
			
			return true;
		}
		
		private function generatePPPId() {
			$allowed_chars = "0123456789";
			do {
				$ppp_id = "g";
				for ($i = 0; $i < 9; $i++) {
					$ppp_id .= $allowed_chars[random_int(0, strlen($allowed_chars) - 1)];
				}
				
				$db = DBUtil::getInstance()->getDB();
				$stmt = $db->prepare("select count(*) from sys_users where dion_ppp_id = ?");
				$stmt->bind_param("s", $ppp_id);
				$stmt->execute();
				$ppp_id_free = $stmt->get_result()->fetch_assoc()["count(*)"] == 0;
			} while (!$ppp_id_free);
			
			return $ppp_id;
		}
	}
?>
