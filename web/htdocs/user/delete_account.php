<?php
	require_once("../../classes/TemplateUtil.php");
	require_once("../../classes/DBUtil.php");
	require_once("../../classes/SessionUtil.php");
	require_once("../../classes/CsrfUtil.php");
	require_once("../../classes/AccountDataUtil.php");
	session_start();

	// Apagar a conta. Sem volta, e a tela diz isso antes de qualquer botão.
	//
	// Três exigências, e cada uma existe por um motivo diferente:
	//
	//   - token anti-CSRF, como todo POST do site: sem ele, um link em outro
	//     lugar poderia apagar a conta de quem estivesse com a sessão aberta;
	//   - a senha, digitada agora: sessão aberta em máquina compartilhada é
	//     comum, e ninguém deve conseguir apagar uma conta só por achar o
	//     computador destravado;
	//   - o nome da conta, digitado à mão: é o degrau contra o clique no
	//     automático. Senha a pessoa digita de olhos fechados; o próprio nome
	//     ela só digita se estiver lendo a tela.
	if (!SessionUtil::getInstance()->isSessionActive()) {
		header("Location: /index.php");
		exit;
	}

	$db = DBUtil::getInstance()->getDB();
	$stmt = $db->prepare("select username, password from sys_users where id = ?");
	$stmt->bind_param("i", $_SESSION["user_id"]);
	$stmt->execute();
	$conta = DBUtil::fancy_get_result($stmt);
	if (!count($conta)) {
		header("Location: /index.php");
		exit;
	}
	$conta = $conta[0];

	$erros = [];

	if ($_SERVER["REQUEST_METHOD"] === "POST") {
		CsrfUtil::check();

		if (!password_verify((string)($_POST["password"] ?? ""), $conta["password"])) {
			$erros[] = "senha";
		}
		// Comparação exata: "Rafael00" não é "rafael00". Aceitar variação
		// tiraria justamente o que o degrau serve para provar, que é que a
		// pessoa leu o que está escrito.
		if ((string)($_POST["confirmName"] ?? "") !== (string)$conta["username"]) {
			$erros[] = "nome";
		}

		if (!$erros) {
			$feito = AccountDataUtil::getInstance()->erase($_SESSION["user_id"]);
			if ($feito === null || (int)$feito["conta"] === 0) {
				$erros[] = "falhou";
			} else {
				// A sessão morre junto: o id dela aponta para uma conta que
				// não existe mais, e qualquer página que o leia depois disso
				// falharia de um jeito difícil de entender.
				$_SESSION = [];
				if (ini_get("session.use_cookies")) {
					$p = session_get_cookie_params();
					setcookie(session_name(), "", time() - 42000,
						$p["path"], $p["domain"], $p["secure"], $p["httponly"]);
				}
				session_destroy();
				header("Location: /index.php?deleted=1");
				exit;
			}
		}
	}

	echo TemplateUtil::render("/user/delete_account", [
		"username" => $conta["username"],
		"errors" => $erros,
	]);
