#!/usr/bin/php
<?php
/**
 * MS3TI CONSULTORIA EM TECNOLOGIA LTDA
 * https://www.ms3ti.com.br
 * Desenvolvido por EDERSON MARQUES
 *
 * Monitor WD ShareSpace - PHP 4.4.2 / mini_httpd CGI
 * Executa LOCALMENTE no storage WD ShareSpace (kernel 2.6.12.6-arm1)
 *
 * IMPORTANTE: Este arquivo deve ser executavel (chmod +x)
 * O mini_httpd executa .php como CGI, entao os headers HTTP
 * sao emitidos manualmente via echo.
 *
 * Uso:
 *   Navegador: http://IP_STORAGE/wd_sharespace_monitor.php
 *   Zabbix:    http://IP_STORAGE/wd_sharespace_monitor.php?mode=zabbix&metric=NOME
 */

error_reporting(0);

$cache_file = '/tmp/wd_monitor_cache.dat';
$cache_ttl  = 55;

// ============================================================
// FUNCOES
// ============================================================

function read_local_file($path)
{
    $fp = @fopen($path, 'r');
    if (!$fp) { return ''; }
    $content = '';
    while (!feof($fp)) {
        $buf = @fread($fp, 4096);
        if ($buf === false) { break; }
        $content .= $buf;
    }
    @fclose($fp);
    return $content;
}

function run_cmd($cmd)
{
    $out = array();
    $ret = 0;
    // mini_httpd CGI tem PATH limitado, forcar PATH completo
    @exec('PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin ' . $cmd . ' 2>/dev/null', $out, $ret);
    return implode("\n", $out);
}

function parse_mem_val($block, $key)
{
    $pos = strpos($block, $key);
    if ($pos === false) { return 0; }
    $sub = substr($block, $pos + strlen($key));
    $sub = trim($sub);
    if (substr($sub, 0, 1) == ':') { $sub = trim(substr($sub, 1)); }
    $val = '';
    for ($i = 0; $i < strlen($sub); $i++) {
        $c = substr($sub, $i, 1);
        if ($c >= '0' && $c <= '9') { $val .= $c; } else { break; }
    }
    if ($val == '') { return 0; }
    return intval($val);
}

function format_kb($kb)
{
    if ($kb >= 1048576) { return round($kb / 1048576, 2) . ' TB'; }
    if ($kb >= 1024) { return round($kb / 1024, 1) . ' MB'; }
    return $kb . ' KB';
}

function format_kb_disk($kb)
{
    if ($kb >= 1073741824) { return round($kb / 1073741824, 2) . ' TB'; }
    if ($kb >= 1048576) { return round($kb / 1048576, 2) . ' GB'; }
    if ($kb >= 1024) { return round($kb / 1024, 1) . ' MB'; }
    return $kb . ' KB';
}

function status_color($status)
{
    $s = strtoupper($status);
    if ($s == 'OK' || $s == 'PASSED') { return '#27ae60'; }
    if (strpos($s, 'DEGRAD') !== false || $s == 'FAILED') { return '#e74c3c'; }
    if (strpos($s, 'REBUILD') !== false || $s == 'UNKNOWN') { return '#f39c12'; }
    return '#95a5a6';
}

function bar_color($pct)
{
    if ($pct >= 90) { return '#e74c3c'; }
    if ($pct >= 75) { return '#f39c12'; }
    return '#27ae60';
}

function parse_md_block($md_name, $block)
{
    $vol = array();
    $vol['name']    = $md_name;
    $vol['level']   = 'unknown';
    $vol['status']  = 'unknown';
    $vol['state']   = 'unknown';
    $vol['devices'] = '';
    $vol['mount']   = '';
    $vol['role']    = '';

    // Nivel
    if (strpos($block, 'raid0') !== false) { $vol['level'] = 'RAID0'; }
    elseif (strpos($block, 'raid5') !== false) { $vol['level'] = 'RAID5'; }
    elseif (strpos($block, 'raid10') !== false) { $vol['level'] = 'RAID10'; }
    elseif (strpos($block, 'raid1') !== false) { $vol['level'] = 'RAID1'; }
    elseif (strpos($block, 'linear') !== false) { $vol['level'] = 'JBOD'; }

    // Dispositivos
    if (preg_match('/md[0-9]+[ ]*:[ ]*active[ ]+[^ ]+[ ]+(.+)/', $block, $m)) {
        $devs = trim($m[1]);
        // Remove linhas extras (pode ter quebra)
        $nl = strpos($devs, "\n");
        if ($nl !== false) { $devs = substr($devs, 0, $nl); }
        $vol['devices'] = trim($devs);
    }

    // State [UU] ou [U_]
    if (preg_match('/\[([U_]+)\]/', $block, $m)) {
        $vol['state'] = $m[1];
        if (strpos($m[1], '_') !== false) { $vol['status'] = 'DEGRADED'; }
        else { $vol['status'] = 'OK'; }
    }

    // RAID0 nao tem [UU], se ativo e sem erro = OK
    if ($vol['level'] == 'RAID0' && $vol['status'] == 'unknown') {
        if (strpos($block, 'active') !== false) {
            $vol['status'] = 'OK';
            $vol['state'] = 'active';
        }
    }

    // Rebuild
    if (strpos($block, 'recovery') !== false || strpos($block, 'resync') !== false) {
        $vol['status'] = 'REBUILDING';
        if (preg_match('/([0-9]+\.[0-9]+)%/', $block, $m)) {
            $vol['status'] = 'REBUILDING (' . $m[1] . '%)';
        }
    }

    return $vol;
}

