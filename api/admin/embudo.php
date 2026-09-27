<?php
// DIPAG Admin — Embudo de uso (v1.9.69). Lee la tabla `eventos` que llena dipag.app/api/evento.php.
// GET ?dias=30 (1..365). Mismas reglas que stats.php: sesión de admin y cuentas internas excluidas.
require_once '../../config/database.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: https://dipag.cl');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if($_SERVER['REQUEST_METHOD']==='OPTIONS'){http_response_code(200);exit;}

// Igual que stats.php: 2 = Antoine, 3 = demo, 5 = demo premium, 9 = antoine.81@hotmail.com
const CUENTAS_INTERNAS = [2, 3, 5, 9];

function verificarAdmin($db){
    $token = str_replace('Bearer ', '', $_SERVER['HTTP_AUTHORIZATION'] ?? '');
    if(!$token) return false;
    $stmt = $db->prepare('SELECT admin_id FROM admin_sessions WHERE token=? AND expires_at > NOW() LIMIT 1');
    $stmt->execute([$token]);
    return $stmt->fetch() !== false;
}

try {
    $db = getDB();
    if(!verificarAdmin($db)){ http_response_code(401); echo json_encode(['error'=>'No autorizado']); exit; }

    $dias = max(1, min(365, intval($_GET['dias'] ?? 30)));
    $in   = implode(',', array_map('intval', CUENTAS_INTERNAS)); // enteros fijos del código
    $base = "usuario_id NOT IN ($in) AND created_at > (UTC_TIMESTAMP() - INTERVAL $dias DAY)";

    // ¿Existe la tabla? (si aún no se creó, responder vacío en vez de error)
    if(!$db->query("SHOW TABLES LIKE 'eventos'")->fetch()){
        echo json_encode(['success'=>true,'sin_tabla'=>true]); exit;
    }

    // Cuentas distintas por evento
    $porEvento = function(array $lista) use ($db, $base){
        $ph = implode(',', array_fill(0, count($lista), '?'));
        $st = $db->prepare("SELECT evento, COUNT(DISTINCT cuenta) c FROM eventos WHERE $base AND cuenta IS NOT NULL AND evento IN ($ph) GROUP BY evento");
        $st->execute($lista);
        $out = array_fill_keys($lista, 0);
        foreach($st->fetchAll(PDO::FETCH_ASSOC) as $r) $out[$r['evento']] = (int)$r['c'];
        return $out;
    };

    // 1) Embudo por pasos
    $pasosEv = ['ver_items','ver_comensales','ver_asignar','ver_resumen','cuenta_completada'];
    $embudo  = $porEvento($pasosEv);
    $iniciadas = (int)$db->query("SELECT COUNT(DISTINCT cuenta) FROM eventos WHERE $base AND cuenta IS NOT NULL AND evento IN ('cuenta_manual','escaneo_inicio')")->fetchColumn();
    $porOrigen = $porEvento(['cuenta_manual','escaneo_inicio']);

    // 2) Escaneos (por evento y motivo de error)
    $esc = ['inicio'=>0,'ok'=>0,'sin_items'=>0,'error'=>0,'errores'=>['red'=>0,'limite_diario'=>0,'ocr'=>0,'otro'=>0]];
    $st = $db->query("SELECT evento, meta, COUNT(*) n FROM eventos WHERE $base AND evento IN ('escaneo_inicio','escaneo_ok','escaneo_sin_items','escaneo_error') GROUP BY evento, meta");
    foreach($st->fetchAll(PDO::FETCH_ASSOC) as $r){
        $n = (int)$r['n'];
        if($r['evento']==='escaneo_inicio')    $esc['inicio']    += $n;
        if($r['evento']==='escaneo_ok')        $esc['ok']        += $n;
        if($r['evento']==='escaneo_sin_items') $esc['sin_items'] += $n;
        if($r['evento']==='escaneo_error'){
            $esc['error'] += $n;
            $m = json_decode((string)$r['meta'], true);
            $mot = is_array($m) ? ($m['motivo'] ?? '') : '';
            if(!isset($esc['errores'][$mot])) $mot = 'otro';
            $esc['errores'][$mot] += $n;
        }
    }

    // 3) Conversión a Premium (usuarios distintos)
    $conv = ['upgrade_popup'=>0,'ver_premium'=>0,'premium_pago_inicio'=>0,'premium_redirige_mp'=>0];
    $st = $db->query("SELECT evento, COUNT(DISTINCT usuario_id) u FROM eventos WHERE $base AND evento IN ('upgrade_popup','ver_premium','premium_pago_inicio','premium_redirige_mp') GROUP BY evento");
    foreach($st->fetchAll(PDO::FETCH_ASSOC) as $r) $conv[$r['evento']] = (int)$r['u'];
    $popups = ['escaneo'=>0,'pdf'=>0,'otro'=>0];
    $st = $db->query("SELECT meta, COUNT(*) n FROM eventos WHERE $base AND evento='upgrade_popup' GROUP BY meta");
    foreach($st->fetchAll(PDO::FETCH_ASSOC) as $r){
        $m = json_decode((string)$r['meta'], true);
        $o = is_array($m) ? ($m['origen'] ?? '') : '';
        if(!isset($popups[$o])) $o = 'otro';
        $popups[$o] += (int)$r['n'];
    }

    // 4) Cómo comparten / cierran
    $salidas = $porEvento(['terminar_cuenta','compartir_pdf','compartir_wa','compartir_resumen_wa','compartir_link_comensal','recomendar_app']);

    // 5) Usuarios activos y cuentas iniciadas por día (hora Chile aprox. UTC-3), últimos 14 días
    $st = $db->query("SELECT DATE_FORMAT(created_at - INTERVAL 3 HOUR,'%d/%m') dia, DATE(created_at - INTERVAL 3 HOUR) d,
                             COUNT(DISTINCT usuario_id) usuarios,
                             COUNT(DISTINCT CASE WHEN evento IN ('cuenta_manual','escaneo_inicio') THEN cuenta END) cuentas
                      FROM eventos WHERE usuario_id NOT IN ($in) AND created_at > (UTC_TIMESTAMP() - INTERVAL 14 DAY)
                      GROUP BY d, dia ORDER BY d ASC");
    $porDia = array_map(function($r){ return ['dia'=>$r['dia'],'usuarios'=>(int)$r['usuarios'],'cuentas'=>(int)$r['cuentas']]; }, $st->fetchAll(PDO::FETCH_ASSOC));

    // 6) Usuarios activos y retención simple
    $activos = (int)$db->query("SELECT COUNT(DISTINCT usuario_id) FROM eventos WHERE $base")->fetchColumn();
    $vuelven = (int)$db->query("SELECT COUNT(*) FROM (SELECT usuario_id FROM eventos WHERE $base AND evento='app_abierta'
                                GROUP BY usuario_id HAVING COUNT(DISTINCT DATE(created_at)) >= 2) t")->fetchColumn();

    // 7) Dónde abandonan: última pantalla del flujo de las cuentas que no se completaron
    $abandono = ['ver_items'=>0,'ver_comensales'=>0,'ver_asignar'=>0,'ver_resumen'=>0];
    $st = $db->query("SELECT ult, COUNT(*) n FROM (
                        SELECT SUBSTRING_INDEX(GROUP_CONCAT(e.evento ORDER BY e.id SEPARATOR ','), ',', -1) ult
                        FROM eventos e
                        WHERE e.usuario_id NOT IN ($in) AND e.cuenta IS NOT NULL
                          AND e.created_at > (UTC_TIMESTAMP() - INTERVAL $dias DAY)
                          AND e.evento IN ('ver_items','ver_comensales','ver_asignar','ver_resumen')
                          AND e.cuenta NOT IN (SELECT cuenta FROM eventos WHERE evento='cuenta_completada' AND cuenta IS NOT NULL)
                        GROUP BY e.cuenta) t GROUP BY ult");
    foreach($st->fetchAll(PDO::FETCH_ASSOC) as $r) if(isset($abandono[$r['ult']])) $abandono[$r['ult']] = (int)$r['n'];

    // 8) Datos generales
    $row = $db->query("SELECT COUNT(*) n, MIN(created_at) desde FROM eventos WHERE usuario_id NOT IN ($in)")->fetch(PDO::FETCH_ASSOC);

    echo json_encode([
        'success'   => true,
        'dias'      => $dias,
        'iniciadas' => $iniciadas,
        'por_origen'=> ['manual'=>$porOrigen['cuenta_manual'], 'escaneo'=>$porOrigen['escaneo_inicio']],
        'embudo'    => $embudo,
        'escaneos'  => $esc,
        'conversion'=> $conv,
        'popups'    => $popups,
        'salidas'   => $salidas,
        'por_dia'   => $porDia,
        'activos'   => $activos,
        'vuelven'   => $vuelven,
        'abandono'  => $abandono,
        'total_eventos' => (int)$row['n'],
        'desde_utc' => $row['desde'],
    ]);
} catch(Exception $e){
    http_response_code(500);
    echo json_encode(['error'=>'Error interno']);
}
