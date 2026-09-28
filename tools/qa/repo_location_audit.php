<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }
$argv  = $_SERVER['argv'] ?? [];
$write = in_array('--write-last', $argv, true);
$root  = (string)(realpath(__DIR__ . '/../..') ?: dirname(__DIR__, 2));
$canonical     = '/volume4/web/ERP_RMI_SOFULL';
$canonicalReal = file_exists($canonical) ? (string)realpath($canonical) : '';
$osFamily = PHP_OS_FAMILY;
if ($osFamily === 'Darwin') {
    $r = ['state_version'=>'repo_location_audit_v1','generated_at'=>date('c'),'os_family'=>$osFamily,'canonical_root'=>$canonical,'canonical_exists'=>false,'candidates'=>[],'duplicates_found'=>false,'mismatch_summary'=>[],'overall_ok'=>false,'stop_reason'=>'STOP: macOS detected. Run via SSH on NAS.'];
    if ($write) { @mkdir($root.'/storage/logs',0775,true); file_put_contents($root.'/storage/logs/repo_location_audit_last.json',json_encode($r,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)); }
    echo json_encode($r,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL; exit(2);
}
function file_sha256(string $p): string { if (!is_file($p)) return 'MISSING'; $h=hash_file('sha256',$p,false); return $h!==false?substr($h,0,12):'ERR'; }
function is_erp_candidate(string $d): bool { return is_dir($d)&&is_file($d.'/tools/qa/run_cutover_checks.php')&&is_file($d.'/master/login.php')&&is_file($d.'/tools/index.php'); }
function du_mb(string $d): int { $o=[]; @exec('du -sm '.escapeshellarg($d).' 2>/dev/null',$o); return isset($o[0])?(int)explode("\t",$o[0])[0]:-1; }
function git_info(string $d): string { $b=[]; $c=[]; @exec('git -C '.escapeshellarg($d).' rev-parse --abbrev-ref HEAD 2>/dev/null',$b); @exec('git -C '.escapeshellarg($d).' rev-parse --short HEAD 2>/dev/null',$c); return ($b&&$c)?($b[0].'@'.$c[0]):'no_git'; }
$scan=[]; foreach(glob('/volume*/web/*',GLOB_ONLYDIR|GLOB_NOSORT)?:[] as $p) $scan[]=$p;
foreach(glob('/volume*/web/*/*',GLOB_ONLYDIR|GLOB_NOSORT)?:[] as $p) $scan[]=$p;
$scan[]='/Volumes/web/ERP_RMI_SOFULL';
foreach(glob('/volume*/web/ERP_RMI_SOFULL',GLOB_NOSORT)?:[] as $p) $scan[]=$p;
$scan=array_unique($scan);
$KEY=['tools/qa/run_cutover_checks.php','tools/qa/smoke_http.php','tools/_shared/tools_bootstrap.php','docs/governance/INDEX.md'];
$candidates=[];$seen=[];
foreach($scan as $dir) {
    if (!is_erp_candidate($dir)) continue;
    $real=(string)(realpath($dir)?:$dir);
    if (isset($seen[$real])) continue; $seen[$real]=true;
    $cs=[];foreach($KEY as $k) $cs[$k]=file_sha256($dir.'/'.$k);
    $candidates[]=['path'=>$dir,'realpath'=>$real,'is_symlink'=>is_link($dir),'link_target'=>is_link($dir)?(string)readlink($dir):null,'du_size_mb'=>du_mb($dir),'git_info'=>git_info($dir),'is_canonical'=>($real===$canonicalReal&&$canonicalReal!==''),'key_file_checksums'=>$cs];
}
$canonicalEntry=null; foreach($candidates as $c) if ($c['is_canonical']) $canonicalEntry=$c;
$logsDir=$root.'/storage/logs'; @mkdir($logsDir,0775,true);
$mismatch=[]; $govDir=$root.'/docs/governance'; @mkdir($govDir,0775,true);
foreach($candidates as $c) {
    if ($c['is_canonical']||!$canonicalEntry) continue;
    $slug=preg_replace('#[^a-z0-9]+#i','_',trim($c['path'],'/'));
    $df=$logsDir.'/repo_location_diff_'.$slug.'.txt';
    $out=[]; @exec('rsync -a --dry-run --delete --itemize-changes '.escapeshellarg($canonicalEntry['realpath'].'/tools/').' '.escapeshellarg($c['realpath'].'/tools/').' 2>&1',$out);
    file_put_contents($df,implode("\n",$out));
    $mismatch[$c['path']]=['diff_file'=>'[APP_ROOT]/storage/logs/'.basename($df),'has_changes'=>!empty($out)];
}
$dup=count($candidates)>1; $ok=$canonicalEntry!==null&&!$dup;
$result=['state_version'=>'repo_location_audit_v1','generated_at'=>date('c'),'os_family'=>$osFamily,'canonical_root'=>$canonical,'canonical_exists'=>$canonicalEntry!==null,'candidates'=>$candidates,'duplicates_found'=>$dup,'mismatch_summary'=>$mismatch,'overall_ok'=>$ok];
if ($write) file_put_contents($logsDir.'/repo_location_audit_last.json',json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
$md="# Repo Location Report\n\n_".date('c')."_\n\n## Canonical\n\`$canonical\` — ".($canonicalEntry?'**EXISTS**':'**NOT FOUND**')."\n\n## Candidates (".count($candidates).")\n\n";
foreach($candidates as $c) $md.="### `{$c['path']}`".($c['is_canonical']?' ✅ CANONICAL':' ⚠️ DUPLICATE')."\n- realpath: `{$c['realpath']}`\n- size: {$c['du_size_mb']} MB | git: `{$c['git_info']}`\n\n";
$md.="## Overall: ".($ok?'**OK**':'**FAIL**')."\n";
file_put_contents($govDir.'/REPO_LOCATION_REPORT.md',$md);
echo json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
exit($ok?0:2);
