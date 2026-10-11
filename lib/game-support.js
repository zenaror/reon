"use strict";
async function enabled(db, module) {
  const [rows] = await db.query('SELECT value FROM sys_settings WHERE name = ?', ['game_support_'+module]);
  return rows.length === 0 || rows[0].value === '1';
}
module.exports = {enabled};
