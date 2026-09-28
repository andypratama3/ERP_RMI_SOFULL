#!/usr/bin/env python3
"""
Ganti pola header('Location: ...'); exit; → rmi_redirect(...) di tree produksi.
SKIP: _backup/, exports/, vendor/, file yang mendefinisikan function rmi_redirect.
"""
from __future__ import annotations

import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
SKIP_PARTS = frozenset({"_backup", "exports", "vendor", "node_modules", ".git"})


def skip_path(p: Path) -> bool:
    try:
        rel = p.relative_to(ROOT)
    except ValueError:
        return True
    return any(part in SKIP_PARTS for part in rel.parts)


def should_skip_content(path: Path, text: str) -> bool:
    if path.name == "helpers.php" and "function rmi_redirect" in text:
        return True
    if path.parts[-2:] == ("_shared", "helpers.py"):
        return True
    if path.name == "helpers.php" and path.parent.name == "_shared":
        return True
    return False


def transform(text: str) -> tuple[str, int]:
    orig = text
    n = 0

    # Same-line: header(...); exit;
    patterns = [
        (
            re.compile(
                r'header\s*\(\s*"Location:\s*([^"\\]*(?:\\.[^"\\]*)*)"\s*\)\s*;\s*exit\s*;',
                re.MULTILINE,
            ),
            lambda m: f"rmi_redirect(\"{m.group(1)}\")",
        ),
        (
            re.compile(
                r"header\s*\(\s*'Location:\s*([^'\\]*(?:\\.[^'\\]*)*)'\s*\)\s*;\s*exit\s*;",
                re.MULTILINE,
            ),
            lambda m: f"rmi_redirect('{m.group(1)}')",
        ),
    ]
    for rx, repl in patterns:
        text, c = rx.subn(lambda m: repl(m) + ";", text)
        n += c

    # Next-line exit (whitespace flexible)
    patterns2 = [
        (
            re.compile(
                r'header\s*\(\s*"Location:\s*([^"\\]*(?:\\.[^"\\]*)*)"\s*\)\s*;\s*\r?\n\s*exit\s*;',
                re.MULTILINE,
            ),
            lambda m: f"rmi_redirect(\"{m.group(1)}\")",
        ),
        (
            re.compile(
                r"header\s*\(\s*'Location:\s*([^'\\]*(?:\\.[^'\\]*)*)'\s*\)\s*;\s*\r?\n\s*exit\s*;",
                re.MULTILINE,
            ),
            lambda m: f"rmi_redirect('{m.group(1)}')",
        ),
    ]
    for rx, repl in patterns2:
        text, c = rx.subn(lambda m: repl(m) + ";", text)
        n += c

    return text, n


def main() -> int:
    total_files = 0
    total_repls = 0
    for path in ROOT.rglob("*.php"):
        if skip_path(path):
            continue
        try:
            text = path.read_text(encoding="utf-8")
        except (OSError, UnicodeDecodeError):
            continue
        if "header(" not in text or "Location" not in text:
            continue
        if should_skip_content(path, text):
            continue
        new_text, count = transform(text)
        if count and new_text != text:
            path.write_text(new_text, encoding="utf-8")
            total_files += 1
            total_repls += count
            print(f"{count}\t{path.relative_to(ROOT)}")
    print(f"Done: {total_files} files, ~{total_repls} replacements", file=sys.stderr)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
