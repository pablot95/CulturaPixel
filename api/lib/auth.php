<?php
declare(strict_types=1);

// Acceso al panel: el navegador inicia sesión con Firebase Auth y manda su ID token.
// Se valida contra Firebase (accounts:lookup, como api/lib/auth.php del template) y se
// exige admins/{email} con activo: true en Firestore.

require_once __DIR__ . '/firestore.php';

function bearerToken(): string {
    if (preg_match('/^Bearer\s+(.+)$/i', requestHeader('Authorization'), $match)) return trim($match[1]);
    return requestHeader('X-Admin-Token');
}

function requireAdmin(): array {
    $idToken = bearerToken();
    if ($idToken === '' || strlen($idToken) > 4096) throw new ErrorPublico('Tu sesión venció. Volvé a ingresar.', 401, 'sesion');
    $emulador = trim((string)getenv('FIREBASE_AUTH_EMULATOR_HOST'));
    $base = $emulador !== '' ? 'http://' . $emulador . '/identitytoolkit.googleapis.com' : 'https://identitytoolkit.googleapis.com';
    $lookup = httpJson('POST', $base . '/v1/accounts:lookup?key=' . rawurlencode(FIREBASE_WEB_API_KEY), ['idToken' => $idToken]);
    $user = $lookup['data']['users'][0] ?? null;
    $email = strtolower(trim((string)($user['email'] ?? '')));
    if (!$lookup['ok'] || $email === '') throw new ErrorPublico('Tu sesión venció. Volvé a ingresar.', 401, 'sesion');
    if (!empty($user['disabled'])) throw new ErrorPublico('Tu usuario está deshabilitado.', 403);
    $admin = fsGet('admins/' . $email);
    if (!$admin || ($admin['activo'] ?? false) !== true) throw new ErrorPublico('Este usuario no tiene acceso al panel.', 403);
    return ['email' => $email, 'uid' => (string)($user['localId'] ?? '')];
}
