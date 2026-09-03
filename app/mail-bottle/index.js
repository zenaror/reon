const fs = require("fs");
const path = require("path");
const mysql = require("mysql2/promise");

const { Command } = require("commander");

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
// writes straight into sys_inbox (same table/shape deliver.js writes into)
// instead of routing through an SMTP transport. Two reasons, not just one:
// it keeps the player's original message bytes completely untouched (no
// MIME/SMTP-layer reinterpretation of content that was never meant to leave
// the game in the first place), and it avoids a real class of vulnerability
// nodemailer's "raw" option has a history of (arbitrary file read / SSRF
// during message processing) for content nothing internal-only should be
// exposed to regardless of where it's ultimately addressed.

async function doExchange() {
  const connection = await mysql.createConnection(dbConfig);

  try {
    await connection.beginTransaction();

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

        await connection.execute(
          "insert into sys_inbox (sender, recipient, message) values (?, ?, ?)",
          [a["email"], b["acc_id"], "To: " + b["email"] + "\r\n" + a["message"]]
        );
        await connection.execute(
          "insert into sys_inbox (sender, recipient, message) values (?, ?, ?)",
          [b["email"], a["acc_id"], "To: " + a["email"] + "\r\n" + b["message"]]
        );

        // Clean up processed rows
        await connection.execute("DELETE FROM " + table + " WHERE id = ?", [a["id"]]);
        await connection.execute("DELETE FROM " + table + " WHERE id = ?", [b["id"]]);
      }
    }

    await connection.commit();
    console.log("Finished exchange");
  } catch (e) {
    console.error("Exchange failed, rolling back:", e);
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
