<?php
// ── Test direct FcmService — à supprimer après debug ─────────────────────

// ── Credentials ───────────────────────────────────────────────────────────
$credPath = __DIR__ . '/../app/Config/firebase_credentials.json';

$envJson = getenv('FIREBASE_CREDENTIALS_JSON');
if (!empty($envJson)) {
    $creds = json_decode($envJson, true);
    echo "✅ Credentials chargés depuis ENV (FIREBASE_CREDENTIALS_JSON)\n\n";
} elseif (file_exists($credPath)) {
    $creds = json_decode(file_get_contents($credPath), true);
    echo "✅ Credentials chargés depuis fichier firebase_credentials.json\n\n";
} else {
    die("❌ Aucun credential trouvé (ni fichier ni variable ENV)\n");
}

if (json_last_error() !== JSON_ERROR_NONE) {
    die("❌ Credentials JSON invalide : " . json_last_error_msg() . "\n");
}

$projectId = $creds['project_id'] ?? '';
echo "project_id : {$projectId}\n";
echo "client_email : " . ($creds['client_email'] ?? '???') . "\n\n";

// ── Étape 1 : Access token OAuth2 ────────────────────────────────────────
echo "⏳ Génération access token...\n";

$now     = time();
$header  = base64url(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
$payload = base64url(json_encode([
    'iss'   => $creds['client_email'],
    'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
    'aud'   => 'https://oauth2.googleapis.com/token',
    'iat'   => $now,
    'exp'   => $now + 3600,
]));

$signingInput = $header . '.' . $payload;

if (!openssl_sign($signingInput, $signature, $creds['private_key'], OPENSSL_ALGO_SHA256)) {
    die("❌ Échec signature OpenSSL\n");
}

$jwt = $signingInput . '.' . base64url($signature);

$ch = curl_init('https://oauth2.googleapis.com/token');
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => http_build_query([
        'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
        'assertion'  => $jwt,
    ]),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 15,
]);
$resp     = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlErr  = curl_error($ch);
curl_close($ch);

echo "HTTP OAuth : {$httpCode}\n";
if ($curlErr) die("❌ cURL error : {$curlErr}\n");

$result = json_decode($resp, true);
if (empty($result['access_token'])) {
    echo "❌ Pas d'access_token\n";
    echo "Réponse complète : {$resp}\n";
    die();
}

$accessToken = $result['access_token'];
echo "✅ Access token obtenu\n\n";

// ── Étape 2 : Token FCM depuis la DB ─────────────────────────────────────
echo "⏳ Lecture token FCM depuis DB...\n";

// Adapte ces 4 valeurs selon ton hébergement Render
$host   = getenv('DB_HOST')     ?: 'localhost';
$dbname = getenv('DB_DATABASE') ?: 'perfectocrm';
$user   = getenv('DB_USERNAME') ?: 'root';
$pass   = getenv('DB_PASSWORD') ?: '';

echo "DB host : {$host} / base : {$dbname}\n";

try {
    $pdo  = new PDO("mysql:host={$host};dbname={$dbname};charset=utf8", $user, $pass);
    $stmt = $pdo->query("SELECT name, value FROM tbloptions WHERE name LIKE 'fcm_token_%'");
    $tokens = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($tokens)) {
        die("❌ Aucun token FCM dans tbloptions — l'app Flutter n'a pas encore enregistré de token\n");
    }

    echo "Tokens FCM trouvés :\n";
    foreach ($tokens as $t) {
        echo "  - {$t['name']} = " . substr($t['value'], 0, 40) . "...\n";
    }

    // Prend le premier token staff
    $fcmToken    = null;
    $tokenName   = null;
    foreach ($tokens as $t) {
        if (str_starts_with($t['name'], 'fcm_token_staff_')) {
            $fcmToken  = $t['value'];
            $tokenName = $t['name'];
            break;
        }
    }
    // Fallback : premier token disponible
    if (!$fcmToken) {
        $fcmToken  = $tokens[0]['value'];
        $tokenName = $tokens[0]['name'];
    }

    echo "\n✅ Token utilisé : {$tokenName}\n\n";

} catch (Exception $e) {
    die("❌ DB Error : " . $e->getMessage() . "\n");
}

// ── Étape 3 : Envoi push FCM ─────────────────────────────────────────────
echo "⏳ Envoi push FCM...\n";

$url = "https://fcm.googleapis.com/v1/projects/{$projectId}/messages:send";

$fcmPayload = json_encode([
    'message' => [
        'token'        => $fcmToken,
        'notification' => [
            'title' => '🔔 Test FCM hébergement',
            'body'  => 'Test depuis Render — ' . date('H:i:s'),
        ],
        'android' => ['priority' => 'high'],
    ],
]);

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_HTTPHEADER     => [
        'Authorization: Bearer ' . $accessToken,
        'Content-Type: application/json',
    ],
    CURLOPT_POSTFIELDS     => $fcmPayload,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 15,
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlErr  = curl_error($ch);
curl_close($ch);

echo "HTTP FCM : {$httpCode}\n";
if ($curlErr) echo "❌ cURL error : {$curlErr}\n";

$fcmResult = json_decode($response, true);
echo "Réponse FCM : {$response}\n\n";

if ($httpCode === 200 && isset($fcmResult['name'])) {
    echo "✅✅✅ PUSH ENVOYÉ AVEC SUCCÈS\n";
    echo "Message ID : " . $fcmResult['name'] . "\n";
} else {
    echo "❌ ÉCHEC envoi FCM\n";
    echo "Code    : " . ($fcmResult['error']['code']    ?? '?') . "\n";
    echo "Message : " . ($fcmResult['error']['message'] ?? '?') . "\n";
    echo "Status  : " . ($fcmResult['error']['status']  ?? '?') . "\n";
}

// ── Helper ────────────────────────────────────────────────────────────────
function base64url(string $data): string {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}