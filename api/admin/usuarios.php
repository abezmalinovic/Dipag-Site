<?php
// DIPAG Admin — Listado de usuarios
// dipag.cl/api/admin/usuarios.php

require_once '../../config/database.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: https://dipag.cl');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if($_SERVER['REQUEST_METHOD']==='OPTIONS'){http_response_code(200);exit;}

function verificarAdmin($db){
    $auth  = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    $token = str_replace('Bearer ', '', $auth);
    if(!$token){ return false; }
    $stmt = $db->prepare('SELECT usuario_id FROM admin_sessions WHERE token=? AND expires_at > NOW() LIMIT 1');
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

    $where = $plan ? "WHERE u.plan='".($plan==='premium'?'premium':'free')."'" : '';

    $stmt = $db->prepare("
        SELECT
            u.id, u.nombre, u.email, u.plan, u.created_at,
            COUNT(DISTINCT b.id) as total_boletas,
            COUNT(DISTINCT g.id) as total_grupos,
            MAX(b.created_at)    as ultima_boleta,
            s.estado             as suscripcion_estado,
            s.vencimiento        as suscripcion_vence
        FROM usuarios u
        LEFT JOIN boletas b ON b.usuario_id = u.id
        LEFT JOIN grupos g ON g.usuario_id = u.id
        LEFT JOIN suscripciones s ON s.usuario_id = u.id AND s.estado='activa'
        $where
        GROUP BY u.id
        ORDER BY u.created_at DESC
        LIMIT ? OFFSET ?
    ");
    $stmt->execute([$limit, $offset]);
    $usuarios = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $total = $db->query("SELECT COUNT(*) FROM usuarios $where")->fetchColumn();

    echo json_encode([
        'success'  => true,
        'usuarios' => $usuarios,
        'total'    => (int)$total,
        'limit'    => $limit,
        'offset'   => $offset,
    ]);

} catch(Exception $e){
    http_response_code(500);
    echo json_encode(['error'=>'Error interno: '.$e->getMessage()]);
}
