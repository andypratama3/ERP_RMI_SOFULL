#!/usr/bin/env python3
"""
Konversi Master_Customers_RSHermina_Template.xlsx ke CSV untuk import ERP.
Kolom "email keuangan/farmasi" dipetakan ke "email".
Output: CSV dengan header sesuai master_import_customers.

Usage:
  python3 hermina_excel_to_csv.py "path/to/Master_Customers_RSHermina_Template.xlsx"
  python3 hermina_excel_to_csv.py "path/to/file.xlsx" -o output.csv
"""
import csv
import sys
from pathlib import Path

try:
    import openpyxl
except ImportError:
    print("Error: openpyxl required. Install: pip install openpyxl")
    sys.exit(1)

# Mapping: Excel header -> CSV header (untuk kolom yang beda nama)
HEADER_MAP = {
    "email keuangan/farmasi": "email",
}

# Urutan kolom output (sesuai master_import_customers)
OUTPUT_HEADERS = [
    "customers_code", "customers_name", "category", "segment", "city",
    "office_code", "cover_area", "address", "maps_url", "phone", "email",
    "npwp", "status",
]


def main():
    args = sys.argv[1:]
    if not args:
        print("Usage: python3 hermina_excel_to_csv.py <input.xlsx> [-o output.csv]")
        sys.exit(1)

    infile = args[0]
    outfile = None
    if "-o" in args:
        idx = args.index("-o")
        if idx + 1 < len(args):
            outfile = args[idx + 1]

    if not Path(infile).exists():
        print(f"Error: File not found: {infile}")
        sys.exit(1)

    if outfile is None:
        outfile = str(Path(infile).with_suffix(".csv"))

    wb = openpyxl.load_workbook(infile, read_only=True, data_only=True)
    ws = wb.active
    rows = list(ws.iter_rows(values_only=True))
    wb.close()

    if not rows:
        print("Error: Excel file is empty")
        sys.exit(1)

    # Header row
    excel_headers = [str(h).strip() if h is not None else "" for h in rows[0]]
    # Normalize: lowercase for lookup
    excel_headers_lower = [h.lower() for h in excel_headers]

    # Build mapping: output_col -> value from excel
    def get_value(row, col_name):
        if col_name in excel_headers_lower:
            idx = excel_headers_lower.index(col_name)
            v = row[idx] if idx < len(row) else None
            return "" if v is None else str(v).strip()
        # Check aliases
        for excel_header, csv_header in HEADER_MAP.items():
            if csv_header == col_name and excel_header.lower() in excel_headers_lower:
                idx = excel_headers_lower.index(excel_header.lower())
                v = row[idx] if idx < len(row) else None
                return "" if v is None else str(v).strip()
        return ""

    # Generate unique customers_code per cabang (jika duplikat, append slug dari city/nama)
    def slug_from_row(row):
        city = get_value(row, "city")
        name = get_value(row, "customers_name")
        for s in (city, name):
            if s and s.strip():
                parts = s.strip().split()
                if parts:
                    slug = "".join(c for c in parts[-1].upper() if c.isalnum())[:12]
                    if slug:
                        return slug
        return "X"

    seen_codes = set()
    out_rows = []
    dup_fixed = 0
    for row in rows[1:]:
        if not row or all(v is None or str(v).strip() == "" for v in row):
            continue
        code = get_value(row, "customers_code").strip().upper()
        if not code:
            code = "H-" + slug_from_row(row)
        orig = code
        n = 1
        if code in seen_codes:
            dup_fixed += 1
        while code in seen_codes:
            code = f"{orig}-{slug_from_row(row)}" if n == 1 else f"{orig}-{n}"
            n += 1
        seen_codes.add(code)
        out_row = [get_value(row, h) for h in OUTPUT_HEADERS]
        out_row[0] = code  # customers_code
        out_rows.append(out_row)

    with open(outfile, "w", newline="", encoding="utf-8-sig") as f:
        writer = csv.writer(f)
        writer.writerow(OUTPUT_HEADERS)
        writer.writerows(out_rows)

    dup_msg = f" ({dup_fixed} kode duplikat dibuat unik)" if dup_fixed else ""
    print(f"OK: {outfile} ({len(out_rows)} rows){dup_msg}")
    print("Next: Master Data → Import Customers → Upload CSV")


if __name__ == "__main__":
    main()