// ============================================================
// COLETA LOCAL
// ============================================================

function collect_data()
{
    $d = array();
    $d['timestamp'] = time();
    $d['errors']    = array();

    $d['hostname'] = trim(read_local_file('/proc/sys/kernel/hostname'));
    if ($d['hostname'] == '') { $d['hostname'] = trim(run_cmd('hostname')); }

    $d['uname'] = trim(run_cmd('uname -a'));

    // Uptime
    $raw = trim(read_local_file('/proc/uptime'));
    $d['uptime_seconds'] = 0;
    $d['uptime_human']   = 'N/A';
    if ($raw != '') {
        $parts = explode(' ', $raw);
        $sec = intval($parts[0]);
        $d['uptime_seconds'] = $sec;
        $d['uptime_human'] = intval($sec/86400).'d '.intval(($sec%86400)/3600).'h '.intval(($sec%3600)/60).'m';
    }

    // Load
    $raw = trim(read_local_file('/proc/loadavg'));
    $d['load1'] = '0'; $d['load5'] = '0'; $d['load15'] = '0';
    if ($raw != '') {
        $parts = explode(' ', $raw);
        if (isset($parts[0])) { $d['load1']  = $parts[0]; }
        if (isset($parts[1])) { $d['load5']  = $parts[1]; }
        if (isset($parts[2])) { $d['load15'] = $parts[2]; }
    }

    // Memoria
    $meminfo = read_local_file('/proc/meminfo');
    $d['mem_total']   = parse_mem_val($meminfo, 'MemTotal');
    $d['mem_free']    = parse_mem_val($meminfo, 'MemFree');
    $d['mem_buffers'] = parse_mem_val($meminfo, 'Buffers');
    $d['mem_cached']  = parse_mem_val($meminfo, 'Cached');
    $d['mem_used']    = $d['mem_total'] - $d['mem_free'] - $d['mem_buffers'] - $d['mem_cached'];
    if ($d['mem_used'] < 0) { $d['mem_used'] = $d['mem_total'] - $d['mem_free']; }
    if ($d['mem_total'] > 0) {
        $d['mem_percent'] = round(($d['mem_used'] / $d['mem_total']) * 100, 1);
    } else {
        $d['mem_percent'] = 0;
    }

    // Disco
    $df = run_cmd('df -k');
    $d['disks'] = array();
    $d['disk_total'] = 0; $d['disk_used'] = 0; $d['disk_free'] = 0; $d['disk_percent'] = 0;
    if ($df != '') {
        $lines = explode("\n", $df);
        for ($i = 0; $i < count($lines); $i++) {
            if (strpos($lines[$i], '/dev/') === false) { continue; }
            $cols = preg_split('/[\s]+/', trim($lines[$i]));
            if (count($cols) >= 6) {
                $entry = array();
                $entry['device'] = $cols[0]; $entry['total'] = intval($cols[1]);
                $entry['used'] = intval($cols[2]); $entry['free'] = intval($cols[3]);
                $entry['percent'] = $cols[4]; $entry['mount'] = $cols[5];
                $d['disks'][] = $entry;
                if (strpos($cols[5], '/DataVolume') !== false || strpos($cols[5], '/shares') !== false) {
                    $d['disk_total'] = intval($cols[1]);
                    $d['disk_used']  = intval($cols[2]);
                    $d['disk_free']  = intval($cols[3]);
                }
            }
        }
    }
    if ($d['disk_total'] > 0) {
        $d['disk_percent'] = round(($d['disk_used'] / $d['disk_total']) * 100, 1);
    }

    // RAID - coleta cada md array individualmente + LVM
    $mdstat = read_local_file('/proc/mdstat');
    $d['raid_detail'] = trim($mdstat);
    $d['volumes'] = array();

    // Parse mdstat por blocos (cada md)
    if (trim($mdstat) != '') {
        $md_lines = explode("\n", $mdstat);
        $current_md = '';
        $current_block = '';
        for ($ml = 0; $ml < count($md_lines); $ml++) {
            $line = $md_lines[$ml];
            if (preg_match('/^(md[0-9]+)[ ]*:/', $line, $mm)) {
                // Salva bloco anterior
                if ($current_md != '') {
                    $d['volumes'][] = parse_md_block($current_md, $current_block);
                }
                $current_md = $mm[1];
                $current_block = $line . "\n";
            } elseif ($current_md != '') {
                $current_block .= $line . "\n";
            }
        }
        if ($current_md != '') {
            $d['volumes'][] = parse_md_block($current_md, $current_block);
        }
    }

    // Complementa com mdadm --detail para cada md
    for ($vi = 0; $vi < count($d['volumes']); $vi++) {
        $vname = $d['volumes'][$vi]['name'];
        $mdadm = run_cmd('mdadm --detail /dev/' . $vname);
        if (trim($mdadm) != '') {
            if (preg_match('/State[ ]*:[ ]*(.+)/i', $mdadm, $m)) {
                $state = strtolower(trim($m[1]));
                if ($d['volumes'][$vi]['status'] == 'unknown') {
                    if (strpos($state, 'clean') !== false || strpos($state, 'active') !== false) {
                        $d['volumes'][$vi]['status'] = 'OK';
                    } elseif (strpos($state, 'degrad') !== false) {
                        $d['volumes'][$vi]['status'] = 'DEGRADED';
                    }
                }
            }
        }
        // Identifica funcao pelo mount point
        $mount = '';
        for ($di = 0; $di < count($d['disks']); $di++) {
            if (strpos($d['disks'][$di]['device'], $vname) !== false) {
                $mount = $d['disks'][$di]['mount'];
                break;
            }
        }
        $d['volumes'][$vi]['mount'] = $mount;
        if (strpos($mount, '/DataVolume') !== false || strpos($mount, '/shares') !== false) {
            $d['volumes'][$vi]['role'] = 'DataVolume';
        } elseif ($mount == '/' || $mount == '/old') {
            $d['volumes'][$vi]['role'] = 'Sistema';
        } else {
            $d['volumes'][$vi]['role'] = '';
        }
    }

    // LVM - /dev/vg1/lv1 (ExtendVolume / JBOD/Span)
    $lv_mount = '';
    $lv_found = false;
    for ($di = 0; $di < count($d['disks']); $di++) {
        if (strpos($d['disks'][$di]['device'], '/dev/vg') !== false) {
            $lv_found = true;
            $lv_mount = $d['disks'][$di]['mount'];
            break;
        }
    }
    if ($lv_found) {
        $lv = array();
        $lv['name'] = 'vg1/lv1';
        $lv['level'] = 'SPAN/JBOD';
        $lv['status'] = 'OK';
        $lv['state'] = 'active';
        $lv['devices'] = '';
        $lv['mount'] = $lv_mount;
        $lv['role'] = 'ExtendVolume';
        // Tenta pegar PVs do VG
        $pvs = run_cmd('pvs --noheadings -o pv_name,vg_name');
        if ($pvs != '') {
            $pv_devs = array();
            $pv_lines = explode("\n", $pvs);
            for ($pl = 0; $pl < count($pv_lines); $pl++) {
                $pv_cols = preg_split('/[\s]+/', trim($pv_lines[$pl]));
                if (count($pv_cols) >= 2 && strpos($pv_cols[1], 'vg1') !== false) {
                    $pv_devs[] = $pv_cols[0];
                }
            }
            $lv['devices'] = implode(' ', $pv_devs);
        }
        // Verifica se LV esta ativo
        $lv_info = run_cmd('lvs --noheadings -o lv_attr vg1/lv1');
        if (trim($lv_info) != '') {
            // Attr: -wi-a- (a=active)
            if (strpos($lv_info, 'a') !== false) { $lv['status'] = 'OK'; }
            else { $lv['status'] = 'INACTIVE'; }
        }
        $d['volumes'][] = $lv;
    }

    // Metricas flat para Zabbix (compatibilidade + novos por volume)
    // md0 = sistema, md2 = DataVolume, vg1 = ExtendVolume
    $d['raid_md0_status'] = 'N/A'; $d['raid_md0_level'] = 'N/A'; $d['raid_md0_state'] = 'N/A';
    $d['raid_md2_status'] = 'N/A'; $d['raid_md2_level'] = 'N/A'; $d['raid_md2_state'] = 'N/A';
    $d['raid_lvm_status'] = 'N/A';
    $d['raid_status'] = 'OK'; // status geral

    for ($vi = 0; $vi < count($d['volumes']); $vi++) {
        $vol = $d['volumes'][$vi];
        if ($vol['name'] == 'md0') {
            $d['raid_md0_status'] = $vol['status'];
            $d['raid_md0_level']  = $vol['level'];
            $d['raid_md0_state']  = $vol['state'];
        } elseif ($vol['name'] == 'md2') {
            $d['raid_md2_status'] = $vol['status'];
            $d['raid_md2_level']  = $vol['level'];
            $d['raid_md2_state']  = $vol['state'];
        } elseif (strpos($vol['name'], 'vg') !== false) {
            $d['raid_lvm_status'] = $vol['status'];
        }
        // Status geral: pior status de todos
        $vs = strtoupper($vol['status']);
        if (strpos($vs, 'DEGRAD') !== false || $vs == 'FAILED') {
            $d['raid_status'] = 'DEGRADED';
        } elseif (strpos($vs, 'REBUILD') !== false && $d['raid_status'] != 'DEGRADED') {
            $d['raid_status'] = 'REBUILDING';
        }
    }

    // Temperatura - sem /sys neste kernel, usa hddtemp e smartctl
    $d['temp_cpu'] = 0; $d['temp_hdd'] = 0;
    $temps = array();

    // Tenta /proc para temp do CPU (alguns kernels ARM)
    $proc_temp_paths = array(
        '/proc/driver/temp1',
        '/proc/driver/temp2',
        '/proc/acpi/thermal_zone/THRM/temperature'
    );
    for ($t = 0; $t < count($proc_temp_paths); $t++) {
        $val = trim(read_local_file($proc_temp_paths[$t]));
        if ($val != '') {
            // Pode vir como "temperature: 45 C" ou apenas numero
            if (preg_match('/([0-9]+)/', $val, $tm)) {
                $num = intval($tm[1]);
                if ($num > 1000) { $num = intval($num / 1000); }
                if ($num > 0 && $num < 120) { $temps[] = $num; }
            }
        }
    }

    // Temperatura dos HDDs via hddtemp (rapido, sem overhead)
    $hdd_disks = array('sda', 'sdb', 'sdc', 'sdd');
    $hdd_temps = array();
    for ($h = 0; $h < count($hdd_disks); $h++) {
        $ht = trim(run_cmd('hddtemp -n /dev/' . $hdd_disks[$h]));
        if ($ht != '' && intval($ht) > 0 && intval($ht) < 120) {
            $hdd_temps[] = intval($ht);
        }
    }

    // Se hddtemp falhou, tenta smartctl -A (atributo 194 = Temperature)
    if (count($hdd_temps) == 0) {
        for ($h = 0; $h < count($hdd_disks); $h++) {
            $sout = run_cmd('/usr/sbin/smartctl -A /dev/' . $hdd_disks[$h]);
            if ($sout != '') {
                // Formato smartctl 5.1: "194 Temperature_Celsius ... -       36"
                // Pega ultima coluna da linha que contem Temperature
                $slines = explode("\n", $sout);
                for ($sl = 0; $sl < count($slines); $sl++) {
                    if (strpos(strtolower($slines[$sl]), 'temp') !== false) {
                        // Pega o ultimo numero da linha
                        if (preg_match('/([0-9]+)\s*$/', trim($slines[$sl]), $tm)) {
                            $num = intval($tm[1]);
                            if ($num > 0 && $num < 120) {
                                $hdd_temps[] = $num;
                                break;
                            }
                        }
                    }
                }
            }
        }
    }

    // CPU temp = primeiro valor de /proc, HDD temp = media dos discos
    if (count($temps) > 0) { $d['temp_cpu'] = $temps[0]; }
    if (count($hdd_temps) > 0) {
        $sum = 0;
        for ($h = 0; $h < count($hdd_temps); $h++) { $sum += $hdd_temps[$h]; }
        $d['temp_hdd'] = intval($sum / count($hdd_temps));
        // Se nao tem temp de CPU, usa a maior temp de HDD como referencia
        if ($d['temp_cpu'] == 0) { $d['temp_cpu'] = $hdd_temps[0]; }
    }

    // Rede - detecta interface automaticamente (egiga0, eth0, etc)
    $netdev = read_local_file('/proc/net/dev');
    $d['net_rx_bytes'] = 0; $d['net_tx_bytes'] = 0; $d['net_iface'] = '';
    if ($netdev != '') {
        $lines = explode("\n", $netdev);
        for ($i = 0; $i < count($lines); $i++) {
            // Pula header e loopback
            if (strpos($lines[$i], '|') !== false) { continue; }
            if (strpos($lines[$i], 'lo') !== false) { continue; }
            $clean = str_replace(':', ' ', $lines[$i]);
            $parts = preg_split('/[\s]+/', trim($clean));
            if (count($parts) >= 11 && $parts[0] != '') {
                // Pega a primeira interface real com trafego
                $rx = $parts[1];
                if (intval($rx) > 0 || $d['net_iface'] == '') {
                    $d['net_iface']    = $parts[0];
                    $d['net_rx_bytes'] = $parts[1];
                    $d['net_tx_bytes'] = $parts[9];
                    if (intval($rx) > 0) { break; }
                }
            }
        }
    }

    // SMART - usa caminho absoluto (mini_httpd tem PATH limitado)
    $sdisks = array('sda', 'sdb', 'sdc', 'sdd');
    $smartctl_bin = '/usr/sbin/smartctl';

    // Detecta discos presentes via /proc/partitions
    $partitions = read_local_file('/proc/partitions');
    $present_disks = array();
    if ($partitions != '') {
        for ($pd = 0; $pd < count($sdisks); $pd++) {
            // Procura linha com "sda" (sem numero = disco inteiro)
            if (preg_match('/[ \t]' . $sdisks[$pd] . '[ \t\n]/', $partitions)) {
                $present_disks[$sdisks[$pd]] = 1;
            }
        }
    }

    for ($s = 0; $s < count($sdisks); $s++) {
        $dn = $sdisks[$s];

        if (!isset($present_disks[$dn])) {
            $d['disk_health_' . $dn] = 'EMPTY';
        } else {
            $health = 'N/A';
            $sout = run_cmd($smartctl_bin . ' -H /dev/' . $dn);
            if ($sout != '') {
                $sup = strtoupper($sout);
                if (strpos($sup, 'PASSED') !== false || strpos($sup, ': OK') !== false) { $health = 'OK'; }
                elseif (strpos($sup, 'FAILED') !== false) { $health = 'FAILED'; }
                else { $health = 'UNKNOWN'; }
            }
            $d['disk_health_' . $dn] = $health;
        }
    }

    return $d;
}

