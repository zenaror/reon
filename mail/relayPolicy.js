const net = require("net");
const fs = require("fs");
const mysql = require("mysql2");
const { Command } = require("commander");

// Postfix policy delegation service (see SMTPD_POLICY_README) that gates
// outbound relay to real internet addresses on the device-auth "authorized"
// flag (see DeviceAuthUtil.php / sys_device_authorization), so the game can
// send to a real address only while its device is authorized -- and every
// other case (game-to-game mail, an unauthorized/unknown sender) falls
// through unchanged to Postfix's own reject_unauth_destination, exactly as
// before this existed. Wired into smtpd_relay_restrictions by
// setup-postfix-bridge.sh, positioned before reject_unauth_destination.
//
// Never answers anything but DUNNO/OK: DUNNO defers to the restrictions
// already in place (the safe default for absolutely anything unexpected --
// bad request, DB error, unknown sender, no active authorization), OK is
// the one case that actually grants the relay. There is deliberately no
// REJECT here -- this service only ever loosens, never tightens, what
// Postfix would otherwise decide.
class RelayPolicyServer {
	constructor(mysqlConfig, port) {
		this.mysql = mysql.createPool(mysqlConfig);
		net.createServer(sock => this._onClientConnect(sock)).listen(port, "127.0.0.1");
		console.log("Relay policy service listening on 127.0.0.1:" + port);
	}

	_onClientConnect(sock) {
		let buffer = "";
		sock.on("data", data => {
			buffer += data;
			// A request ends with a blank line ("\n\n" once CRLF/LF is
			// normalized) -- Postfix reuses the connection, so keep
			// consuming complete requests as they arrive.
			let sep;
			while ((sep = buffer.indexOf("\n\n")) !== -1) {
				let raw = buffer.slice(0, sep);
				buffer = buffer.slice(sep + 2);
				this._handleRequest(raw, sock);
			}
		});
		sock.on("error", () => {});
	}

	_handleRequest(raw, sock) {
		let attrs = {};
		for (let line of raw.split("\n")) {
			let eq = line.indexOf("=");
			if (eq === -1) continue;
			attrs[line.slice(0, eq)] = line.slice(eq + 1);
		}

		this._decide(attrs, action => {
			if (sock.writable) sock.write("action=" + action + "\n\n");
		});
	}

	_decide(attrs, callback) {
		let recipient = attrs["recipient"] || "";
		let sender = attrs["sender"] || "";
		let recipientLocal = recipient.split("@")[0];
		let senderLocal = sender.split("@")[0];

		if (!recipientLocal || !senderLocal) {
			callback("DUNNO");
			return;
		}

		// A recipient sys_users already knows about is game-to-game mail,
		// whatever domain it arrived addressed to -- not this service's
		// concern, leave it to the restrictions already handling that.
		this.mysql.query("select 1 from sys_users where dion_email_local = ? limit 1", [recipientLocal], (error, results) => {
			if (error) {
				console.error("relayPolicy: recipient lookup failed:", error.message);
				callback("DUNNO");
				return;
			}
			if (results.length > 0) {
				callback("DUNNO");
				return;
			}

			// Recipient isn't one of ours: this is the actual outbound-
			// relay case. Only grant it if the sender is both a known
			// account and currently authorized.
			this.mysql.query(
				"select a.id from sys_users u inner join sys_device_authorization a on a.user_id = u.id where u.dion_email_local = ? and a.authorized = 1 and a.authorized_until > now() limit 1",
				[senderLocal],
				(error, results) => {
					if (error) {
						console.error("relayPolicy: authorization lookup failed:", error.message);
						callback("DUNNO");
						return;
					}
					callback(results.length > 0 ? "OK" : "DUNNO");
				}
			);
		});
	}
}

module.exports.RelayPolicyServer = RelayPolicyServer;

if (require.main === module) {
	const program = new Command();
	program
		.option("-c, --config <path>", "Config file path.", "../config.json")
		.option("-p, --port <port>", "Port to listen on.", "10045")
		.parse(process.argv);

	const options = program.opts();
	const config = JSON.parse(fs.readFileSync(options.config));
	const mysqlConfig = {
		host: config["mysql_host"],
		user: config["mysql_user"],
		password: config["mysql_password"],
		database: config["mysql_database"]
	};

	new RelayPolicyServer(mysqlConfig, parseInt(options.port, 10));
}
