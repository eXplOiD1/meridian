#!/usr/bin/env node
/**
 * Meridian-Regel-Hook (siehe CLAUDE.md, Abschnitt "Aufbau in drei Ebenen").
 *
 *   --pre   PreToolUse  auf Edit|Write|MultiEdit -> nennt den zustaendigen Skill und die
 *                       wichtigsten Fallstricke fuer den Pfad.
 *   --post  PostToolUse auf Edit|Write|MultiEdit -> php -l, PHPStan (falls installiert) und
 *                       harte Musterpruefung (Shell, strict_types, @phpstan-ignore, SQL, ...).
 *
 * Grundsaetze:
 *   - Nur Node-Standardbibliothek.
 *   - Faellt IMMER offen aus: jeder interne Fehler endet in exit 0.
 *   - Dateien ausserhalb dieses Repositorys werden ignoriert.
 *   - BLOCK -> stderr + exit 2 (Claude sieht den Verstoss und muss reagieren).
 *     NOTE  -> additionalContext (Hinweis, kein Abbruch).
 *   - Bewusst akzeptierte Stelle: Kommentar `SECURITY-REVIEWED <Datum> (<Verdikt>): <Begruendung>`
 *     in der Fundzeile oder bis zu 4 Zeilen darueber. Wirkt NICHT auf strict_types und
 *     @phpstan-ignore (CLAUDE.md Regel 1).
 *
 * Schalter neben diesem Skript (wirken sofort):
 *   .disabled -> Notaus     .debug -> jeder Lauf landet in hook.log
 */

'use strict';

const { execFileSync } = require('child_process');
const fs = require('fs');
const path = require('path');

const MODE = process.argv.includes('--post') ? 'post' : 'pre';
const HOOK_DIR = __dirname;
const REPO_ROOT = path.resolve(HOOK_DIR, '..', '..');
const FLAG_OFF = path.join(HOOK_DIR, '.disabled');
const FLAG_DEBUG = path.join(HOOK_DIR, '.debug');
const LOG_FILE = path.join(HOOK_DIR, 'hook.log');

function debugLog(line) {
  try {
    if (!fs.existsSync(FLAG_DEBUG)) return;
    try {
      if (fs.statSync(LOG_FILE).size > 256 * 1024) fs.unlinkSync(LOG_FILE);
    } catch (_) {}
    fs.appendFileSync(LOG_FILE, `${new Date().toISOString()} [${MODE}] ${line}\n`);
  } catch (_) {}
}

/* ------------------------------------------------------------------ *
 * Pfad -> Skill (Spiegel der Routing-Tabelle in CLAUDE.md).
 * Erster Treffer gewinnt, spezifischste Regel zuerst. rel ist relativ
 * zum Repository, immer mit "/".
 * ------------------------------------------------------------------ */
