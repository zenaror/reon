// The Node side of web/classes/ActivityLog.php: the same file, the same line
// shape. Used by the jobs that do something to people's accounts (trades).
//
//   activity.record("trade", { region: "e", a: 12, b: 40 });
//
// Numeric account ids and game data only (no IP: the web server log has it) -- no e-mail addresses, no names, no
// message text (see the header of ActivityLog.php). Logging never throws.
const fs = require("fs");

const FILE = process.env.ACTIVITY_LOG || "/var/log/reon/activity.log";

function record(event, fields, level) {
  const line = { ts: new Date().toISOString().replace(/\.\d+Z$/, "Z"), level: level || "info", component: "activity", event: String(event), msg: String(event) };
  for (const [k, v] of Object.entries(fields || {})) {
    if (v === null || v === undefined || ["ts", "level", "component", "event", "msg"].includes(k)) continue;
    line[k] = typeof v === "string" ? v.replace(/[\x00-\x1f\x7f]+/g, " ").slice(0, 160) : v;
  }
  try {
    fs.appendFileSync(FILE, JSON.stringify(line) + "\n");
  } catch (e) {
    // Not fatal (the file may not exist on a dev machine).
  }
}

module.exports = { record };
