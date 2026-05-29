<?php
// DIPAG Admin — Estadísticas
require_once '../../config/database.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: https://dipag.cl');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if($_SERVER['REQUEST_METHOD']==='OPTIONS'){http_response_code(200);exit;}

// Emails excluidos del conteo
define('DEMO_EMAILS', ['demo@dipag.app', 'demo_premium@dipag.app']);
// Email del owner — no cuenta como ingreso premium
define('OWNER_EMAIL', 'abezmalinovic@gmail.com');

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
    $excluidos = array_merge(DEMO_EMAILS, [OWNER_EMAIL]);
    $placeholders = implode(',', array_fill(0, count($excluidos), '?'));
    $excl2 = DEMO_EMAILS;
    $pl2 = implode(',', array_fill(0, count($excl2), '?'));

    // Usuarios reales (sin demos)
    $stmtU = $db->prepare("SELECT COUNT(*) FROM usuarios WHERE email NOT IN ($pl2)");
    $stmtU->execute($excl2);
    $total_usuarios = $stmtU->fetchColumn();

    // Nuevos este mes (sin demos)
    $stmtN = $db->prepare("SELECT COUNT(*) FROM usuarios WHERE DATE_FORMAT(created_at,'%Y-%m')=? AND email NOT IN ($pl2)");
    $stmtN->execute(array_merge([$mes], $excl2));
    $nuevos_mes = $stmtN->fetchColumn();

    // Premium activos que generan ingresos (sin demos ni owner)
    $stmtP = $db->prepare("SELECT COUNT(*) FROM usuarios u JOIN suscripciones s ON s.usuario_id=u.id WHERE s.estado='activa' AND s.vencimiento >= CURDATE() AND u.email NOT IN ($placeholders)");
    $stmtP->execute($excluidos);
    $premium_activos = $stmtP->fetchColumn();

    $total_boletas     = $db->query('SELECT COUNT(*) FROM boletas')->fetchColumn();
    $boletas_mes       = $db->query("SELECT COUNT(*) FROM boletas WHERE DATE_FORMAT(created_at,'%Y-%m')='$mes'")->fetchColumn();
    $boletas_ocr_mes   = $db->query("SELECT COUNT(*) FROM boletas WHERE origen='ocr' AND DATE_FORMAT(created_at,'%Y-%m')='$mes'")->fetchColumn();
    $boletas_manual_mes= $db->query("SELECT COUNT(*) FROM boletas WHERE origen='manual' AND DATE_FORMAT(created_at,'%Y-%m')='$mes'")->fetchColumn();
    $total_ocr         = $db->query("SELECT COUNT(*) FROM boletas WHERE origen='ocr'")->fetchColumn();
    $completadas_mes   = $db->query("SELECT COUNT(*) FROM boletas WHERE estado='completada' AND DATE_FORMAT(created_at,'%Y-%m')='$mes'")->fetchColumn();

    $dias = $db->query("SELECT DATE_FORMAT(created_at,'%d/%m') as dia, COUNT(*) as total
        FROM boletas WHERE created_at >= DATE_SUB(NOW(), INTERVAL 14 DAY)
        GROUP BY DATE(created_at) ORDER BY DATE(created_at) ASC LIMIT 14")->fetchAll(PDO::FETCH_ASSOC);

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
    ]);
} catch(Exception $e){
    http_response_code(500);
    echo json_encode(['error'=>'Error: '.$e->getMessage()]);
}
