<?php
/**
 * Shared styles for rekap pages (sales_do_rekap, ap_rekap, target_rekap, gl_rekap)
 */
$rekapExtraHead = <<<'CSS'
<style>
  .excel-surface{background:linear-gradient(135deg,#0f172a 0%,#1e293b 50%,#0f172a 100%);border:1px solid rgba(6,182,212,.25);border-radius:16px;color:#e2e8f0;position:relative;overflow:hidden;isolation:isolate;box-shadow:0 4px 24px rgba(0,0,0,.4),inset 0 1px 0 rgba(255,255,255,.03)}
  .excel-surface::before,.excel-surface::after{content:"";position:absolute;width:400px;height:400px;border-radius:50%;filter:blur(80px);opacity:.12;z-index:-1;pointer-events:none;animation:drift 18s ease-in-out infinite}
  .excel-surface::before{background:linear-gradient(135deg,#06b6d4,#3b82f6);top:-180px;right:-100px}
  .excel-surface::after{background:linear-gradient(135deg,#8b5cf6,#06b6d4);bottom:-180px;left:-180px;animation-delay:3s}
  .excel-card{background:rgba(15,23,42,.7);border:1px solid rgba(6,182,212,.2);border-radius:12px;backdrop-filter:blur(10px);box-shadow:0 2px 12px rgba(0,0,0,.2);transition:transform .25s ease,box-shadow .25s ease,border-color .25s ease}
  .excel-card:hover{transform:translateY(-3px);box-shadow:0 12px 32px rgba(0,0,0,.35),0 0 0 1px rgba(6,182,212,.15);border-color:rgba(6,182,212,.35)}
  .excel-title{background:linear-gradient(90deg,rgba(6,182,212,.25) 0%,rgba(59,130,246,.15) 100%);color:#06b6d4;font-weight:700;padding:12px 16px;border-bottom:1px solid rgba(6,182,212,.2);font-family:'Segoe UI',system-ui,sans-serif;letter-spacing:.5px;text-transform:uppercase;font-size:12px}
  .excel-table{width:100%;border-collapse:collapse;font-size:13px;font-family:'Segoe UI',system-ui,sans-serif;color:#e2e8f0}
  .excel-table th,.excel-table td{border:1px solid rgba(6,182,212,.15);padding:8px 12px;line-height:1.35}
  .excel-table th{background:rgba(6,182,212,.12);text-align:center;white-space:nowrap;font-weight:700;color:#67e8f9}
  .excel-table td{background:rgba(30,41,59,.4);color:#cbd5e1}
  .excel-table td.num{text-align:right;font-variant-numeric:tabular-nums}
  .btn-outline-cyan{border-color:#06b6d4;color:#06b6d4}
  .btn-outline-cyan:hover{background:rgba(6,182,212,.2);border-color:#06b6d4;color:#67e8f9}
  .excel-surface .form-control,.excel-surface .form-select{background:rgba(30,41,59,.8);color:#e2e8f0;border:1px solid rgba(6,182,212,.3)}
  .excel-surface .form-label{color:#94a3b8}
  body.rekap-page{background:#0f172a!important;color:#e2e8f0}
  body.rekap-page .container-fluid{background:transparent}
  @keyframes drift{0%,100%{transform:translate3d(0,0,0) scale(1)}50%{transform:translate3d(20px,-15px,0) scale(1.05)}}
</style>
CSS;
