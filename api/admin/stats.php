<?php
// DIPAG Admin — Estadísticas
require_once '../../config/database.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: https://dipag.cl');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if($_SERVER['REQUEST_METHOD']==='OPTIONS'){http_response_code(200);exit;}

// 25 sep 2026: cuentas internas por id, igual que el cron de vencimientos (Dipag-App).
// 2 = Antoine, 3 = demo@dipag.app, 5 = demo_premium@dipag.app, 9 = antoine.81@hotmail.com
// Antes: usuarios excluía solo las demo, Premium además al owner, y boletas no excluía a nadie.
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

    $mes = date('Y-m');
    $in  = implode(',', array_map('intval', CUENTAS_INTERNAS)); // enteros fijos del código, sin datos del usuario
    $q = function($sql) use ($db){ return (int)$db->query($sql)->fetchColumn(); };

    // Usuarios reales (sin cuentas internas)
    $total_usuarios = $q("SELECT COUNT(*) FROM usuarios WHERE id NOT IN ($in)");
    $nuevos_mes     = $q("SELECT COUNT(*) FROM usuarios WHERE id NOT IN ($in) AND DATE_FORMAT(created_at,'%Y-%m')='$mes'");
    // Premium que pagan: suscripción activa y vigente
    $premium_activos = $q("SELECT COUNT(DISTINCT u.id) FROM usuarios u JOIN suscripciones s ON s.usuario_id=u.id
                           WHERE u.id NOT IN ($in) AND s.estado='activa' AND s.vencimiento >= CURDATE()");

    // Boletas de clientes (sin cuentas internas)
    $w = "usuario_id NOT IN ($in)";
    $total_boletas      = $q("SELECT COUNT(*) FROM boletas WHERE $w");
    $boletas_mes        = $q("SELECT COUNT(*) FROM boletas WHERE $w AND DATE_FORMAT(created_at,'%Y-%m')='$mes'");
    $boletas_ocr_mes    = $q("SELECT COUNT(*) FROM boletas WHERE $w AND origen='scan' AND DATE_FORMAT(created_at,'%Y-%m')='$mes'");
    $boletas_manual_mes = $q("SELECT COUNT(*) FROM boletas WHERE $w AND origen='manual' AND DATE_FORMAT(created_at,'%Y-%m')='$mes'");
    $total_ocr          = $q("SELECT COUNT(*) FROM boletas WHERE $w AND origen='scan'");
    $completadas_mes    = $q("SELECT COUNT(*) FROM boletas WHERE $w AND estado='completada' AND DATE_FORMAT(created_at,'%Y-%m')='$mes'");

    $dias = $db->query("SELECT DATE_FORMAT(created_at,'%d/%m') as dia, COUNT(*) as total
        FROM boletas WHERE $w AND created_at >= DATE_SUB(NOW(), INTERVAL 14 DAY)
        GROUP BY DATE(created_at) ORDER BY DATE(created_at) ASC LIMIT 14")->fetchAll(PDO::FETCH_ASSOC);

    // Uso interno (aparte) y OCR total del mes: el costo de la API es real para todas las cuentas
    $int_boletas_mes = $q("SELECT COUNT(*) FROM boletas WHERE usuario_id IN ($in) AND DATE_FORMAT(created_at,'%Y-%m')='$mes'");
    $int_ocr_mes     = $q("SELECT COUNT(*) FROM boletas WHERE usuario_id IN ($in) AND origen='scan' AND DATE_FORMAT(created_at,'%Y-%m')='$mes'");

    echo json_encode([
        'success'            => true,
        'total_usuarios'     => (int)$total_usuarios,
        'premium_activos'    => (int)$premium_activos,
        'nuevos_mes'         => (int)$nuevos_mes,
        'total_boletas'      => (int)$total_boletas,
        'boletas_mes'        => (int)$boletas_mes,
        'boletas_ocr_mes'    => (int)$boletas_ocr_mes,
        'boletas_manual_mes' => (int)$boletas_manual_mes,
        'total_ocr'          => (int)$total_ocr,
        'completadas_mes'    => (int)$completadas_mes,
        'boletas_por_dia'    => $dias,
        'mrr'                => (int)$premium_activos * 1990,
        'ocr_mes_total'      => $boletas_ocr_mes + $int_ocr_mes,
        'uso_interno'        => ['boletas_mes' => $int_boletas_mes, 'ocr_mes' => $int_ocr_mes, 'cuentas' => CUENTAS_INTERNAS],
    ]);
} catch(Exception $e){
    http_response_code(500);
    echo json_encode(['error'=>'Error interno']);
}
