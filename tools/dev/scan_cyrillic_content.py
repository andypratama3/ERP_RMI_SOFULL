#!/usr/bin/env python3
"""
Scan file content for Cyrillic (U+0400–U+04FF).
Output: storage/logs/cyrillic_content_hits.txt
"""
import os
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent.parent
EXCLUDE = {'vendor', 'node_modules', 'storage', '.git', 'exports', '__pycache__'}
EXT = {'.php', '.html', '.htm', '.js', '.css', '.json', '.md', '.txt', '.sql'}

def has_cyrillic(s: str) -> bool:
    return any(0x0400 <= ord(c) <= 0x04FF for c in s)

def main():
    hits = []
    for dirpath, _, filenames in os.walk(ROOT):
        parts = Path(dirpath).relative_to(ROOT).parts
        if any(p in EXCLUDE for p in parts):
            continue
        for f in filenames:
            if Path(f).suffix.lower() not in EXT:
                continue
            path = Path(dirpath) / f
            try:
                content = path.read_text(encoding='utf-8', errors='replace')
            except Exception:
                continue
            for i, line in enumerate(content.splitlines(), 1):
                if has_cyrillic(line):
                    rel = path.relative_to(ROOT)
                    hits.append((str(rel), i, line.strip()[:80]))
                    break  # one hit per file for brevity; remove for full scan

    log_dir = ROOT / 'storage' / 'logs'
    log_dir.mkdir(parents=True, exist_ok=True)
    out_path = log_dir / 'cyrillic_content_hits.txt'
    with open(out_path, 'w', encoding='utf-8') as out:
        out.write("# Cyrillic Content Scan (U+0400–U+04FF)\n\n")
        for rel, line_no, snippet in hits:
            out.write(f"{rel}:{line_no}: {snippet}\n")
    print(f"Found {len(hits)} files with Cyrillic. Output: {out_path}")
    return 1 if hits else 0

if __name__ == '__main__':
    import sys
    sys.exit(main())
