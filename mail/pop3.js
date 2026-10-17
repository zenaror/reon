// LEGACY: REON's own POP3 server. Off since 2026-09-12 (config.json `disable_pop3`):
// Dovecot answers port 110 now (examples/dovecot/). Kept for reference and for
// the development environments that still run it; do not enable it next to Dovecot.
const net = require("net");
const mysql = require("mysql2");
const POP3Connection = require("./pop3Connection").POP3Connection;
const { createStore } = require("./mailStore");
const log = require("../lib/log").child("mail-pop3");

class POP3Server {
	constructor(mysqlConfig, emailDomain, emailDomainDion, config) {
		this.connections = new Set();
		this.mysql = mysql.createPool(mysqlConfig);
		// Production mail lives in Dovecot. sys_inbox was removed on 2026-09-12. MySQL retains
		// account registration and device authorization keys, not production mailbox contents.
		this.store = createStore(config || {}, this.mysql);
		log.info("POP3 storage backend: " + this.store.name);
		// Mail sent from one of our own domains was written for these games
		// and is handed over untouched; see POP3Connection#_getMail.
		this.internalDomains = [emailDomain, emailDomainDion]
			.filter(Boolean).map(domain => String(domain).toLowerCase());
		// Enabled when the Dovecot delivery filter already shapes mail.
		// This optional POP3 reader then serves the stored bytes unchanged,
		// matching the production Dovecot reader on port 110.
		this.shapedAtDelivery = (config || {})["shaped_at_delivery"] === true;
		if (this.shapedAtDelivery) log.info("POP3: tratativas ja aplicadas na entrega");
		net.createServer(sock => this._onClientConnect(sock)).listen(110, "0.0.0.0");
		log.info("POP3 server listening");
	}
	
	_onClientConnect(socket) {
		log.debug("(POP3) CONNECTED: " + socket.remoteAddress + ":" + socket.remotePort);
		let conn = new POP3Connection(this, socket);
		conn.on("disconnect", (connection, ip, port) => this._onClientDisconnect(connection, ip, port));
		conn.on("command", (command, user, ip, port) => this._onClientCommand(command, user, ip, port));
		conn.on("error", error => this._onClientError(error));
		this.connections.add(conn);
	}
	
	_onClientDisconnect(connection, ip, port) {
		log.debug("(POP3) DISCONNECTED: " + ip + ":" + port);
		this.connections.delete(connection);
	}
	
	_onClientCommand(command, user, ip, port) {
		log.debug("(POP3) " + ip + ":" + port + (user == null ? "" : " (" + user + ")") + ": " + command);
	}
	
	_onClientError(error) {
		log.warn("(POP3) " + error);
	}
}
module.exports.POP3Server = POP3Server;
