const net = require("net");
const mysql = require("mysql2");
const POP3Connection = require("./pop3Connection").POP3Connection;
const { createStore } = require("./mailStore");

class POP3Server {
	constructor(mysqlConfig, emailDomain, emailDomainDion, config) {
		this.connections = new Set();
		this.mysql = mysql.createPool(mysqlConfig);
		// De onde a correspondência sai. Hoje só existe uma resposta: as
		// caixas do Dovecot. A tabela sys_inbox foi apagada em 12/09/2026 e
		// o outro caminho ficou sem destino -- ver mailStore.js. O MySQL
		// segue sendo o cadastro de contas e a chave de device-auth.
		this.store = createStore(config || {}, this.mysql);
		console.log("POP3 storage backend: " + this.store.name);
		// Mail sent from one of our own domains was written for these games
		// and is handed over untouched; see POP3Connection#_getMail.
		this.internalDomains = [emailDomain, emailDomainDion]
			.filter(Boolean).map(domain => String(domain).toLowerCase());
		// Ligado quando o filtro de entrega do Dovecot ja aplica as
		// tratativas. Daqui em diante este servidor so entrega os bytes
		// guardados -- que e o que o Dovecot vai fazer quando assumir a 110.
		this.shapedAtDelivery = (config || {})["shaped_at_delivery"] === true;
		if (this.shapedAtDelivery) console.log("POP3: tratativas ja aplicadas na entrega");
		net.createServer(sock => this._onClientConnect(sock)).listen(110, "0.0.0.0");
		console.log("POP3 server listening");
	}
	
	_onClientConnect(socket) {
		console.log("(POP3) CONNECTED: " + socket.remoteAddress + ":" + socket.remotePort);
		let conn = new POP3Connection(this, socket);
		conn.on("disconnect", (connection, ip, port) => this._onClientDisconnect(connection, ip, port));
		conn.on("command", (command, user, ip, port) => this._onClientCommand(command, user, ip, port));
		conn.on("error", error => this._onClientError(error));
		this.connections.add(conn);
	}
	
	_onClientDisconnect(connection, ip, port) {
		console.log("(POP3) DISCONNECTED: " + ip + ":" + port);
		this.connections.delete(connection);
	}
	
	_onClientCommand(command, user, ip, port) {
		console.log("(POP3) " + ip + ":" + port + (user == null ? "" : " (" + user + ")") + ": " + command);
	}
	
	_onClientError(error) {
		console.log("(POP3) " + error);
	}
}
module.exports.POP3Server = POP3Server;
