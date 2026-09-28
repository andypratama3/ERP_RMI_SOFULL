#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(CDPATH= cd -- "$(dirname -- "${BASH_SOURCE[0]}")/../.." && pwd)"
OUT_DIR="$ROOT_DIR/output/internal_release"
mkdir -p "$OUT_DIR"

SUMMARY_JSON="$OUT_DIR/final_signoff_summary.json"
SUMMARY_MD="$OUT_DIR/final_signoff_summary.md"

UNIT_XML_GLOB="$ROOT_DIR/app/build/test-results/testDebugUnitTest/*.xml"
INST_XML_GLOB="$ROOT_DIR/app/build/outputs/androidTest-results/connected/debug/*.xml"
CONTRACT_JSON="$ROOT_DIR/../storage/logs/mobile_contract_check_last.json"

python3 - "$SUMMARY_JSON" "$SUMMARY_MD" "$UNIT_XML_GLOB" "$INST_XML_GLOB" "$CONTRACT_JSON" <<'PY'
import glob
import json
import os
import sys
import xml.etree.ElementTree as ET
from datetime import datetime, timezone

summary_json, summary_md, unit_glob, inst_glob, contract_json = sys.argv[1:]

def parse_junit(files):
    total = failures = errors = skipped = 0
    for f in files:
        try:
            root = ET.parse(f).getroot()
        except Exception:
            continue
        attrs = root.attrib
        total += int(attrs.get("tests", 0))
        failures += int(attrs.get("failures", 0))
        errors += int(attrs.get("errors", 0))
        skipped += int(attrs.get("skipped", 0))
    passed = max(0, total - failures - errors - skipped)
    return {
        "files": len(files),
        "tests": total,
        "passed": passed,
        "failures": failures,
        "errors": errors,
        "skipped": skipped,
        "ok": (total > 0 and failures == 0 and errors == 0),
    }

unit_files = glob.glob(unit_glob)
inst_files = glob.glob(inst_glob)
unit = parse_junit(unit_files)
inst = parse_junit(inst_files)

contract = {"ok": False, "reason": "missing"}
if os.path.isfile(contract_json):
    try:
        payload = json.load(open(contract_json, encoding="utf-8"))
        contract = {
            "ok": bool(payload.get("passed", False)),
            "checked_files": payload.get("checked_files", []),
            "errors": payload.get("errors", []),
        }
    except Exception as e:
        contract = {"ok": False, "reason": f"invalid_json:{e}"}

overall_ok = unit["ok"] and inst["ok"] and contract.get("ok", False)
ts = datetime.now(timezone.utc).isoformat()

summary = {
    "state_version": 1,
    "generated_at": ts,
    "overall_ok": overall_ok,
    "checks": {
        "unit_tests": unit,
        "instrumentation_tests": inst,
        "mobile_contract_check": contract,
    },
}

with open(summary_json, "w", encoding="utf-8") as f:
    json.dump(summary, f, ensure_ascii=False, indent=2)
    f.write("\n")

md = []
md.append("# Final Signoff Summary")
md.append("")
md.append(f"- Generated at: {ts}")
md.append(f"- Overall: {'PASS' if overall_ok else 'FAIL'}")
md.append("")
md.append("## Unit Tests")
md.append(f"- Files: {unit['files']}")
md.append(f"- Tests: {unit['tests']}, Passed: {unit['passed']}, Failures: {unit['failures']}, Errors: {unit['errors']}, Skipped: {unit['skipped']}")
md.append(f"- Status: {'PASS' if unit['ok'] else 'FAIL'}")
md.append("")
md.append("## Instrumentation Tests")
md.append(f"- Files: {inst['files']}")
md.append(f"- Tests: {inst['tests']}, Passed: {inst['passed']}, Failures: {inst['failures']}, Errors: {inst['errors']}, Skipped: {inst['skipped']}")
md.append(f"- Status: {'PASS' if inst['ok'] else 'FAIL'}")
md.append("")
md.append("## Mobile Contract Check")
md.append(f"- Status: {'PASS' if contract.get('ok', False) else 'FAIL'}")
if contract.get("checked_files"):
    md.append(f"- Checked files: {', '.join(contract['checked_files'])}")
if contract.get("errors"):
    md.append("- Errors:")
    for e in contract["errors"]:
        md.append(f"  - {e}")

with open(summary_md, "w", encoding="utf-8") as f:
    f.write("\n".join(md) + "\n")
PY

echo "PASS final signoff summary generated"
echo "json=$SUMMARY_JSON"
echo "md=$SUMMARY_MD"