const ROUTES = [
  {
    test: (p) => /^src\/(Security|Auth)\//.test(p),
    skill: 'mer-security',
    hints: [
      'Standard ist verboten: AccessControl::require() vor jedem Lesen/Aendern; "alle Kategorien" nur ausdruecklich, nie aus leerer Liste.',
      'Geheimnisse nur SecretBox/_enc, nie in Antworten (auch nicht maskiert), #[\\SensitiveParameter], kein Literal im Code.',
    ],
  },
  {
    test: (p) => /^(migrations\/|src\/Database\/)/.test(p),
    skill: 'mer-storage',
    hints: [
      'SQL nur als Literal, Werte nur als Parameter; eingespielte Migration nie aendern, neue Datei anlegen.',
      'Geheimnis-Spalten mit Suffix _enc; Kaskaden duerfen keine Rechte erweitern; Grunddaten nur einmal seeden.',
    ],
  },
  {
    test: (p) => /^src\/Runner\//.test(p),
    skill: 'mer-runner + mer-security',
    hints: [
      'proc_open nur mit Argument-Array und expliziter Umgebung (nie null, nie MERIDIAN_KEY*); Docker nur ueber den Socket-Proxy.',
      'Payload-Teile im SecretMasker registrieren; Reihenfolge maskieren -> kuerzen -> speichern/senden; SSRF: DNS pruefen, IP festhalten, Weiterleitungen neu pruefen.',
    ],
  },
  {
    test: (p) => /^src\/Schedule\//.test(p) || /^src\/Console\/Scheduler/.test(p),
    skill: 'mer-scheduler',
    hints: [
      'UTC speichern, in Job-Zeitzone auswerten; Sommerzeit-Tests Maerz/Oktober (Europe/Berlin).',
      'Eine Lease-Sperre fuer den Scheduler, Laeufe atomar uebernehmen, max. ein Nachholen pro Job, nie Job-Einstellungen schreiben.',
    ],
  },
  {
    test: (p) => /^(frontend\/|public\/app\/)/.test(p),
    skill: 'mer-ui',
    hints: [
      'CSP nicht aufweichen: keine Inline-Skripte/-Styles, keine fremden Quellen, Schriften selbst gehostet.',
      'Kein dangerouslySetInnerHTML, Ausgaben als Text, keine Tokens im Browser-Speicher, Geheimnisfelder nur schreibend.',
    ],
  },
  {
    test: (p) => /^src\/(Notification|Notify)/.test(p),
    skill: 'mer-runner + mer-security',
    hints: [
      'Benachrichtigungen nur ueber SecretMasker; nur Job-Name, Status, Zeit, Link. Versand-URLs sind Geheimnisse (_enc).',
      'Fuer die neue Ausgabestelle ein Leak-Test mit Test-Geheimnis.',
    ],
  },
  {
    test: (p) => /^src\/User\//.test(p),
    skill: 'mer-security + mer-storage',
    hints: [
      'Passwoerter nur PasswordHasher, Rechte frisch laden (is_active pruefen), Rechteaenderungen ins Audit-Log.',
      'Letzter aktiver Admin bleibt erhalten; wer ein Recht vergibt, muss es selbst haben.',
    ],
  },
  {
    test: (p) => /^(src\/Http\/|src\/Api\/|src\/Controller\/|public\/)/.test(p),
    skill: 'mer-security',
    hints: [
      'Reihenfolge: Anmeldung -> CSRF (aendernd) -> Allowlist -> Datensatz laden -> AccessControl::require() mit Kategorie aus der DB.',
      'Antwort nur freigegebene Felder, durch SecretMasker; Tests pro Rolle (Admin, Operator +/-Kategorie, Beobachter, ohne Anmeldung).',
    ],
  },
  {
    test: (p) => /^(src\/Console\/|bin\/)/.test(p),
    skill: 'mer-security',
    hints: [
      'Kein Geheimnis als CLI-Argument (Shell-History, Prozessliste); Ausgaben ohne Geheimnisse.',
      'Befehle, die ueber den Dienstbenutzer hinaus wirken, pruefen Rechte wie Endpunkte.',
    ],
  },
  {
    test: (p) => /^(deploy\/|docker\/|compose\.ya?ml$|\.github\/)/.test(p),
    skill: 'mer-security + mer-runner',
    hints: [
      'Hauptschluessel nur als Datei (Docker-Secret, LoadCredential), nie als Umgebungsvariable in Compose/Image.',
      'Docker-Socket nie in Meridian einbinden, nur docker-socket-proxy im internen Netz; Container ohne root, read_only, cap_drop ALL.',
    ],
  },
  {
    test: (p) => /^tests\//.test(p),
    skill: 'mer-security',
    hints: [
      'Testwerte offensichtlich unecht (TEST-SECRET-do-not-use-123), echte Geheimnisse nie im Repository.',
      'Pflicht: Rechte-Tests pro Rolle, Leak-Test je Ausgabestelle, Roundtrip ueber neue Connection.',
    ],
  },
  {
    test: (p) => /^src\//.test(p),
    skill: 'mer-security + mer-storage',
    hints: [
      'Jede Ausgabestelle durch SecretMasker, jeder Zugriff hinter AccessControl::require().',
      'SQL nur als Literal mit Parametern; declare(strict_types=1); Klassen final, Wertobjekte readonly.',
    ],
  },
];

const GLOBAL_HINT = 'Regelaenderung? Den zustaendigen Skill unter .claude/skills/ im selben Schritt mitziehen.';

/* ------------------------------------------------------------------ *
 * Hilfen
 * ------------------------------------------------------------------ */
function norm(p) {
  return String(p || '').replace(/\\/g, '/');
}

/** MSYS-Pfade (/c/Users/...) nach Windows drehen, sonst laeuft existsSync ins Leere. */
function toNativePath(p) {
  const s = norm(p);
  const m = /^\/([a-zA-Z])\/(.*)$/.exec(s);
  if (m && process.platform === 'win32') return path.resolve(`${m[1].toUpperCase()}:/${m[2]}`);
  return path.resolve(REPO_ROOT, s);
}