// ============================================================
// CACHE
// ============================================================

function load_cache($file, $ttl)
{
    if (!@file_exists($file)) { return false; }
    $mtime = @filemtime($file);
    if ((time() - $mtime) >= $ttl) { return false; }
    $fp = @fopen($file, 'r');
    if (!$fp) { return false; }
    $buf = '';
    while (!feof($fp)) { $buf .= fread($fp, 4096); }
    fclose($fp);
    if ($buf == '') { return false; }
    $arr = @unserialize($buf);
    if (!is_array($arr)) { return false; }
    return $arr;
}

function save_to_cache($file, $arr)
{
    $fp = @fopen($file, 'w');
    if ($fp) { fwrite($fp, serialize($arr)); fclose($fp); }
}

// ============================================================
// PARSE QUERY STRING (compativel com CGI/CLI/GET)
// ============================================================

$mode = '';
$metric = '';

// Tenta $_GET primeiro (modo web normal)
if (isset($_GET['mode'])) {
    $mode = $_GET['mode'];
    if (isset($_GET['metric'])) { $metric = $_GET['metric']; }
}

// Fallback: QUERY_STRING (mini_httpd CGI)
if ($mode == '' && isset($_SERVER['QUERY_STRING']) && $_SERVER['QUERY_STRING'] != '') {
    $qs_pairs = explode('&', $_SERVER['QUERY_STRING']);
    for ($q = 0; $q < count($qs_pairs); $q++) {
        $kv = explode('=', $qs_pairs[$q]);
        if (count($kv) == 2) {
            if ($kv[0] == 'mode') { $mode = $kv[1]; }
            if ($kv[0] == 'metric') { $metric = $kv[1]; }
        }
    }
}

