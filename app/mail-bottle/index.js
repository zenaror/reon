const gameSupport = require("../../lib/game-support");
const fs = require("fs");
const path = require("path");
const mysql = require("mysql2/promise");

const { Command } = require("commander");
const { sendRaw, configure: configurarEnvio } = require("../../lib/rawmail");
const log = require("../../lib/log").child("mail-bottle");
const activity = require("../../lib/activity");

// ------------------------------
// Config
// ------------------------------

const defaultPath = path.resolve(__dirname, "..", "..", "config.json");

const program = new Command();
program
  .option("-c, --config <path>", "Config file path.", defaultPath)
  .parse(process.argv);

const options = program.opts();
const config = JSON.parse(fs.readFileSync(options.config, "utf8"));

// Select local SMTP when local_smtp_host is configured; otherwise use sendmail. See
// lib/rawmail.js. smtp_host is the EXTERNAL relay and must not be used for internal
// delivery.
configurarEnvio(config, config["local_smtp_host"] ? require("nodemailer") : null);

const dbConfig = {
  host: config["mysql_host"],
  port: config["mysql_port"] || 3306,
  user: config["mysql_user"],
  password: config["mysql_password"],
  database: config["mysql_database"],
};

// ------------------------------
// Email + main exchange logic
// ------------------------------
//
// Both trade partners are always the game's own internal accounts (email is
// always dion_email_local@email_domain_dion, set server-side in
// 20.bottlemail.php -- never a real address a player typed in), so this
// hands the message to the local mail system as-is, rather than composing
// it through an SMTP library. Two reasons, not just one:
// it keeps the player's original message bytes completely untouched (no
// MIME/SMTP-layer reinterpretation of content that was never meant to leave
// the game in the first place), and it avoids a real class of vulnerability
// nodemailer's "raw" option has a history of (arbitrary file read / SSRF
// during message processing) for content nothing internal-only should be
// exposed to regardless of where it's ultimately addressed.

async function doExchange() {
  const connection = await mysql.createConnection(dbConfig);
    if (!await gameSupport.enabled(connection, "mailbottle")) { await connection.end(); return; }

  try {
    await connection.beginTransaction();
    const activityToWrite = [];

    const table = "amc_trades";

    const [trades] = await connection.execute(
      "SELECT * FROM " + table + " ORDER BY game_region ASC, timestamp ASC"
    );

    // group trades by region and pair sequentially within each region
    const byRegion = new Map();
    for (const trade of trades) {
      const region = trade["game_region"] ?? "";
      if (!byRegion.has(region)) byRegion.set(region, []);
      byRegion.get(region).push(trade);
    }

    for (const [region, list] of byRegion.entries()) {
      for (let i = 1; i < list.length; i += 2) {
        const a = list[i - 1];
        const b = list[i];

        // Submit through local SMTP instead of inserting directly into an inbox table. This also
        // works with the upstream Postfix/Dovecot stack. Keep the bottle-provided addresses
        // previously stored in sender; domain checks still determine internal delivery.
        await sendRaw(a["email"], b["email"], "To: " + b["email"] + "\r\n" + a["message"]);
        await sendRaw(b["email"], a["email"], "To: " + a["email"] + "\r\n" + b["message"]);

        activityToWrite.push({ kind: "mail-bottle", region });

        // Clean up processed rows
        await connection.execute("DELETE FROM " + table + " WHERE id = ?", [a["id"]]);
        await connection.execute("DELETE FROM " + table + " WHERE id = ?", [b["id"]]);
      }
    }

    await connection.commit();
    for (const t of activityToWrite) activity.record("trade", t);
    log.info("Finished exchange");
  } catch (e) {
    log.error("Exchange failed, rolling back:", e);
    try {
      await connection.rollback();
    } catch (_) {}
  } finally {
    try {
      await connection.end();
    } catch (_) {}
  }
}

doExchange();
