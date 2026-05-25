<?php
// DIPAG Admin — Autenticación
// dipag.cl/api/admin/auth.php
// Usa tabla 'admins' separada — no toca la tabla 'usuarios'

require_once '../../config/database.php';
require_once '../../config/keys.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: https://dipag.cl');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if($_SERVER['REQUEST_METHOD']==='OPTIONS'){http_response_code(200);exit;}
if($_SERVER['REQUEST_METHOD']!=='POST'){http_response_code(405);echo json_encode(['error'=>'Method not allowed']);exit;}

$body     = json_decode(file_get_contents('php://input'), true);
$email    = trim($body['email'] ?? '');
$password = $body['password'] ?? '';

if(!$email || !$password){
    http_response_code(400);
    echo json_encode(['error'=>'Faltan campos']);
    exit;
}

try {
    $db = getDB();

    // Buscar en tabla admins (independiente de usuarios)
    $stmt = $db->prepare('SELECT id, nombre, email, password FROM admins WHERE email = ? AND activo = 1 LIMIT 1');
    $stmt->execute([$email]);
    $admin = $stmt->fetch(PDO::FETCH_ASSOC);

    if(!$admin || !password_verify($password, $admin['password'])){
        http_response_code(401);
        echo json_encode(['error'=>'Email o contraseña incorrectos']);
        exit;
    }

    // Generar token de sesión (expira en 8 horas)
    $token = hash('sha256', $admin['id'].'_admin_'.time().rand(1000,9999).ANTHROPIC_API_KEY);
    $exp   = date('Y-m-d H:i:s', time() + 8*3600);

    $db->prepare('INSERT INTO admin_sessions (admin_id, token, expires_at) VALUES (?,?,?)
                  ON DUPLICATE KEY UPDATE token=VALUES(token), expires_at=VALUES(expires_at)')
       ->execute([$admin['id'], $token, $exp]);

    echo json_encode([
        'success' => true,
        'token'   => $token,
        'nombre'  => $admin['nombre'],
    ]);

} catch(Exception $e){
    http_response_code(500);
    echo json_encode(['error'=>'Error interno']);
}
