<?php
// DIPAG Admin — Autenticación
// dipag.cl/api/admin/auth.php

require_once '../../config/database.php';
require_once '../../config/keys.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: https://dipag.cl');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if($_SERVER['REQUEST_METHOD']==='OPTIONS'){http_response_code(200);exit;}
if($_SERVER['REQUEST_METHOD']!=='POST'){http_response_code(405);echo json_encode(['error'=>'Method not allowed']);exit;}

// Solo admins permitidos
define('ADMIN_EMAILS', ['contacto@dipag.cl']);

$body = json_decode(file_get_contents('php://input'), true);
$email    = trim($body['email'] ?? '');
$password = $body['password'] ?? '';

if(!$email || !$password){
    http_response_code(400);
    echo json_encode(['error'=>'Faltan campos']);
    exit;
}

// Verificar que es admin
if(!in_array($email, ADMIN_EMAILS)){
    http_response_code(403);
    echo json_encode(['error'=>'Acceso denegado']);
    exit;
}

// Verificar credenciales contra la DB de dipag_db
try {
    $db = getDB();
    $stmt = $db->prepare('SELECT id, nombre, email, password_hash FROM usuarios WHERE email = ? LIMIT 1');
    $stmt->execute([$email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if(!$user || !password_verify($password, $user['password_hash'])){
        http_response_code(401);
        echo json_encode(['error'=>'Email o contraseña incorrectos']);
        exit;
    }

    // Generar token de sesión admin (expira en 8 horas)
    $token = hash('sha256', $user['id'].'_admin_'.time().rand(1000,9999).ANTHROPIC_API_KEY);

    // Guardar token en DB con expiración
    $exp = date('Y-m-d H:i:s', time() + 8*3600);
    $db->prepare('INSERT INTO admin_sessions (usuario_id, token, expires_at) VALUES (?,?,?)
                  ON DUPLICATE KEY UPDATE token=VALUES(token), expires_at=VALUES(expires_at)')
       ->execute([$user['id'], $token, $exp]);

    echo json_encode([
        'success' => true,
        'token'   => $token,
        'nombre'  => $user['nombre'],
    ]);

} catch(Exception $e){
    http_response_code(500);
    echo json_encode(['error'=>'Error interno']);
}