// Fallback: argv (chamada CLI direta)
if ($mode == '' && isset($argv) && isset($argv[1])) {
    $cli_pairs = explode('&', $argv[1]);
    for ($q = 0; $q < count($cli_pairs); $q++) {
        $kv = explode('=', $cli_pairs[$q]);
        if (count($kv) == 2) {
            if ($kv[0] == 'mode') { $mode = $kv[1]; }
            if ($kv[0] == 'metric') { $metric = $kv[1]; }
        }
    }
}

// ============================================================
// COLETA (com cache)
// ============================================================

$data = load_cache($cache_file, $cache_ttl);
if ($data === false) {
    $data = collect_data();
    save_to_cache($cache_file, $data);
}

// ============================================================
// SAIDA - Headers CGI manuais (mini_httpd espera isso)
// ============================================================

if ($mode == 'zabbix') {

    $val = '';
    if ($metric == 'uptime')       { $val = $data['uptime_seconds']; }
    elseif ($metric == 'load1')    { $val = $data['load1']; }
    elseif ($metric == 'load5')    { $val = $data['load5']; }
    elseif ($metric == 'load15')   { $val = $data['load15']; }
    elseif ($metric == 'mem_total')   { $val = $data['mem_total']; }
    elseif ($metric == 'mem_used')    { $val = $data['mem_used']; }
    elseif ($metric == 'mem_free')    { $val = $data['mem_free']; }
    elseif ($metric == 'mem_percent') { $val = $data['mem_percent']; }
    elseif ($metric == 'disk_total')   { $val = $data['disk_total']; }
    elseif ($metric == 'disk_used')    { $val = $data['disk_used']; }
    elseif ($metric == 'disk_free')    { $val = $data['disk_free']; }
    elseif ($metric == 'disk_percent') { $val = $data['disk_percent']; }
    elseif ($metric == 'raid_status')     { $val = $data['raid_status']; }
    elseif ($metric == 'raid_md0_status') { $val = $data['raid_md0_status']; }
    elseif ($metric == 'raid_md0_level')  { $val = $data['raid_md0_level']; }
    elseif ($metric == 'raid_md0_state')  { $val = $data['raid_md0_state']; }
    elseif ($metric == 'raid_md2_status') { $val = $data['raid_md2_status']; }
    elseif ($metric == 'raid_md2_level')  { $val = $data['raid_md2_level']; }
    elseif ($metric == 'raid_md2_state')  { $val = $data['raid_md2_state']; }
    elseif ($metric == 'raid_lvm_status') { $val = $data['raid_lvm_status']; }
    elseif ($metric == 'temp_cpu')     { $val = $data['temp_cpu']; }
    elseif ($metric == 'temp_hdd')     { $val = $data['temp_hdd']; }
    elseif ($metric == 'net_rx_bytes') { $val = $data['net_rx_bytes']; }
    elseif ($metric == 'net_tx_bytes') { $val = $data['net_tx_bytes']; }
    elseif ($metric == 'disk_health_sda') { $val = $data['disk_health_sda']; }
    elseif ($metric == 'disk_health_sdb') { $val = $data['disk_health_sdb']; }
    elseif ($metric == 'disk_health_sdc') { $val = $data['disk_health_sdc']; }
    elseif ($metric == 'disk_health_sdd') { $val = $data['disk_health_sdd']; }
    elseif ($metric == 'hostname')     { $val = $data['hostname']; }
    else { $val = 'ERROR: metrica desconhecida'; }

    echo $val;
    return;
}

