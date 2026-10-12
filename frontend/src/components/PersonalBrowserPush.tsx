/* Copyright (c) 2026 Alexander von Pidoll. All rights reserved.
 * SPDX-License-Identifier: LicenseRef-Meridian-Proprietary */
import { useEffect, useState } from 'react';
import { request } from '../lib/api';
import { t } from '../i18n';
import { BrowserPushDevices } from './BrowserPushDevices';
import { Alert } from './Alert';

export function PersonalBrowserPush({ csrf }: { csrf: string }) {
  const [channels, setChannels] = useState<{ id: number; name: string }[]>([]);
  const [error, setError] = useState(false);
  useEffect(() => { void request<{ channels: { id: number; name: string }[] }>('GET', '/api/account/browser-channels').then(data => setChannels(data.channels)).catch(() => setError(true)); }, []);
  return <section className="card">
    <h2>{t('Meine Browser-Benachrichtigungen')}</h2>
    <p className="hint">{t('Du entscheidest für jeden Browser selbst. Du erhältst nur Meldungen zu Jobs und Überwachungen, die du sehen darfst und für die dieser Kanal aktiviert ist. Die Freigabe kannst du hier jederzeit nachholen oder entfernen.')}</p>
    {error && <Alert tone="warn">{t('Browser-Geräte konnten nicht geladen werden.')}</Alert>}
    {!error && channels.length === 0 && <p className="hint">{t('Noch kein aktiver Browser-Kanal vorhanden.')}</p>}
    {channels.map(channel => <div key={channel.id}><h3>{channel.name}</h3><BrowserPushDevices channelId={channel.id} csrf={csrf} /></div>)}
  </section>;
}
