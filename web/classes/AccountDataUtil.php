<?php
	require_once(__DIR__ . "/DBUtil.php");
	require_once(__DIR__ . "/MailStoreUtil.php");
	require_once(__DIR__ . "/ConfigUtil.php");
	require_once(__DIR__ . "/RelayUtil.php");
	require_once(__DIR__ . "/ActivityLog.php");

	// Account export and deletion share one data inventory, covering account/device rows,
	// counters, notifications, Sent copies, external delivery records, relay credentials in a
	// separate database, game tables and Dovecot Maildir. Keeping both operations here ensures
	// they cover the same account-owned records and makes omissions visible through export.
	class AccountDataUtil {

		// Account-owned tables keyed by ID: [table, column].
		const POR_ID = [
			["sys_device_authorization", "user_id"],
			["sys_device_counter",       "user_id"],
			["sys_notifications",        "user_id"],
			["sys_sent",                 "user_id"],
			["sys_email_change",         "user_id"],
			["sys_password_reset",       "user_id"],
			["sys_web_outbound_log",     "user_id"],
			["sys_reminder_log",         "user_id"],
			["amk_user_map",             "user_id"],
			["bxt_battle_tower_honor_roll", "account_id"],
			["bxt_battle_tower_records",    "account_id"],
			["bxt_battle_tower_trainers",   "account_id"],
			["bxt_exchange",             "account_id"],
			["bxt_ranking",              "account_id"],
		];

		// Some game tables identify players by address rather than account ID because the
		// cartridge does not know server IDs. bxt_exchange appears in both inventories: older
		// deposits may populate only one identity column, so deletion must cover both.
		const POR_EMAIL = [
			["amc_trades",       ["email"]],
			["amo_ranking",      ["email"]],
			["bxt_exchange",     ["email"]],
			["bxt_exchange_log", ["email_1", "email_2"]],
			["sys_signup",       ["email"]],
		];

		private static $instance;

		public static function getInstance() {
			if (self::$instance === null) self::$instance = new self();
			return self::$instance;
		}

		// Return the account as the rest of the system sees it, or NULL if it does not exist; the
		// caller chooses the response.
		private function conta($userId) {
			$db = DBUtil::getInstance()->getDB();
			$stmt = $db->prepare("select * from sys_users where id = ?");
			$id = (int)$userId;
			$stmt->bind_param("i", $id);
			$stmt->execute();
			$linhas = DBUtil::fancy_get_result($stmt);
			return count($linhas) ? $linhas[0] : null;
		}

		// Os endereços pelos quais as tabelas de jogo podem conhecer a pessoa.
		private function enderecos($conta) {
			$cfg = ConfigUtil::getInstance()->getConfig();
			return array_values(array_unique(array_filter([
				$conta["dion_email_local"] . "@" . $cfg["email_domain_dion"],
				$conta["dion_email_local"] . "@" . $cfg["email_domain"],
				$conta["username"] . "@" . $cfg["email_domain"],
				$conta["email"],
			])));
		}

		// Tolerate missing tables across schema versions. An optional table must not break account
		// export or deletion.
		private function existe($db, $tabela) {
			$stmt = $db->prepare(
				"select 1 from information_schema.tables
				 where table_schema = database() and table_name = ? limit 1");
			$stmt->bind_param("s", $tabela);
			$stmt->execute();
			return count(DBUtil::fancy_get_result($stmt)) > 0;
		}

		// EXPORT: return account data as a JSON-ready structure, excluding live device keys and
		// relay tokens. Report their presence and creation dates rather than creating additional
		// plaintext credential copies in downloadable files.
		public function export($userId) {
			$db = DBUtil::getInstance()->getDB();
			$conta = $this->conta($userId);
			if ($conta === null) return null;

			unset($conta["log_in_password"], $conta["password"], $conta["password_hash"]);

			$saida = [
				"gerado_em" => gmdate("c"),
				"conta" => $conta,
				"tabelas" => [],
				"correio" => [],
			];

			foreach (self::POR_ID as $t) {
				list($tabela, $coluna) = $t;
				if (!$this->existe($db, $tabela)) continue;
				$stmt = $db->prepare("select * from `{$tabela}` where `{$coluna}` = ?");
				$id = (int)$userId;
				$stmt->bind_param("i", $id);
				$stmt->execute();
				$linhas = DBUtil::fancy_get_result($stmt);
				foreach ($linhas as &$linha) {
					// Represent the device key by its presence, never its value.
					if (isset($linha["device_auth_key"])) {
						$linha["device_auth_key"] = "<guardada, não exportada>";
					}
				}
				unset($linha);
				if ($linhas) $saida["tabelas"][$tabela] = $linhas;
			}

			$enderecos = $this->enderecos($conta);
			foreach (self::POR_EMAIL as $t) {
				list($tabela, $colunas) = $t;
				if (!$this->existe($db, $tabela)) continue;
				foreach ($colunas as $coluna) {
					$marcas = implode(",", array_fill(0, count($enderecos), "?"));
					$stmt = $db->prepare("select * from `{$tabela}` where `{$coluna}` in ({$marcas})");
					$stmt->bind_param(str_repeat("s", count($enderecos)), ...$enderecos);
					$stmt->execute();
					$linhas = DBUtil::fancy_get_result($stmt);
					if ($linhas) $saida["tabelas"][$tabela . " (" . $coluna . ")"] = $linhas;
				}
			}

			// Relay records live in a separate database. Omit an absent section instead of suggesting
			// missing data with an empty relay_users array.
			$relay = $this->linhasRelay($userId);
			if (!empty($relay)) $saida["tabelas"]["relay_users"] = $relay;

			// Mailbox contents live outside the database.
			foreach (["INBOX", MailStoreUtil::TRASH] as $pasta) {
				$msgs = MailStoreUtil::rows($conta["dion_email_local"], $pasta, true);
				if ($msgs) $saida["correio"][$pasta] = $msgs;
			}

			return $saida;
		}

		// The optional relay has a separate database and may have no user table. Never export its
		// live token in plaintext.
		private function linhasRelay($userId, $paraApagar = false) {
			// getRelayDB returns false when this installation has no relay configuration.
			try {
				$db = RelayUtil::getInstance()->getRelayDB();
			} catch (\Throwable $e) {
				return null;
			}
			if (!$db) return null;
			$stmt = @$db->prepare($paraApagar
				? "delete from relay_users where user_id = ?"
				: "select user_id, number from relay_users where user_id = ?");
			if (!$stmt) return null;
			$id = (int)$userId;
			$stmt->bind_param("i", $id);
			if (!$stmt->execute()) return null;
			if ($paraApagar) return $stmt->affected_rows;
			return DBUtil::fancy_get_result($stmt);
		}

		// DELETE: return per-store deletion counts for the UI and audit. Remove mail first because
		// it is outside the database transaction. If Dovecot fails, retain the account so deletion
		// can be retried; deleting registration first would orphan the mailbox.
		public function erase($userId) {
			$conta = $this->conta($userId);
			if ($conta === null) return null;

			$feito = ["correio" => 0, "tabelas" => [], "conta" => 0];

			foreach (["INBOX", MailStoreUtil::TRASH] as $pasta) {
				$msgs = MailStoreUtil::rows($conta["dion_email_local"], $pasta, false);
				if (!$msgs) continue;
				$ids = array_map(function ($m) { return $m["id"]; }, $msgs);
				$feito["correio"] += MailStoreUtil::purge($conta["dion_email_local"], $ids);
			}

			$db = DBUtil::getInstance()->getDB();
			$enderecos = $this->enderecos($conta);

			foreach (self::POR_EMAIL as $t) {
				list($tabela, $colunas) = $t;
				if (!$this->existe($db, $tabela)) continue;
				foreach ($colunas as $coluna) {
					$marcas = implode(",", array_fill(0, count($enderecos), "?"));
					$stmt = $db->prepare("delete from `{$tabela}` where `{$coluna}` in ({$marcas})");
					$stmt->bind_param(str_repeat("s", count($enderecos)), ...$enderecos);
					$stmt->execute();
					if ($stmt->affected_rows > 0) {
						$chave = $tabela . " (" . $coluna . ")";
						$feito["tabelas"][$chave] = ($feito["tabelas"][$chave] ?? 0) + $stmt->affected_rows;
					}
				}
			}

			foreach (self::POR_ID as $t) {
				list($tabela, $coluna) = $t;
				if (!$this->existe($db, $tabela)) continue;
				$stmt = $db->prepare("delete from `{$tabela}` where `{$coluna}` = ?");
				$id = (int)$userId;
				$stmt->bind_param("i", $id);
				$stmt->execute();
				if ($stmt->affected_rows > 0) {
					$feito["tabelas"][$tabela] = ($feito["tabelas"][$tabela] ?? 0) + $stmt->affected_rows;
				}
			}

			$n = $this->linhasRelay($userId, true);
			if ($n) $feito["tabelas"]["relay_users"] = $n;

			// The address block, BEFORE the account row is deleted: this is
			// the last chance to have the e-mail in hand. After the delete
			// there is nowhere left to get it from, and a block that
			// depended on reading the deleted account would never happen.
			//
			// Only the hash is stored -- see the sys_email_block migration
			// for why. And a failure here does not stop the deletion: if the
			// block does not get written, the person can sign up again
			// before the window ends, which is a nuisance; aborting the
			// deletion over that would deny a right for the sake of a
			// convenience.
			if (!empty($conta["email"])) {
				require_once(__DIR__ . "/UserUtil.php");
				$feito["email_bloqueado"] = UserUtil::blockEmailAfterDeletion($conta["email"]);
			}

			// Delete registration last so any remaining data retains an owner and can be removed on
			// retry.
			$stmt = $db->prepare("delete from sys_users where id = ?");
			$id = (int)$userId;
			$stmt->bind_param("i", $id);
			$stmt->execute();
			$feito["conta"] = $stmt->affected_rows;
			// The id of an account that no longer exists: nothing left to identify.
			ActivityLog::record("account-deleted", ["account" => $id, "removed" => (int)$feito["conta"]]);

			return $feito;
		}
	}