if ($mode == 'json') {
    $keys = array_keys($data);
    echo "{\n";
    for ($i = 0; $i < count($keys); $i++) {
        $k = $keys[$i];
        $v = $data[$k];
        if ($i > 0) { echo ",\n"; }
        if (is_array($v)) { echo '"' . $k . '": "array"'; }
        else { echo '"' . $k . '": "' . addslashes($v) . '"'; }
    }
    echo "\n}";
    return;
}

// ============================================================
// HTML
// ============================================================

$host_display = $data['hostname'];
if ($host_display == '') { $host_display = 'WD ShareSpace'; }

echo '<html>';
echo '<head>';
echo '<title>[MS3TI] WD ShareSpace Monitor - ' . htmlspecialchars($host_display) . '</title>';
echo '<meta http-equiv="refresh" content="60">';
echo '<meta http-equiv="Content-Type" content="text/html; charset=utf-8">';
echo '<style type="text/css">';
echo 'body{font-family:Verdana,Arial,sans-serif;font-size:12px;background:#1a1a2e;color:#e0e0e0;margin:10px}';
echo 'h1{color:#00d4ff;font-size:18px;margin:5px 0 15px 0}';
echo 'h2{color:#00d4ff;font-size:14px;margin:15px 0 8px 0;border-bottom:1px solid #333;padding-bottom:4px}';
echo 'table{border-collapse:collapse;width:100%;margin-bottom:10px}';
echo 'td,th{padding:5px 10px;border:1px solid #333;text-align:left}';
echo 'th{background:#16213e;color:#00d4ff;font-weight:bold}';
echo '.sok{color:#27ae60;font-weight:bold}';
echo '.swarn{color:#f39c12;font-weight:bold}';
echo '.scrit{color:#e74c3c;font-weight:bold}';
echo '.sunk{color:#95a5a6}';
echo '.barbg{background:#333;width:200px;height:16px;display:inline-block;vertical-align:middle}';
echo '.barfill{height:16px;display:inline-block}';
echo '.bartxt{font-size:11px;margin-left:5px}';
echo '.sec{background:#0f3460;border:1px solid #1a5276;padding:10px;margin-bottom:12px}';
echo '.errbox{background:#5c1a1a;border:1px solid #e74c3c;padding:10px;margin-bottom:12px}';
echo '.grid{width:100%}';
echo '.grid td{vertical-align:top;border:none;padding:0 8px 0 0;width:50%}';
echo '.foot{color:#666;font-size:10px;margin-top:15px;text-align:center}';
echo '</style></head><body>';

