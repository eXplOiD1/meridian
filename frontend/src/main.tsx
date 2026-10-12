/* Copyright (c) 2026 Alexander von Pidoll. All rights reserved.
 * SPDX-License-Identifier: LicenseRef-Meridian-Proprietary
 */
import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { App } from './App';
import { useLang } from './i18n';
import './fonts.css';
import './styles.css';

const root = document.getElementById('root');
if (root === null) {
  throw new Error('Das Element #root fehlt in index.html.');
}

/** Beim Sprachwechsel wird die ganze Oberfläche neu aufgebaut (Texte stehen in jeder Komponente). */
function Root() {
  const lang = useLang();
  return <App key={lang} />;
}

createRoot(root).render(
  <StrictMode>
    <Root />
  </StrictMode>,
);
