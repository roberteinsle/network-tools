#!/usr/bin/env python3
"""Builds the GitHub wiki from handbuch/ (the full documentation; README.md is the short overview).

Usage: python3 scripts/build-wiki.py <output-dir>
Then copy the pages into a clone of https://github.com/roberteinsle/network-tools.wiki.git and push.
Never edit the wiki by hand: handbuch/ is the source.

Each handbuch/<Page>.md becomes the wiki page <Page>. Its first line ("# Title") is dropped,
because the wiki shows the page name as title. Links are rewritten:
  [x](Other.md#anchor) -> [x](Other#anchor)     (handbook page -> wiki page)
  [x](../path/file)    -> repository blob link   (file in the repository)
"""
import os
import re
import sys

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
SRC = os.path.join(ROOT, 'handbuch')
REPO = 'https://github.com/roberteinsle/network-tools'
SITE = 'https://network-tools.einsle.app'

# Order of the sidebar; pages not listed here are appended alphabetically.
ORDER = {
    'Getting started': ['Installation', 'Configuration', 'Using-the-backend'],
    'Reference': ['Checks', 'Notifications', 'Operations', 'Login-and-security'],
    'More': ['About'],
}

out = sys.argv[1] if len(sys.argv) > 1 else sys.exit(__doc__)
pages = {}
for f in sorted(os.listdir(SRC)):
    if f.endswith('.md'):
        lines = open(os.path.join(SRC, f), encoding='utf-8').read().split('\n')
        title = lines[0][2:].strip() if lines[0].startswith('# ') else f[:-3].replace('-', ' ')
        body = '\n'.join(lines[1:] if lines[0].startswith('# ') else lines).strip('\n')
        pages[f[:-3]] = (title, body)

listed = [p for group in ORDER.values() for p in group]
missing = [p for p in listed if p not in pages]
if missing:
    sys.exit(f'Pages in ORDER without handbuch file: {missing}')
ORDER['More'] = ORDER['More'] + sorted(p for p in pages if p not in listed)

broken = []


def fix(text, page):
    def repl(m):
        label, target = m.group(1), m.group(2)
        if re.match(r'^(https?:|mailto:|#)', target):
            return m.group(0)
        name, _, anchor = target.partition('#')
        if name.endswith('.md') and '/' not in name:
            if name[:-3] not in pages:
                broken.append(f'{page}: {target}')
            return f'[{label}]({name[:-3]}{"#" + anchor if anchor else ""})'
        path = os.path.normpath(os.path.join('handbuch', name))
        if not os.path.exists(os.path.join(ROOT, path)):
            broken.append(f'{page}: {target}')
        return f'[{label}]({REPO}/blob/main/{path}{"#" + anchor if anchor else ""})'
    return re.sub(r'\[([^\]]+)\]\(([^)\s]+)\)', repl, text)


def link(p):
    return f'[[{pages[p][0]}|{p}]]'


os.makedirs(out, exist_ok=True)
for p, (title, body) in pages.items():
    open(os.path.join(out, f'{p}.md'), 'w', encoding='utf-8').write(fix(body, p) + '\n')

groups = '\n\n'.join(f'## {g}\n' + '\n'.join(f'- {link(p)}' for p in ps) for g, ps in ORDER.items())
open(os.path.join(out, 'Home.md'), 'w', encoding='utf-8').write(f"""**network-tools** is a self-hosted domain monitor. It checks your domains on a schedule – MX, SPF, DKIM, DMARC, MTA-STS, TLS-RPT, BIMI, DNSSEC, TLS certificates, domain expiry and blacklists – keeps a history of every run and emails you when something breaks or changes.

This wiki is generated from the [handbook]({REPO}/tree/main/handbuch) in the repository; if they differ, the handbook applies. For a quick overview, see the [website]({SITE}) or the [README]({REPO}#readme).

{groups}
""")
side = ['**[[Home]]**', '']
for g, ps in ORDER.items():
    side += [f'**{g}**'] + [f'- {link(p)}' for p in ps] + ['']
open(os.path.join(out, '_Sidebar.md'), 'w', encoding='utf-8').write('\n'.join(side))
open(os.path.join(out, '_Footer.md'), 'w', encoding='utf-8').write(
    f'[network-tools]({REPO}) · [Website]({SITE}) · Bugs and ideas: [Issues]({REPO}/issues) · '
    f'Generated from [handbuch/]({REPO}/tree/main/handbuch) – do not edit here\n')
print(f'{len(pages) + 1} pages written to {out}')
if broken:
    sys.exit('Broken links: ' + ', '.join(broken))