echo '<h1>&#128190; WD ShareSpace Monitor - ' . htmlspecialchars($host_display) . '</h1>';

if (count($data['errors']) > 0) {
    echo '<div class="errbox"><b>&#9888; Erros:</b><br>';
    for ($i = 0; $i < count($data['errors']); $i++) { echo htmlspecialchars($data['errors'][$i]) . '<br>'; }
    echo '</div>';
}

echo '<table class="grid"><tr><td>';

// Sistema
echo '<div class="sec"><h2>&#9881; Sistema</h2><table>';
echo '<tr><th>Hostname</th><td>' . htmlspecialchars($host_display) . '</td></tr>';
echo '<tr><th>Kernel</th><td>' . htmlspecialchars($data['uname']) . '</td></tr>';
echo '<tr><th>Uptime</th><td>' . $data['uptime_human'] . '</td></tr>';
echo '<tr><th>Load Avg</th><td>' . $data['load1'] . ' / ' . $data['load5'] . ' / ' . $data['load15'] . '</td></tr>';
if ($data['temp_cpu'] > 0) {
    $tc = 'sok';
    if ($data['temp_cpu'] > 70) { $tc = 'scrit'; } elseif ($data['temp_cpu'] > 55) { $tc = 'swarn'; }
    echo '<tr><th>Temp CPU</th><td class="'.$tc.'">'.$data['temp_cpu'].'&deg;C</td></tr>';
}
if ($data['temp_hdd'] > 0) {
    $tc = 'sok';
    if ($data['temp_hdd'] > 55) { $tc = 'scrit'; } elseif ($data['temp_hdd'] > 45) { $tc = 'swarn'; }
    echo '<tr><th>Temp HDD</th><td class="'.$tc.'">'.$data['temp_hdd'].'&deg;C</td></tr>';
}
echo '</table></div>';