/** Pfad relativ zum Repository oder null, wenn ausserhalb. */
function relToRepo(nativePath) {
  const rel = norm(path.relative(REPO_ROOT, nativePath));
  if (!rel || rel.startsWith('../') || rel === '..' || path.isAbsolute(rel)) return null;
  // Das Produkt liegt unter Meridian/; Routing und Regeln arbeiten mit Pfaden relativ dazu (src/, bin/, ...).
  return rel.replace(/^Meridian\//, '');
}

const SKIP_PATHS = /(^|\/)(vendor|node_modules|dist|var|\.git)\/|\.min\.(js|css)$|^public\/app\//;

function routeFor(rel) {
  return ROUTES.find((r) => {
    try {
      return r.test(rel);
    } catch (_) {
      return false;
    }
  });
}

/* ------------------------------------------------------------------ *
 * PHP-Lexer: liefert den Text mit ausgeblendeten Strings und Kommentaren
 * (gleiche Laenge, Zeilenumbrueche bleiben), dazu Kommentare und Backticks.
 * ------------------------------------------------------------------ */
function lexPhp(src) {
  const out = src.split('');
  const comments = []; // {line, text}
  const backticks = []; // line numbers
  let i = 0;
  let line = 1;
  let inPhp = false;
  const n = src.length;

  const blank = (from, to) => {
    for (let k = from; k < to && k < n; k++) if (out[k] !== '\n' && out[k] !== '\r') out[k] = ' ';
  };
  const countLines = (from, to) => {
    let c = 0;
    for (let k = from; k < to && k < n; k++) if (src[k] === '\n') c++;
    return c;
  };

  // Shebang
  if (src.startsWith('#!')) {
    const e = src.indexOf('\n');
    blank(0, e === -1 ? n : e);
  }

  while (i < n) {
    if (!inPhp) {
      const j = src.indexOf('<?', i);
      if (j === -1) {
        blank(i, n);
        break;
      }
      blank(i, j);
      line += countLines(i, j);
      i = j + (src.startsWith('<?php', j) ? 5 : src.startsWith('<?=', j) ? 3 : 2);
      inPhp = true;
      continue;
    }
    const c = src[i];
    const c2 = src.substr(i, 2);
    if (c === '\n') {
      line++;
      i++;
      continue;
    }
    if (c2 === '?>') {
      inPhp = false;
      i += 2;
      continue;
    }
    if (c2 === '//' || (c === '#' && src[i + 1] !== '[')) {
      let e = i;
      while (e < n && src[e] !== '\n' && src.substr(e, 2) !== '?>') e++;
      comments.push({ line, text: src.slice(i, e) });
      blank(i, e);
      i = e;
      continue;
    }
    if (c2 === '/*') {
      let e = src.indexOf('*/', i + 2);
      e = e === -1 ? n : e + 2;
      const text = src.slice(i, e);
      text.split('\n').forEach((t, k) => comments.push({ line: line + k, text: t }));
      blank(i, e);
      line += countLines(i, e);
      i = e;
      continue;
    }
    if (c === "'" || c === '"') {
      let e = i + 1;
      while (e < n && src[e] !== c) e += src[e] === '\\' ? 2 : 1;
      e = Math.min(e + 1, n);
      blank(i + 1, e - 1);
      line += countLines(i, e);
      i = e;
      continue;
    }
    if (c === '`') {
      backticks.push(line);
      let e = src.indexOf('`', i + 1);
      e = e === -1 ? n : e + 1;
      line += countLines(i, e);
      i = e;
      continue;
    }
    if (src.startsWith('<<<', i)) {
      const m = /^<<<[ \t]*(['"]?)([A-Za-z_][A-Za-z0-9_]*)\1\r?\n/.exec(src.slice(i, i + 200));
      if (m) {
        const id = m[2];
        const bodyStart = i + m[0].length;
        const endRe = new RegExp(`^[ \\t]*${id}\\b`, 'm');
        const rest = src.slice(bodyStart);
        const em = endRe.exec(rest);
        const e = em ? bodyStart + em.index + em[0].length : n;
        blank(bodyStart, e - id.length);
        line += countLines(i, e);
        i = e;
        continue;
      }
    }
    i++;
  }
  return { code: out.join(''), comments, backticks };
}

function lineAt(text, offset) {
  let c = 1;
  for (let k = 0; k < offset && k < text.length; k++) if (text[k] === '\n') c++;
  return c;
}

/**
 * Prueft das erste Argument eines DB-Aufrufs im Originaltext.
 * Liefert eine Begruendung, wenn das SQL nicht als reines Literal uebergeben wird.
 */
function sqlArgProblem(raw, pos) {
  let i = pos;
  const skipWs = () => {
    while (i < raw.length && /\s/.test(raw[i])) i++;
  };
  skipWs();
  const ch = raw[i];
  if (ch === ')' || ch === '[' || ch === undefined) return null;
  if (ch === '"') {
    let e = i + 1;
    let interp = false;
    while (e < raw.length && raw[e] !== '"') {
      if (raw[e] === '\\') {
        e += 2;
        continue;
      }
      if (raw[e] === '$' && /[A-Za-z_{]/.test(raw[e + 1] || '')) interp = true;
      if (raw[e] === '{' && raw[e + 1] === '$') interp = true;
      e++;
    }
    if (interp) return 'Variable im doppelt gequoteten SQL-String';
    i = e + 1;
  } else if (ch === "'") {
    let e = i + 1;
    while (e < raw.length && raw[e] !== "'") e += raw[e] === '\\' ? 2 : 1;
    i = e + 1;
  } else if (raw.startsWith('<<<', i)) {
    const m = /^<<<[ \t]*(['"]?)([A-Za-z_]\w*)\1/.exec(raw.slice(i, i + 100));
    if (m && m[1] !== "'") {
      const end = raw.indexOf('\n' , i);
      const body = raw.slice(end, raw.indexOf(m[2], end + 1));
      if (/\$[A-Za-z_{]|\{\$/.test(body)) return 'Variable im Heredoc-SQL';
    }
    return null;
  } else if (ch === '$') {
    const m = /^\$([A-Za-z_]\w*)/.exec(raw.slice(i));
    if (m && /sql|query|stmt|statement/i.test(m[1])) return `SQL aus Variable $${m[1]} statt Literal`;
    return null;
  } else if (/[A-Za-z_\\]/.test(ch)) {
    const m = /^\\?([A-Za-z_]\w*)\s*\(/.exec(raw.slice(i));
    if (m && /^(sprintf|vsprintf|implode|join|str_replace|strtr|preg_replace)$/i.test(m[1])) {
      return `SQL ueber ${m[1]}() zusammengesetzt`;
    }
    return null;
  } else {
    return null;
  }
  // Nach dem Literal: Verkettung?
  skipWs();
  if (raw[i] === '.') {
    i++;
    skipWs();
    if (raw[i] === "'" || raw[i] === '"') return sqlArgProblem(raw, i);
    return 'SQL-Literal mit Wert/Variable verkettet';
  }
  return null;
}

/* ------------------------------------------------------------------ *
 * Musterpruefung
 * ------------------------------------------------------------------ */
const isPhpPath = (rel, text) =>
  /\.php$/.test(rel) || (/^bin\//.test(rel) && /^#![^\n]*\bphp\b/.test(text));
const isFrontend = (rel) => /^(frontend|public)\//.test(rel) && /\.(jsx?|tsx?|mjs|cjs|html?|vue|svelte)$/.test(rel);
const isTestFixture = (rel) => /^tests\/Fixtures\//.test(rel);

function suppressed(lines, lineNo) {
  const ctx = lines.slice(Math.max(0, lineNo - 5), lineNo).join('\n');
  return /SECURITY-REVIEWED/.test(ctx);
}

function scanPhp(rel, raw, lines, found) {
  const { code, comments, backticks } = lexPhp(raw);
  const codeLines = code.split('\n');
  const add = (level, line, msg, opts = {}) => {
    if (opts.suppressible !== false && suppressed(lines, line)) return;
    found.push({ level, line, msg });
  };

  // 1. strict_types (Regel aus CLAUDE.md Tech-Stack)
  if (/<\?php/.test(raw) && !/^(?:#![^\n]*\n)?\s*<\?php\s+(?:(?:\/\*[\s\S]*?\*\/|\/\/[^\n]*|#(?!\[)[^\n]*)\s*)*declare\s*\(\s*strict_types\s*=\s*1\s*\)\s*;/.test(raw)) {
    add('BLOCK', 1, 'declare(strict_types=1); fehlt oder steht nicht als erste Anweisung nach <?php.', { suppressible: false });
  }

  // 2. Verbotene Shell-Aufrufe (Regel 7)
  const shellRe = /(?<![\w$>:\\])\\?(exec|shell_exec|system|passthru|popen|pcntl_exec)\s*\(/g;
  for (let m; (m = shellRe.exec(code)); ) {
    const ln = lineAt(code, m.index);
    if (/\bfunction\s+$/.test(code.slice(Math.max(0, m.index - 20), m.index))) continue;
    add('BLOCK', ln, `${m[1]}() verboten — Shell nur ueber proc_open mit Argument-Array (Regel 7, mer-runner).`);
  }
  backticks.forEach((ln) =>
    add('BLOCK', ln, 'Backtick-Operator fuehrt eine Shell aus — verboten, nur proc_open mit Argument-Array (Regel 7).')
  );
  const procRe = /\bproc_open\s*\(/g;
  for (let m; (m = procRe.exec(code)); ) {
    const after = raw.slice(m.index + m[0].length, m.index + m[0].length + 40).trimStart();
    if (/^(['"]|sprintf|implode|\$\w*(cmd|command|line)\b)/i.test(after)) {
      add('BLOCK', lineAt(code, m.index), 'proc_open mit Shell-String — nur Argument-Array erlaubt (Regel 7).');
    }
  }

  // 3. @phpstan-ignore / Baseline (Regel 1) und Taint-Unterdrueckung
  for (const c of comments) {
    const m = /@phpstan-ignore[\w-]*([^\n]*)/.exec(c.text);
    if (m) {
      const reason = m[1].replace(/\*\/\s*$/, '').trim();
      const hasReason = /\(([^)]{10,})\)/.test(reason) || /(—|--|:)\s*\S.{8,}/.test(reason);
      if (!hasReason) {
        found.push({ level: 'BLOCK', line: c.line, msg: '@phpstan-ignore ohne Begruendung im selben Kommentar — Ursache beheben (Regel 1).' });
      } else {
        found.push({ level: 'NOTE', line: c.line, msg: '@phpstan-ignore mit Begruendung — braucht zusaetzlich die Zustimmung von Alex (Regel 1).' });
      }
    }
    if (/@psalm-suppress\s+[^\n]*Tainted/i.test(c.text)) {
      found.push({ level: 'BLOCK', line: c.line, msg: 'Taint-Befund unterdrueckt (@psalm-suppress Tainted*) — Ursache beheben (Regel 2/7).' });
    } else if (/@psalm-suppress/.test(c.text)) {
      found.push({ level: 'NOTE', line: c.line, msg: '@psalm-suppress — nur mit Begruendung, Ursache bevorzugt beheben.' });
    }
  }

  // 4. SQL mit Variablen (Regel 2)
  const isConnection = /^src\/Database\/Connection\.php$/.test(rel);
  const isMigrator = /^src\/Database\/Migrator\.php$/.test(rel);
  const dbRe = /->\s*(execute|fetchAll|fetchOne|prepare|query|exec)\s*\(/g;
  for (let m; (m = dbRe.exec(code)); ) {
    if (isConnection) continue;
    const problem = sqlArgProblem(raw, m.index + m[0].length);
    if (problem) {
      add('BLOCK', lineAt(code, m.index), `${problem} — SQL nur als Literal, Werte nur als Parameter (Regel 2, mer-storage).`);
    }
  }
  const migRe = /->\s*executeMigrationScript\s*\(/g;
  for (let m; (m = migRe.exec(code)); ) {
    if (!isMigrator && !isConnection) {
      add('BLOCK', lineAt(code, m.index), 'executeMigrationScript() nur aus Migrator mit Dateien aus migrations/ (Regel 2).');
    }
  }
  const pdoRe = /\bnew\s+\\?PDO\s*\(/g;
  for (let m; (m = pdoRe.exec(code)); ) {
    if (!isConnection) add('BLOCK', lineAt(code, m.index), 'PDO nur in src/Database/Connection.php (mer-storage).');
  }

  // 5. Weitere harte Verstoesse
  const lineRules = [
    { level: 'BLOCK', re: /\beval\s*\(/, msg: 'eval() verboten.' },
    { level: 'BLOCK', re: /CURLOPT_SSL_VERIFYPEER\s*(=>|,)\s*(false|0)\b/, msg: 'TLS-Pruefung abgeschaltet — VERIFYPEER muss true sein (mer-runner).' },
    { level: 'BLOCK', re: /CURLOPT_SSL_VERIFYHOST\s*(=>|,)\s*(false|0|1)\b/, msg: 'CURLOPT_SSL_VERIFYHOST muss 2 sein (mer-runner).' },
    { level: 'BLOCK', re: /['"]verify_peer(_name)?['"]\s*=>\s*false/, msg: 'Stream-Context ohne TLS-Pruefung (mer-runner).' },
    { level: 'BLOCK', re: /CURLOPT_FOLLOWLOCATION\s*(=>|,)\s*(true|1)\b/, msg: 'Automatische Weiterleitungen umgehen die SSRF-Pruefung — manuell folgen und neu pruefen (mer-runner).' },
    { level: 'BLOCK', re: /ini_set\s*\(\s*['"]display_errors['"]\s*,\s*(['"]?(1|on|true|stdout)['"]?|true)\s*\)/i, msg: 'display_errors einschalten ist verboten (Regel 9).' },
    { level: 'BLOCK', re: /(?<![\w>:])password_hash\s*\(/, when: (r) => !/^src\/Security\/PasswordHasher\.php$/.test(r), msg: 'password_hash() nur in PasswordHasher (Regel 8).' },
    { level: 'NOTE', re: /(?<![\w>:])(md5|sha1)\s*\(/, msg: 'md5/sha1 — fuer Passwoerter/Tokens ungeeignet; PasswordHasher bzw. ApiToken nutzen.' },
    { level: 'NOTE', re: /(?<![\w>:])unserialize\s*\(/, msg: 'unserialize() — nur mit allowed_classes => false, besser JSON.' },
    { level: 'NOTE', re: /(?<![\w>:])(var_dump|print_r|var_export|debug_zval_dump|dd)\s*\(/, when: (r) => /^src\//.test(r), msg: 'Debug-Ausgabe in src/ — kann Geheimnisse ausgeben (Regel 4).' },
    { level: 'NOTE', re: /(?<![\w>:])error_log\s*\(/, unlessLine: /mask\s*\(/, msg: 'error_log() ohne SecretMasker::mask() in derselben Zeile (Regel 4).' },
    { level: 'NOTE', re: /getenv\s*\(\s*['"]MERIDIAN_KEY/, when: (r) => !/^src\/Security\/KeyLoader\.php$/.test(r), msg: 'Hauptschluessel nur ueber KeyLoader laden.' },
  ];
  for (const r of lineRules) {
    if (r.when && !r.when(rel)) continue;
    for (let k = 0; k < codeLines.length; k++) {
      // Muster auf Code ohne Strings/Kommentare; CURLOPT/ini_set brauchen den String-Inhalt.
      const subject = /CURLOPT|verify_peer|ini_set|getenv/.test(r.re.source) ? stripComment(lines[k]) : codeLines[k];
      if (!r.re.test(subject)) continue;
      if (r.unlessLine && r.unlessLine.test(lines[k])) continue;
      add(r.level, k + 1, r.msg);
      break;
    }
  }
}

function stripComment(line) {
  return String(line || '').replace(/^\s*(\/\/|#(?!\[)|\*|\/\*).*$/, '');
}

function scanFrontend(rel, raw, lines, found) {
  const rules = [
    { level: 'BLOCK', re: /dangerouslySetInnerHTML/, msg: 'dangerouslySetInnerHTML verboten — Ausgaben als Text (mer-ui).' },
    { level: 'BLOCK', re: /\.(innerHTML|outerHTML)\s*=|insertAdjacentHTML\s*\(|document\.write\s*\(/, msg: 'HTML aus Daten ins DOM — verboten, textContent bzw. React-Text nutzen (mer-ui).' },
    { level: 'BLOCK', re: /\beval\s*\(|new\s+Function\s*\(/, msg: 'eval/new Function verstoesst gegen die CSP (mer-ui).' },
    { level: 'BLOCK', re: /fonts\.(googleapis|gstatic)\.com|cdn\.jsdelivr|unpkg\.com|cdnjs\./, msg: 'Fremde Quelle — Schriften/Skripte selbst hosten (mer-ui, CSP).' },
    { level: 'BLOCK', re: /(localStorage|sessionStorage)\.setItem\s*\([^)]*(token|secret|session|passw|auth)/i, msg: 'Token/Geheimnis im Browser-Speicher — nur HttpOnly-Cookie (mer-ui).' },
  ];
  if (/\.html?$/.test(rel)) {
    rules.push(
      { level: 'BLOCK', re: /<script\b(?![^>]*\bsrc\s*=)[^>]*>\s*[^<\s]/i, msg: 'Inline-Skript — verstoesst gegen die CSP (mer-ui).' },
      { level: 'BLOCK', re: /\son[a-z]+\s*=\s*["']/i, msg: 'Inline-Eventhandler — verstoesst gegen die CSP (mer-ui).' },
      { level: 'NOTE', re: /<style\b|\sstyle\s*=\s*["']/i, msg: 'Inline-Style — CSP style-src self blockiert ihn (mer-ui).' }
    );
  }
  for (const r of rules) {
    for (let k = 0; k < lines.length; k++) {
      if (!r.re.test(lines[k])) continue;
      if (suppressed(lines, k + 1)) continue;
      found.push({ level: r.level, line: k + 1, msg: r.msg });
      break;
    }
  }
}

function scanGeneric(rel, raw, lines, found) {
  const rules = [
    { level: 'BLOCK', re: /-----BEGIN [A-Z ]*PRIVATE KEY-----/, msg: 'Privater Schluessel im Repository (Regel 10).' },
    {
      level: 'NOTE',
      re: /\b(password|passwd|secret|token|api[_-]?key|client[_-]?secret)\b["']?\s*[:=]>?\s*["'][^"'\s$]{8,}["']/i,
      unlessLine: /TEST|test|example|beispiel|unecht|dummy|do-not-use|placeholder|xxx|\*\*\*|••••|<[^>]+>|\$\{/,
      when: (r) => !isTestFixture(r) && !/\.md$/.test(r),
      msg: 'Moegliches Geheimnis als Literal — Geheimnisse nie im Quelltext, auch nicht als Standardwert (Regel 10, mer-security).',
    },
  ];
  if (/(^|\/)(compose[^/]*\.ya?ml|docker-compose[^/]*\.ya?ml|Dockerfile[^/]*)$/.test(rel) || /^docker\//.test(rel)) {
    rules.push(
      { level: 'BLOCK', re: /^\s*-?\s*MERIDIAN_KEY\s*[:=]/m, msg: 'Hauptschluessel als Umgebungsvariable — nur als Datei (Docker-Secret) (infra, mer-security).' },
      { level: 'BLOCK', re: /^\s*ENV\s+[^#]*\bMERIDIAN_KEY=/, msg: 'Hauptschluessel im Image — nur als Datei zur Laufzeit.' },
      { level: 'BLOCK', re: /^\s*privileged\s*:\s*true/, msg: 'privileged: true ist verboten.' },
      { level: 'NOTE', re: /^\s*-\s*\/var\/run\/docker\.sock/, msg: 'Docker-Socket eingebunden — nur im docker-socket-proxy erlaubt, nie in Meridian (mer-runner).' }
    );
  }
  if (/\.neon$/.test(rel)) {
    rules.push({ level: 'BLOCK', re: /baseline|ignoreErrors/, msg: 'PHPStan-Baseline/ignoreErrors — verboten ohne Zustimmung von Alex (Regel 1).' });
  }
  for (const r of rules) {
    if (r.when && !r.when(rel)) continue;
    for (let k = 0; k < lines.length; k++) {
      const subject = /^\s*#/.test(lines[k]) && !/\.ya?ml$|Dockerfile/.test(rel) ? '' : lines[k];
      if (/^\s*#/.test(lines[k]) && /\.ya?ml$|Dockerfile/.test(rel)) continue; // auskommentiert
      if (!r.re.test(subject)) continue;
      if (r.unlessLine && r.unlessLine.test(lines[k])) continue;
      if (suppressed(lines, k + 1)) continue;
      found.push({ level: r.level, line: k + 1, msg: r.msg });
      break;
    }
  }
}

function scanFile(nativePath, rel) {
  let raw;
  try {
    if (fs.statSync(nativePath).size > 2 * 1024 * 1024) return [];
    raw = fs.readFileSync(nativePath, 'utf8');
  } catch (_) {
    return [];
  }
  const lines = raw.split(/\r?\n/);
  const found = [];
  if (isPhpPath(rel, raw)) scanPhp(rel, raw, lines, found);
  else if (isFrontend(rel)) scanFrontend(rel, raw, lines, found);
  scanGeneric(rel, raw, lines, found);
  return found;
}

/* ------------------------------------------------------------------ *
 * Externe Pruefungen (optional, fallen offen aus)
 * ------------------------------------------------------------------ */
/** Meridian braucht PHP >= 8.3. Ein aelteres php im PATH wuerde gueltigen Code als Syntaxfehler melden. */
let phpOk = null;
function phpUsable() {
  if (phpOk !== null) return phpOk;
  try {
    const v = execFileSync('php', ['-r', 'echo PHP_VERSION_ID;'], { stdio: 'pipe', timeout: 5000 }).toString().trim();
    phpOk = /^\d+$/.test(v) && Number(v) >= 80300;
  } catch (_) {
    phpOk = false;
  }
  debugLog(`php nutzbar: ${phpOk}`);
  return phpOk;
}

function phpLint(nativePath, rel, raw) {
  if (!isPhpPath(rel, raw) || !phpUsable()) return null;
  try {
    execFileSync('php', ['-l', nativePath], { stdio: 'pipe', timeout: 15000 });
    return null;
  } catch (err) {
    const out = [err.stdout, err.stderr].map((b) => (b ? b.toString() : '')).join('').trim();
    if (!out || err.code === 'ENOENT' || /is not recognized|command not found|ENOENT/i.test(out)) return null;
    return out.split(/\r?\n/).filter(Boolean).slice(0, 3).join(' | ');
  }
}

function phpstan(nativePath, rel) {
  if (!/^(src|bin|public|tests)\/.*\.php$/.test(rel)) return [];
  const bin = path.join(REPO_ROOT, 'vendor', 'phpstan', 'phpstan', 'phpstan.phar');
  if (!fs.existsSync(bin) || !phpUsable()) return [];
  let raw = '';
  try {
    raw = execFileSync('php', ['-d', 'memory_limit=1G', bin, 'analyse', '--no-progress', '--error-format=json', nativePath],
      { cwd: REPO_ROOT, stdio: 'pipe', timeout: 25000 }).toString();
  } catch (err) {
    raw = err.stdout ? err.stdout.toString() : '';
  }
  try {
    const res = JSON.parse(raw.slice(raw.indexOf('{')));
    const out = [];
    for (const file of Object.values(res.files || {})) {
      for (const m of file.messages || []) out.push({ line: m.line, msg: `PHPStan: ${m.message}` });
    }
    return out.slice(0, 8);
  } catch (_) {
    return [];
  }
}

/* ------------------------------------------------------------------ */
function runPre(rel) {
  const route = routeFor(rel);
  if (!route) return;
  const lines = [`[Meridian-Routing] Zustaendiger Skill: ${route.skill}`, ...route.hints.map((h) => `  - ${h}`), `  - ${GLOBAL_HINT}`];
  process.stdout.write(JSON.stringify({ hookSpecificOutput: { hookEventName: 'PreToolUse', additionalContext: lines.join('\n') } }));
}

function runPost(nativePath, rel) {
  if (!fs.existsSync(nativePath) || SKIP_PATHS.test(rel)) return;
  let raw = '';
  try {
    raw = fs.readFileSync(nativePath, 'utf8');
  } catch (_) {
    return;
  }
  const blocks = [];
  const notes = [];
  const lint = phpLint(nativePath, rel, raw);
  if (lint) blocks.push(`  php -l: ${lint}`);
  if (!lint) for (const f of phpstan(nativePath, rel)) blocks.push(`  ${rel}:${f.line} — ${f.msg}`);
  for (const f of scanFile(nativePath, rel)) (f.level === 'BLOCK' ? blocks : notes).push(`  ${rel}:${f.line} — ${f.msg}`);

  debugLog(`  -> ${blocks.length} BLOCK / ${notes.length} NOTE (${rel})`);

  if (blocks.length) {
    const out = ['[Meridian-Regelverstoss] ' + rel, ...blocks];
    if (notes.length) out.push('  --- zusaetzlich pruefen ---', ...notes);
    out.push('  Ursache beheben. Bewusst akzeptierte Stelle nur mit Zustimmung von Alex und Kommentar SECURITY-REVIEWED.');
    process.stderr.write(out.join('\n') + '\n');
    process.exit(2);
  }
  if (notes.length) {
    process.stdout.write(JSON.stringify({
      hookSpecificOutput: { hookEventName: 'PostToolUse', additionalContext: ['[Meridian-Hinweis] ' + rel, ...notes].join('\n') },
    }));
  }
}

try {
  if (fs.existsSync(FLAG_OFF)) {
    debugLog('SKIP (.disabled)');
    process.exit(0);
  }
  let input = '';
  try {
    input = fs.readFileSync(0, 'utf8');
  } catch (_) {}
  const payload = JSON.parse(input || '{}');
  const ti = payload.tool_input || {};
  const filePath = ti.file_path || ti.path || ti.notebook_path || '';
  debugLog(filePath || '(kein file_path)');
  if (filePath) {
    const nativePath = toNativePath(filePath);
    const rel = relToRepo(nativePath);
    if (rel) {
      if (MODE === 'pre') runPre(rel);
      else runPost(nativePath, rel);
    }
  }
} catch (err) {
  debugLog('FEHLER: ' + (err && err.message));
}
process.exit(0);
