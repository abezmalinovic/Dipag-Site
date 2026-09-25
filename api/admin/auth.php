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

    // 25 sep 2026: límite de intentos (antes el login del panel no tenía ninguno).
    // Máx. 5 fallidos por IP o por email en 15 minutos. IP real vía Cloudflare.
    $db->exec("CREATE TABLE IF NOT EXISTS admin_login_attempts (
        id INT AUTO_INCREMENT PRIMARY KEY,
        ip VARCHAR(45) NOT NULL,
        email VARCHAR(255),
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_ip (ip, created_at), INDEX idx_email (email, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $db->exec("DELETE FROM admin_login_attempts WHERE created_at < DATE_SUB(NOW(), INTERVAL 15 MINUTE)");
    $ip = substr(trim($_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? 'unknown'), 0, 45);
    $cnt = $db->prepare('SELECT COUNT(*) FROM admin_login_attempts WHERE ip = ? OR email = ?');
    $cnt->execute([$ip, $email]);
    if((int)$cnt->fetchColumn() >= 5){
        http_response_code(429);
        echo json_encode(['error'=>'Demasiados intentos. Espera 15 minutos.']);
        exit;
    }

    // Buscar en tabla admins (independiente de usuarios)
    $stmt = $db->prepare('SELECT id, nombre, email, password FROM admins WHERE email = ? AND activo = 1 LIMIT 1');
    $stmt->execute([$email]);
    $admin = $stmt->fetch(PDO::FETCH_ASSOC);

    if(!$admin || !password_verify($password, $admin['password'])){
        $db->prepare('INSERT INTO admin_login_attempts (ip, email) VALUES (?,?)')->execute([$ip, $email]);
        http_response_code(401);
        echo json_encode(['error'=>'Email o contraseña incorrectos']);
        exit;
    }

    // Generar token de sesión (expira en 8 horas)
    // 25 sep 2026: token 100% aleatorio (antes: hora + rand + clave de la API como sal)
    $token = bin2hex(random_bytes(32));
    $db->prepare('DELETE FROM admin_login_attempts WHERE ip = ? OR email = ?')->execute([$ip, $email]);
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