// Memoria
$mp = $data['mem_percent'];
echo '<div class="sec"><h2>&#128268; Memoria</h2><table>';
echo '<tr><th>Total</th><td>' . format_kb($data['mem_total']) . '</td></tr>';
echo '<tr><th>Usada</th><td>' . format_kb($data['mem_used']) . '</td></tr>';
echo '<tr><th>Livre</th><td>' . format_kb($data['mem_free']) . '</td></tr>';
echo '<tr><th>Uso</th><td>';
echo '<span class="barbg"><span class="barfill" style="width:'.intval($mp*2).'px;background:'.bar_color($mp).'"></span></span>';
echo '<span class="bartxt">'.$mp.'%</span></td></tr>';
echo '</table></div>';

echo '</td><td>';

// Volumes (RAID + LVM)
echo '<div class="sec"><h2>&#128191; Volumes / RAID</h2>';
echo '<table>';
echo '<tr><th>Volume</th><th>Tipo</th><th>Status</th><th>State</th><th>Discos</th><th>Funcao</th></tr>';
if (isset($data['volumes'])) {
    for ($vi = 0; $vi < count($data['volumes']); $vi++) {
        $vol = $data['volumes'][$vi];
        $scol = status_color($vol['status']);
        $role = $vol['role'];
        if ($role == '') { $role = $vol['mount']; }
        echo '<tr>';
        echo '<td>/dev/' . htmlspecialchars($vol['name']) . '</td>';
        echo '<td>' . $vol['level'] . '</td>';
        echo '<td style="color:'.$scol.';font-weight:bold">' . strtoupper($vol['status']) . '</td>';
        echo '<td>' . $vol['state'] . '</td>';
        echo '<td>' . htmlspecialchars($vol['devices']) . '</td>';
        echo '<td>' . htmlspecialchars($role) . '</td>';
        echo '</tr>';
    }
}
echo '</table>';
// Status geral
$gs = isset($data['raid_status']) ? $data['raid_status'] : 'unknown';
echo '<p style="margin:8px 0 0 0">Status Geral: <span style="color:'.status_color($gs).';font-weight:bold">'.strtoupper($gs).'</span></p>';
echo '</div>';

