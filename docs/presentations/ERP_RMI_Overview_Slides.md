---
title: "ERP RMI — Rizqullah Mediska Indonesia"
subtitle: "Menu • Modul • Workflow"
author: "RMI"
date: "2026"
---

# ERP RMI
## Rizqullah Mediska Indonesia
### Menu • Modul • Workflow — Unified Layout
### Multi Branch + Depo • O2C • RBAC

---

# Struktur Kantor RMI
## 6 Branch + 2 Depo

| Tipe | Kode | Lokasi |
|------|------|--------|
| Branch | BGR | Bogor |
| Branch | BKS | Bekasi |
| Branch | TGR | Tangerang |
| Branch | BDG | Bandung |
| Branch | SLO | Solo |
| Branch | SMG | Semarang |
| Depo | KAL | Kalimantan |
| Depo | JGY | Jogyakarta |

---

# Menu ERP — Overview

- **MAIN** — Dashboards, Chat, Help, Exec Summary, Quality
- **MASTER DATA** — Products, Customers, Vendors, Employees
- **CRM** — Sales DO, Leads, Control Tower
- **PQP** — Purchases, PO, GR, AP, Forwarder
- **WQS** — Stock, Incoming, Picking, PR, Transfer
- **HRL** — Docs, Process, Reg Alkes, Absensi, KPI
- **FIN** — Payroll, Rekening Perusahaan
- **MPR** — Marketing Project, Budget Approval
- **ACT** — Fixed Asset
- **SETTINGS** — RBAC, Tools

---

# MAIN

| Menu | Fungsi |
|------|--------|
| Dashboards | Dashboard Center per dept |
| Internal Chat | Chat internal, channels |
| Help Center | Manual, SOP, Office Pack |
| Executive Summary | Ringkasan bisnis Owner (SYS) |
| Quality & Complaint | Incoming QC, lot/serial/exp |

---

# Master Data

- Products, Customers, Vendors, Manufactures
- Employees, Pricelist Jual/Buy
- Offices, Departements, Tax, Payment Terms
- ITC Reset Password, Account Readiness
- System Login, Config, Company Bank Accounts

---

# CRM (Sales)

**Alur:** Sales DO → WQS → SCM → ACT → FIN

| Stage | Fungsi |
|-------|--------|
| CRM | Buat DO, input/approval |
| WQS | Picking/packing |
| SCM | Logistik & forwarding |
| ACT | Cek accounting |
| FIN | Invoice & pembayaran |

Control Tower, Tax Invoice, CRM Leads

---

# PQP & WQS

**PQP (Purchases):** PO, GR, Invoice AP, Payment AP, Forwarder, CEISA PIB, Bank Recon

**WQS (Stock):** Stock, Incoming, Allocation, Picking, PR, Transfer, Adjustment, Opname, Audit

---

# HRL

| Modul | Fungsi |
|-------|--------|
| HRL Docs | Dokumen, Ack Report, Tower |
| HRL Process | Request, PIN approval, Tower |
| Reg Alkes | 15 tahap, NIE, SKU, Expiry |
| Absensi | Check-in/out, Izin, Approval |
| KPI Center | Daily, Monthly, Employee, Office, Stock, DO Audit/SLA |

---

# FIN • MPR • ACT

**FIN:** Payroll, Rekening Perusahaan

**MPR:** Plans, Budget FIN, Ops Daily

**ACT (Fixed Asset):** Assets, Ops, Depreciation, Audit, Tax Annual, Disposals, Transfers

---

# O2C — Order to Cash

DRAFT → crm_to_wqs → wqs_processing → ready_scm → on_delivery → delivered → wait_payment → paid → closed

| Status | Dept | Aksi |
|--------|------|------|
| DRAFT | CRM | Submit DO |
| wqs_processing | WQS | Foto stok, READY SCM |
| on_delivery | SCM | DELIVERED + POD |
| wait_payment | ACT/FIN | Tukar faktur, bayar |
| paid | FIN | Selesai |

---

# RBAC — Role & Permission

**Role:** manager | staff | sys

| Modul | Permission |
|-------|------------|
| Master | MASTER.VIEW |
| Sales | SALES.VIEW, DASHBOARD.SALES_VIEW |
| HRL | HRL.PROCESS_VIEW, HRL.REG_ALKES_VIEW |
| Absensi | ABSENSI.VIEW, ABSENSI.CHECKIN, ABSENSI.APPROVE |
| KPI | KPI.VIEW |
| Quality | DASHBOARD.QUALITY_VIEW |

---

# Settings

**RBAC** — Role, permission, user–permission

**Tools** — Backup, Health, Migration, QA, Ops, Release, Audit

---

# Terima Kasih

## ERP RMI — Rizqullah Mediska Indonesia

Referensi: docs/ERP_MENU_WORKFLOW_REFERENCE.md
