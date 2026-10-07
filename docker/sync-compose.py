#!/usr/bin/env python3
"""Erzeugt den Inline-Dockerfile der compose.yaml aus docker/Dockerfile.

Die compose.yaml soll allein genügen (kein git, kein Klonen auf dem NAS), deshalb enthält sie das Dockerfile
als `dockerfile_inline` und lädt den Quellcode als Archiv von GitHub. Damit beide nicht auseinanderlaufen:
nach jeder Änderung an docker/Dockerfile dieses Skript ausführen:  python3 docker/sync-compose.py
"""

import re
from pathlib import Path

root = Path(__file__).resolve().parent.parent
dockerfile = (root / 'docker' / 'Dockerfile').read_text(encoding='utf-8')
compose_path = root / 'compose.yaml'
compose = compose_path.read_text(encoding='utf-8')

body = dockerfile[dockerfile.index('# --- Abhängigkeiten'):]
# Alle COPY-Quellen aus dem Build-Kontext kommen im Inline-Dockerfile aus der heruntergeladenen Quelle.
body = re.sub(r'^COPY (--chmod=\S+ )?(?!--from)(\S+)', lambda m: 'COPY --from=source ' + (m.group(1) or '') + '/src/' + m.group(2), body, flags=re.M)
body = body.replace('/src/composer.json composer.lock* ./', '/src/composer.json /src/composer.lock* ./')
body = body.replace('/src/frontend/package.json frontend/package-lock.json ./', '/src/frontend/package.json /src/frontend/package-lock.json ./')
body = re.sub(r'(COPY --from=source /src/(?:bin|migrations|public|src|frontend)/?) (\S+)', lambda m: m.group(1) + ' ' + m.group(2), body)

source = '''# --- Quellcode von GitHub (Archiv per HTTPS, auf dem Host wird kein git gebraucht) ---------
FROM alpine:3 AS source
ADD https://github.com/eXplOiD1/meridian/archive/${MERIDIAN_REPO_REF:-claude/great-clarke-ubr0vc}.tar.gz /src.tar.gz
RUN mkdir /src && tar xzf /src.tar.gz -C /src --strip-components=1

'''
inline = source + body.rstrip() + '\n'
# $ für Compose maskieren, außer der Variable für die Version
inline = re.sub(r'\$(?!\{MERIDIAN_REPO_REF)', '$$', inline)
indented = '\n'.join(('        ' + line) if line.strip() else '' for line in inline.split('\n'))

new, count = re.subn(r'(    dockerfile_inline: \|\n)(?:.*\n)*?(?=  image: meridian:local)', lambda m: m.group(1) + indented + '\n', compose, count=1)
if count != 1:
    raise SystemExit('Block dockerfile_inline in compose.yaml nicht gefunden.')
compose_path.write_text(new, encoding='utf-8')
print('compose.yaml aktualisiert.')
