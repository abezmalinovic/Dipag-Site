<?php
// DIPAG Admin — Listado de usuarios
require_once '../../config/database.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: https://dipag.cl');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if($_SERVER['REQUEST_METHOD']==='OPTIONS'){http_response_code(200);exit;}

// 25 sep 2026: cuentas internas por id (mismas que stats.php y el cron de vencimientos)
const CUENTAS_INTERNAS = [2, 3, 5, 9];

function verificarAdmin($db){
    $auth  = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    $token = str_replace('Bearer ', '', $auth);
    if(!$token){ return false; }
    $stmt = $db->prepare('SELECT admin_id FROM admin_sessions WHERE token=? AND expires_at > NOW() LIMIT 1');
    $stmt->execute([$token]);
    return $stmt->fetch() !== false;
}

try {
    $db = getDB();

    if(!verificarAdmin($db)){
        http_response_code(401);
        echo json_encode(['error'=>'No autorizado']);
        exit;
    }

    $limit  = min(100, (int)($_GET['limit'] ?? 50));
    $offset = (int)($_GET['offset'] ?? 0);
    $plan   = $_GET['plan'] ?? '';

    // Excluir cuentas internas siempre
    $in = implode(',', array_map('intval', CUENTAS_INTERNAS));
    $whereBase = "WHERE u.id NOT IN ($in)";
    $params = [];

    if($plan){
        $whereBase .= " AND u.plan=?";
        $params[] = $plan === 'premium' ? 'premium' : 'free';
    }

    $stmt = $db->prepare("
        SELECT
            u.id, u.nombre, u.email, u.plan, u.created_at,
            COUNT(DISTINCT b.id) as total_boletas,
            MAX(b.created_at)    as ultima_boleta,
            s.estado             as suscripcion_estado,
            s.vencimiento        as suscripcion_vence
        FROM usuarios u
        LEFT JOIN boletas b ON b.usuario_id = u.id
        LEFT JOIN suscripciones s ON s.usuario_id = u.id AND s.estado='activa'
        $whereBase
        GROUP BY u.id
        ORDER BY u.created_at DESC
        LIMIT ? OFFSET ?
    ");
    $stmt->execute(array_merge($params, [$limit, $offset]));
    $usuarios = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $totalStmt = $db->prepare("SELECT COUNT(*) FROM usuarios u $whereBase");
    $totalStmt->execute($params);
    $total = $totalStmt->fetchColumn();

    echo json_encode([
        'success'  => true,
        'usuarios' => $usuarios,
        'total'    => (int)$total,
        'limit'    => $limit,
        'offset'   => $offset,
    ]);

} catch(Exception $e){
    http_response_code(500);
    echo json_encode(['error'=>'Error interno']);
}
