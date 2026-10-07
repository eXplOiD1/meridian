#!/usr/bin/env node
/**
 * Steuerung des Meridian-Regel-Hooks. Aufgerufen von /hook-status, /hook-on, /hook-off, /hook-debug.
 * Alle Schalter wirken sofort.
 *
 *   node .claude/hooks/hookctl.js status | on | off | debug [on|off|log|clear]
 */

'use strict';

const fs = require('fs');
const path = require('path');

const DIR = __dirname;
const OFF = path.join(DIR, '.disabled');
const DBG = path.join(DIR, '.debug');
const LOG = path.join(DIR, 'hook.log');
const ROUTER = path.join(DIR, 'rule-router.js');

const has = (p) => fs.existsSync(p);
const fail = (e) => {
  console.log('FEHLER: ' + (e && e.message));
  process.exit(0);
};
const set = (p) => {
  try {
    fs.writeFileSync(p, '');
  } catch (e) {
    fail(e);
  }
};
const unset = (p) => {
  try {
    if (has(p)) fs.unlinkSync(p);
  } catch (e) {
    fail(e);
  }
};

function skillCount() {
  try {
    const d = path.join(DIR, '..', 'skills');
    return fs.readdirSync(d).filter((n) => has(path.join(d, n, 'SKILL.md'))).length;
  } catch (_) {
    return 0;
  }
}

function tail(n) {
  if (!has(LOG)) return ['  (keine Eintraege)'];
  try {
    const lines = fs.readFileSync(LOG, 'utf8').split(/\r?\n/).filter(Boolean);
    return lines.length ? lines.slice(-n).map((l) => '  ' + l) : ['  (keine Eintraege)'];
  } catch (e) {
    return ['  (Log nicht lesbar: ' + e.message + ')'];
  }
}

const cmd = (process.argv[2] || 'status').toLowerCase();
const arg = (process.argv[3] || '').toLowerCase();

switch (cmd) {
  case 'off':
    set(OFF);
    console.log('NOTAUS aktiv — der Hook prueft nichts mehr. Wieder an: /hook-on');
    break;
  case 'on':
    unset(OFF);
    console.log('Hook wieder AKTIV.');
    break;
  case 'debug':
    if (arg === 'log') {
      tail(30).forEach((l) => console.log(l));
    } else if (arg === 'clear') {
      unset(LOG);
      console.log('hook.log geleert.');
    } else {
      const want = arg === 'on' ? true : arg === 'off' ? false : !has(DBG);
      if (want) {
        set(DBG);
        console.log('Protokoll AN (.claude/hooks/hook.log). Anzeigen: /hook-debug log');
      } else {
        unset(DBG);
        console.log('Protokoll aus.');
      }
    }
    break;
  default:
    console.log('Meridian-Regel-Hook');
    console.log('  Pruefung  : ' + (has(OFF) ? 'NOTAUS (.disabled)' : 'AKTIV'));
    console.log('  Protokoll : ' + (has(DBG) ? 'AN (hook.log)' : 'aus'));
    console.log('  Router    : ' + (has(ROUTER) ? 'vorhanden' : 'FEHLT'));
    console.log('  Skills    : ' + skillCount());
    if (has(DBG) || has(LOG)) {
      console.log('  Letzte Laeufe:');
      tail(8).forEach((l) => console.log('  ' + l));
    }
}
