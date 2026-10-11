import type { HttpMethod } from '../types';

/** Ergebnis von `parseCurl`: genau das, was ein HTTP-Job aus einem curl-Befehl übernehmen kann. */
export interface CurlImport {
  url: string;
  method: HttpMethod;
  headers: Array<{ name: string; value: string }>;
  body: string | null;
  /** Hinweise zu Optionen, die nicht übernommen wurden (nie mit Werten, nur Optionsnamen). */
  ignored: string[];
}

/** Wert für `Authorization: Basic …` (UTF-8, wie curl und Browser es senden). */
export function basicAuthValue(credentials: string): string {
  const bytes = new TextEncoder().encode(credentials);
  let binary = '';
  for (const byte of bytes) binary += String.fromCharCode(byte);

  return 'Basic ' + btoa(binary);
}

const METHODS: readonly string[] = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD'];

/** Zerlegt eine Befehlszeile wie eine POSIX-Shell (Anführungszeichen, Backslash, Zeilenfortsetzung), ohne etwas auszuführen. */
function tokenize(input: string): string[] | null {
  const text = input.replace(/\\\r?\n/g, ' ');
  const tokens: string[] = [];
  let current = '';
  let has = false;
  let quote: '"' | "'" | null = null;
  for (let i = 0; i < text.length; i++) {
    const ch = text.charAt(i);
    if (quote === "'") {
      if (ch === "'") quote = null;
      else current += ch;
    } else if (quote === '"') {
      if (ch === '"') quote = null;
      else if (ch === '\\' && i + 1 < text.length && '"\\$`'.includes(text.charAt(i + 1))) current += text.charAt(++i);
      else current += ch;
    } else if (ch === "'" || ch === '"') {
      quote = ch;
      has = true;
    } else if (ch === '\\' && i + 1 < text.length) {
      current += text.charAt(++i);
      has = true;
    } else if (/\s/.test(ch)) {
      if (has || current !== '') tokens.push(current);
      current = '';
      has = false;
    } else {
      current += ch;
      has = true;
    }
  }
  if (quote !== null) return null;
  if (has || current !== '') tokens.push(current);

  return tokens;
}

/**
 * Liest einen curl-Befehl (z. B. aus „Als cURL kopieren“) für die Job-Anlage. Nur Anzeige und Vorbelegung im Browser:
 * nichts wird ausgeführt oder gesendet, gespeichert wird erst über das normale Formular (Geheimnisse wie sonst verschlüsselt).
 * Gibt `null` zurück, wenn kein Befehl erkennbar ist.
 */
export function parseCurl(input: string): CurlImport | null {
  const tokens = tokenize(input.trim());
  if (tokens === null || tokens.length === 0 || tokens[0] !== 'curl') return null;
  let url = '';
  let method: string | null = null;
  const headers: Array<{ name: string; value: string }> = [];
  const dataParts: string[] = [];
  let head = false;
  let json = false;
  const ignored: string[] = [];

  for (let i = 1; i < tokens.length; i++) {
    let token = tokens[i] ?? '';
    let inline: string | null = null;
    const eq = token.startsWith('--') ? token.indexOf('=') : -1;
    if (eq > 0) {
      inline = token.slice(eq + 1);
      token = token.slice(0, eq);
    } else if (/^-[XHdAuebF]./.test(token)) {
      inline = token.slice(2);
      token = token.slice(0, 2);
    }
    const value = (): string => inline ?? tokens[++i] ?? '';
    switch (token) {
      case '-X':
      case '--request':
        method = value().toUpperCase();
        break;
      case '-H':
      case '--header': {
        const line = value();
        const colon = line.indexOf(':');
        if (colon > 0) headers.push({ name: line.slice(0, colon).trim(), value: line.slice(colon + 1).trim() });
        break;
      }
      case '-d':
      case '--data':
      case '--data-raw':
      case '--data-binary':
      case '--data-ascii':
        dataParts.push(value());
        break;
      case '--json':
        dataParts.push(value());
        json = true;
        break;
      case '-I':
      case '--head':
        head = true;
        break;
      case '--url':
        url = value();
        break;
      case '-u':
      case '--user': {
        const credentials = value();
        headers.push({ name: 'Authorization', value: basicAuthValue(credentials) });
        break;
      }
      case '-A':
      case '--user-agent':
        headers.push({ name: 'User-Agent', value: value() });
        break;
      case '-e':
      case '--referer':
        headers.push({ name: 'Referer', value: value() });
        break;
      case '-b':
      case '--cookie':
        headers.push({ name: 'Cookie', value: value() });
        break;
      case '-L':
      case '--location':
      case '-s':
      case '--silent':
      case '-S':
      case '--show-error':
      case '-k':
      case '--insecure':
      case '--compressed':
      case '-v':
      case '--verbose':
      case '-i':
      case '--include':
      case '-f':
      case '--fail':
        if (token === '-k' || token === '--insecure') ignored.push('--insecure (TLS-Prüfung bleibt immer an)');
        break;
      default:
        if (token.startsWith('-')) {
          ignored.push(token);
          // Unbekannte Option mit getrenntem Wert nicht als URL lesen.
          if (inline === null && /^(-o|--output|-m|--max-time|--connect-timeout|-F|--form|-T|--upload-file|--cert|--key|--cacert|-x|--proxy|-w|--write-out|--retry)$/.test(token)) i++;
        } else if (url === '') {
          url = token;
        }
    }
  }
  if (url === '') return null;
  if (json) {
    if (!headers.some((h) => h.name.toLowerCase() === 'content-type')) headers.push({ name: 'Content-Type', value: 'application/json' });
    if (!headers.some((h) => h.name.toLowerCase() === 'accept')) headers.push({ name: 'Accept', value: 'application/json' });
  }
  const body = dataParts.length > 0 ? dataParts.join('&') : null;
  let chosen = method ?? (head ? 'HEAD' : body !== null ? 'POST' : 'GET');
  if (!METHODS.includes(chosen)) {
    ignored.push('Methode ' + chosen.slice(0, 12));
    chosen = 'GET';
  }

  return { url, method: chosen as HttpMethod, headers, body, ignored };
}
