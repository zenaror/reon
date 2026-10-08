<?php
	include("../classes/DBUtil.php");
	include("../classes/UserUtil.php");

    function main() {
        $db = DBUtil::getInstance()->getDB();
        $user = UserUtil::getInstance();

        $email = prompt("Email: ");
        $password = prompt("Password: ");
        $passwordConfirm = prompt("Confirm Password: ");
        $reonEmail = prompt("Username (3-20 lowercase letters or numbers): ");

        if (!isEmailAvailable($db, $email)) {
            exit("Email is unavailable");
        }

        $result = $user->createUser($email, $reonEmail, $password, $passwordConfirm);

        $detail = match ($result) {
            0 => "Account created!",
            1 => "Invalid email",
            2 => "Username is invalid, reserved or unavailable",
            3 => "Passwords do not match",
            4 => "Password does not meet minimum requirements",
            default => "Account could not be created (code ".$result.")",
        };

        echo $detail."\n";
        exit($result);
    }

    function prompt($s) {
        echo $s;
        return rtrim(fgets(STDIN));
    }

    function isEmailAvailable($db, $email) {
        $stmt = $db->prepare("select id from sys_users where email = ?");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        if (isset($row)) return false;
        return true;
    }

    main();