// Armazenamento
$dp = $data['disk_percent'];
echo '<div class="sec"><h2>&#128190; Armazenamento</h2><table>';
echo '<tr><th>Total</th><td>'.format_kb_disk($data['disk_total']).'</td></tr>';
echo '<tr><th>Usado</th><td>'.format_kb_disk($data['disk_used']).'</td></tr>';
echo '<tr><th>Livre</th><td>'.format_kb_disk($data['disk_free']).'</td></tr>';
echo '<tr><th>Uso</th><td>';
echo '<span class="barbg"><span class="barfill" style="width:'.intval($dp*2).'px;background:'.bar_color($dp).'"></span></span>';
echo '<span class="bartxt">'.$dp.'%</span></td></tr>';
echo '</table></div>';

// SMART
echo '<div class="sec"><h2>&#128737; SMART Health</h2><table>';
echo '<tr><th>Disco</th><th>Status</th></tr>';
$sdisks = array('sda','sdb','sdc','sdd');
for ($sd = 0; $sd < 4; $sd++) {
    $dk = $sdisks[$sd];
    $h = $data['disk_health_'.$dk];
    $cls = 'sunk';
    if ($h == 'OK') { $cls = 'sok'; } elseif ($h == 'FAILED') { $cls = 'scrit'; } elseif ($h == 'EMPTY') { $cls = 'sunk'; }
    echo '<tr><td>/dev/'.$dk.'</td><td class="'.$cls.'">'.$h.'</td></tr>';
}
echo '</table></div>';

echo '</td></tr></table>';

// Particoes
if (count($data['disks']) > 0) {
    echo '<div class="sec"><h2>&#128194; Particoes</h2><table>';
    echo '<tr><th>Dispositivo</th><th>Montagem</th><th>Total</th><th>Usado</th><th>Livre</th><th>Uso</th></tr>';
    for ($p = 0; $p < count($data['disks']); $p++) {
        $pt = $data['disks'][$p];
        echo '<tr>';
        echo '<td>'.htmlspecialchars($pt['device']).'</td>';
        echo '<td>'.htmlspecialchars($pt['mount']).'</td>';
        echo '<td>'.format_kb_disk($pt['total']).'</td>';
        echo '<td>'.format_kb_disk($pt['used']).'</td>';
        echo '<td>'.format_kb_disk($pt['free']).'</td>';
        echo '<td>'.htmlspecialchars($pt['percent']).'</td>';
        echo '</tr>';
    }
    echo '</table></div>';
}

// Rede
$net_name = 'Rede';
if (isset($data['net_iface']) && $data['net_iface'] != '') { $net_name = 'Rede (' . $data['net_iface'] . ')'; }
echo '<div class="sec"><h2>&#127760; '.$net_name.'</h2><table>';
echo '<tr><th>RX (recebido)</th><td>'.format_kb_disk(intval($data['net_rx_bytes']/1024)).'</td></tr>';
echo '<tr><th>TX (enviado)</th><td>'.format_kb_disk(intval($data['net_tx_bytes']/1024)).'</td></tr>';
echo '</table></div>';

// Footer
echo '<div class="foot">';
echo '<a href="https://www.ms3ti.com.br" style="color:#666">MS3TI CONSULTORIA EM TECNOLOGIA</a> | Ultima coleta: '.date('d/m/Y H:i:s', $data['timestamp']);
echo ' | Cache: '.$cache_ttl.'s';
echo ' | <a href="?mode=zabbix&metric=raid_status" style="color:#666">Teste Zabbix</a>';
echo ' | <a href="?mode=json" style="color:#666">JSON</a>';
echo '</div>';

echo '</body></html>';
?>
