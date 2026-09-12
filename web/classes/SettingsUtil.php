<?php
	require_once("DBUtil.php");

	// Configurações do servidor que quem administra muda pelo painel, sem
	// editar arquivo e sem reiniciar serviço.
	//
	// Ficam no banco e não em arquivo de configuração por um motivo prático: o
	// Dovecot lê a mesma linha dentro da própria consulta de autenticação, e o
	// nosso POP3 lê na hora do login. Um interruptor no painel vale na próxima
	// conexão, nos dois, sem ninguém recarregar nada.
	class SettingsUtil {

		// Aceita só o que existe. A chave vem de formulário, e uma lista
		// fechada é o que impede alguém de inventar nome e gravar linha solta.
		const KNOWN = [
			// Se o servidor ainda aceita USER/PASS com a senha de oito
			// caracteres, ou se exige APOP/XAPOP. Ver examples/dovecot/.
			"pop3_password_fallback" => "1",
		];

		private static $instance = null;
		public static function getInstance() {
			if (self::$instance === null) self::$instance = new SettingsUtil();
			return self::$instance;
		}

		private $cache = [];

		public static function isKnown($name) {
			return array_key_exists((string)$name, self::KNOWN);
		}

		public function get($name) {
			if (!self::isKnown($name)) return null;
			if (array_key_exists($name, $this->cache)) return $this->cache[$name];

			$db = DBUtil::getInstance()->getDB();
			$stmt = $db->prepare("select value from sys_settings where name = ? limit 1");
			$stmt->bind_param("s", $name);
			$stmt->execute();
			$row = $stmt->get_result()->fetch_assoc();
			// Sem linha vale o padrão: uma tabela vazia não pode significar
			// "tudo desligado", que aqui seria deixar gente sem correio.
			return $this->cache[$name] = ($row ? $row["value"] : self::KNOWN[$name]);
		}

		public function isOn($name) {
			return $this->get($name) === "1";
		}

		public function set($name, $value) {
			if (!self::isKnown($name)) return false;
			$value = (string)$value;

			$db = DBUtil::getInstance()->getDB();
			$stmt = $db->prepare(
				"insert into sys_settings (name, value) values (?, ?)
				 on duplicate key update value = values(value)"
			);
			$stmt->bind_param("ss", $name, $value);
			$ok = $stmt->execute();
			if ($ok) $this->cache[$name] = $value;
			return $ok;
		}
	}
?>
