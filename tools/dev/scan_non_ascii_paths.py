#!/usr/bin/env python3
"""
Scan file/folder names for non-ASCII (Cyrillic U+0400–U+04FF) or any non-ASCII.
Output: JSON to stdout, report to storage/logs/cyrillic_scan_report.txt
"""
import os
import sys
import json
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent.parent
EXCLUDE = {'vendor', 'node_modules', 'storage', '.git', 'exports', '__pycache__'}

def has_non_ascii(s: str) -> bool:
    return not all(ord(c) < 128 for c in s)

def has_cyrillic(s: str) -> bool:
    return any(0x0400 <= ord(c) <= 0x04FF for c in s)

def to_ascii_safe(name: str) -> str:
    """Suggest ASCII-only replacement. Cyrillic -> transliterate or remove."""
    # Minimal: replace known Cyrillic lookalikes with Latin
    trans = str.maketrans({
        '\u0430': 'a', '\u0431': 'b', '\u0432': 'v', '\u0433': 'g', '\u0434': 'd',
        '\u0435': 'e', '\u0451': 'e', '\u0436': 'zh', '\u0437': 'z', '\u0438': 'i',
        '\u0439': 'y', '\u043a': 'k', '\u043b': 'l', '\u043c': 'm', '\u043d': 'n',
        '\u043e': 'o', '\u043f': 'p', '\u0440': 'r', '\u0441': 's', '\u0442': 't',
        '\u0443': 'u', '\u0444': 'f', '\u0445': 'h', '\u0446': 'ts', '\u0447': 'ch',
        '\u0448': 'sh', '\u0449': 'sch', '\u044a': '', '\u044b': 'y', '\u044c': '',
        '\u044d': 'e', '\u044e': 'yu', '\u044f': 'ya',
        '\u0410': 'A', '\u0411': 'B', '\u0412': 'V', '\u0413': 'G', '\u0414': 'D',
        '\u0415': 'E', '\u0401': 'E', '\u0416': 'Zh', '\u0417': 'Z', '\u0418': 'I',
        '\u0419': 'Y', '\u041a': 'K', '\u041b': 'L', '\u041c': 'M', '\u041d': 'N',
        '\u041e': 'O', '\u041f': 'P', '\u0420': 'R', '\u0421': 'S', '\u0422': 'T',
        '\u0423': 'U', '\u0424': 'F', '\u0425': 'H', '\u0426': 'Ts', '\u0427': 'Ch',
        '\u0428': 'Sh', '\u0429': 'Sch', '\u042a': '', '\u042b': 'Y', '\u042c': '',
        '\u042d': 'E', '\u042e': 'Yu', '\u042f': 'Ya',
    })
    out = name.translate(trans)
    # Fallback: strip any remaining non-ASCII
    out = ''.join(c if ord(c) < 128 else '_' for c in out)
    return out or 'unnamed'

def main():
    found = []
    for dirpath, dirnames, filenames in os.walk(ROOT, topdown=True):
        rel = Path(dirpath).relative_to(ROOT)
        parts = rel.parts
        if any(p in EXCLUDE for p in parts):
            dirnames[:] = []
            continue

        for d in list(dirnames):
            if has_non_ascii(d):
                p = str(rel / d)
                found.append({
                    'path': p,
                    'name': d,
                    'type': 'dir',
                    'has_cyrillic': has_cyrillic(d),
                    'suggested': to_ascii_safe(d),
                })

        for f in filenames:
            if has_non_ascii(f):
                p = str(rel / f)
                found.append({
                    'path': p,
                    'name': f,
                    'type': 'file',
                    'has_cyrillic': has_cyrillic(f),
                    'suggested': to_ascii_safe(f),
                })

    log_dir = ROOT / 'storage' / 'logs'
    log_dir.mkdir(parents=True, exist_ok=True)

    report_path = log_dir / 'cyrillic_scan_report.txt'
    with open(report_path, 'w', encoding='utf-8') as out:
        out.write("# Non-ASCII / Cyrillic Path Scan Report\n\n")
        out.write(f"Root: [APP_ROOT]\n")
        out.write(f"Found: {len(found)} paths\n\n")
        out.write("## Paths with non-ASCII\n\n")
        for item in found:
            out.write(f"- {item['path']}\n")
            out.write(f"  type={item['type']} has_cyrillic={item['has_cyrillic']}\n")
            out.write(f"  suggested_rename={item['suggested']}\n\n")
        out.write("\n## Mapping (old -> new)\n\n")
        for item in found:
            base = str(Path(item['path']).parent) if item['path'] else '.'
            base = base + '/' if base != '.' else ''
            new_name = item['suggested']
            out.write(f"{item['path']},{base}{new_name}\n")

    csv_path = log_dir / 'cyrillic_rename_map.csv'
    with open(csv_path, 'w', encoding='utf-8') as out:
        out.write("old_path,new_path\n")
        for item in found:
            base = str(Path(item['path']).parent) if item['path'] else '.'
            base = base + '/' if base != '.' else ''
            new_name = item['suggested']
            new_path = base + new_name
            out.write(f"{item['path']},{new_path}\n")

    result = {'found': found, 'count': len(found), 'report': str(report_path)}
    print(json.dumps(result, indent=2, ensure_ascii=False))
    return 1 if found else 0

if __name__ == '__main__':
    sys.exit(main())
