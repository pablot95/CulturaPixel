<?php
declare(strict_types=1);

// Token de acceso de la cuenta de servicio (JWT firmado con su clave privada).
// Base: api/lib/firebase-admin.php del Template-Ecommerce, más soporte del emulador.

require_once __DIR__ . '/common.php';

function base64Url(string $value): string {
    return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
}

function firestoreEmulador(): string {
    return trim((string)getenv('FIRESTORE_EMULATOR_HOST'));
}

function firebaseAdminToken(): string {
    if (firestoreEmulador() !== '') return 'owner';
    static $memoryToken = '';
    static $memoryExpiry = 0;
    if ($memoryToken !== '' && $memoryExpiry > time() + 60) return $memoryToken;

    $cache = carpetaTemporal('firebase') . '/token-' . hash('sha256', FIREBASE_PROJECT_ID . '|' . SERVICE_ACCOUNT_PATH) . '.json';
    if (is_file($cache)) {
        $saved = json_decode((string)@file_get_contents($cache), true);
        if (is_array($saved) && !empty($saved['token']) && (int)($saved['expires'] ?? 0) > time() + 60) {
            $memoryToken = (string)$saved['token'];
            $memoryExpiry = (int)$saved['expires'];
            return $memoryToken;
        }
    }

    if (!is_file(SERVICE_ACCOUNT_PATH)) throw new RuntimeException('Falta api/service-account.json en el servidor.');
    $service = json_decode((string)file_get_contents(SERVICE_ACCOUNT_PATH), true);
    if (!is_array($service) || empty($service['client_email']) || empty($service['private_key'])) throw new RuntimeException('service-account.json inválido.');

    $now = time();
    $header = base64Url((string)json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
    $claims = base64Url((string)json_encode([
        'iss' => $service['client_email'],
        'scope' => 'https://www.googleapis.com/auth/datastore',
        'aud' => 'https://oauth2.googleapis.com/token',
        'iat' => $now,
        'exp' => $now + 3600,
    ]));
    $unsigned = $header . '.' . $claims;
    $signature = '';
    if (!openssl_sign($unsigned, $signature, $service['private_key'], OPENSSL_ALGO_SHA256)) throw new RuntimeException('No se pudo firmar el token de Firebase.');

    $ch = curl_init('https://oauth2.googleapis.com/token');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query(['grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer', 'assertion' => $unsigned . '.' . base64Url($signature)]),
        CURLOPT_TIMEOUT => 15,
        CURLOPT_SSL_OPTIONS => defined('CURLSSLOPT_NATIVE_CA') ? CURLSSLOPT_NATIVE_CA : 0,
    ]);
    $raw = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $response = json_decode((string)$raw, true);
    if ($status < 200 || $status >= 300 || empty($response['access_token'])) throw new RuntimeException('No se pudo autenticar la cuenta de servicio (HTTP ' . $status . ').');

    $memoryToken = (string)$response['access_token'];
    $memoryExpiry = $now + (int)($response['expires_in'] ?? 3600);
    @file_put_contents($cache, json_encode(['token' => $memoryToken, 'expires' => $memoryExpiry]), LOCK_EX);
    @chmod($cache, 0600);
    return $memoryToken;
}
