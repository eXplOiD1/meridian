/* Copyright (c) 2026 Alexander von Pidoll. All rights reserved.
 * SPDX-License-Identifier: LicenseRef-Meridian-Proprietary */
import { useState } from 'react';
import { t } from '../i18n';

/**
 * Öffentlicher Schlüssel eines SSH-Orts zum Eintragen in `authorized_keys` auf dem Server. Der öffentliche Teil ist nicht
 * geheim; der private bleibt verschlüsselt im Server und wird nie angezeigt.
 */
export function SshPublicKey({ publicKey }: { publicKey: string }) {
  const [copied, setCopied] = useState<'key' | 'command' | null>(null);
  const command = "mkdir -p ~/.ssh && chmod 700 ~/.ssh && echo '" + publicKey + " meridian' >> ~/.ssh/authorized_keys && chmod 600 ~/.ssh/authorized_keys";

  async function copy(text: string, what: 'key' | 'command'): Promise<void> {
    try {
      await navigator.clipboard.writeText(text);
      setCopied(what);
      window.setTimeout(() => setCopied(null), 2500);
    } catch {
      setCopied(null);
    }
  }

  return (
    <div className="stack">
      <p className="hint">{t('Öffentlicher Schlüssel dieses Orts (nicht geheim):')}</p>
      <p><code>{publicKey}</code></p>
      <p className="hint">{t('Auf dem Server als der Benutzer, der die Jobs ausführen soll, eintragen:')}</p>
      <p><code>{command}</code></p>
      <div className="row">
        <button type="button" className="btn btn--ghost btn--small" onClick={() => void copy(publicKey, 'key')}>{copied === 'key' ? t('Kopiert') : t('Schlüssel kopieren')}</button>
        <button type="button" className="btn btn--ghost btn--small" onClick={() => void copy(command, 'command')}>{copied === 'command' ? t('Kopiert') : t('Befehl kopieren')}</button>
      </div>
    </div>
  );
}
