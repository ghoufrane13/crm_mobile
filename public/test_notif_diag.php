<?php
// ── Diagnostic des notifications CRM ─────────────────────────────────────────
// URL : http://localhost/CodeIgniter4-4.4.7/public/test_notif_diag.php
// SUPPRIMER APRES TESTS !

$dsn = 'mysql:host=localhost;dbname=crm_mobile;charset=utf8mb4';
try {
    $pdo = new PDO($dsn, 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
} catch (Exception $e) {
    die("DB Error: " . $e->getMessage());
}

$action = $_GET['action'] ?? 'diag';
$base   = 'http://localhost/CodeIgniter4-4.4.7/public/api';

// ── Action: paiement test ────────────────────────────────────────────────────
if ($action === 'pay') {
    $body = json_encode([
        'invoice_id'      => (int)($_GET['inv'] ?? 0),
        'amount'          => (float)($_GET['amt'] ?? 10),
        'payment_gateway' => (int)($_GET['mode'] ?? 1),
    ]);
    $ch = curl_init("$base/payments/create");
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
    ]);
    $res  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    header('Content-Type: application/json');
    echo json_encode(['http' => $code, 'curl_error' => $err, 'response' => json_decode($res, true)], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

// ── Action: accept/decline devis ─────────────────────────────────────────────
if ($action === 'estimate') {
    $body = json_encode([
        'contact_id' => (int)($_GET['cid'] ?? 0),
        'action'     => $_GET['act'] ?? 'accept',
    ]);
    $eid = (int)($_GET['eid'] ?? 0);
    $ch = curl_init("$base/estimates/client-respond/$eid");
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
    ]);
    $res  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    header('Content-Type: application/json');
    echo json_encode(['http' => $code, 'response' => json_decode($res, true)], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

// ── Action: accept/decline offre ─────────────────────────────────────────────
if ($action === 'proposal') {
    $body = json_encode([
        'contact_id' => (int)($_GET['cid'] ?? 0),
        'action'     => $_GET['act'] ?? 'accept',
    ]);
    $pid = (int)($_GET['pid'] ?? 0);
    $ch = curl_init("$base/proposals/client-respond/$pid");
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
    ]);
    $res  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    header('Content-Type: application/json');
    echo json_encode(['http' => $code, 'response' => json_decode($res, true)], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

// ── PAGE DIAGNOSTIC ──────────────────────────────────────────────────────────
$invoices  = $pdo->query("SELECT id,formatted_number,total,status,sale_agent,clientid FROM tblinvoices WHERE status IN(1,4) ORDER BY id DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
$modes     = $pdo->query("SELECT id,name FROM tblpayment_modes WHERE active=1 LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
$tokens    = $pdo->query("SELECT name,SUBSTRING(value,1,35) as tok FROM tbloptions WHERE name LIKE 'fcm_token_%'")->fetchAll(PDO::FETCH_ASSOC);
$estimates = $pdo->query("SELECT id,formatted_number,clientid,sale_agent FROM tblestimates WHERE status=2 ORDER BY id DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
$proposals = $pdo->query("SELECT id,subject,rel_id,assigned,addedfrom FROM tblproposals WHERE status IN(1,2) ORDER BY id DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
$contacts  = $pdo->query("SELECT c.id,c.firstname,c.lastname,c.userid,cl.company FROM tblcontacts c LEFT JOIN tblclients cl ON cl.userid=c.userid ORDER BY c.id DESC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);
$notifs    = $pdo->query("SELECT id,touserid,description,date,isread FROM tblnotifications ORDER BY date DESC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);

// Logs CI4
$logFile = __DIR__ . '/../writable/logs/log-' . date('Y-m-d') . '.log';
$logLines = '';
if (file_exists($logFile)) {
    $all = file($logFile);
    $relevant = array_filter($all, fn($l) => stripos($l, 'FcmService') !== false || stripos($l, 'Notification') !== false || stripos($l, 'fcm') !== false);
    $logLines = implode('', array_slice($relevant, -15));
}

$modeId = $modes[0]['id'] ?? 1;

// Firebase credentials check
$credPath = __DIR__ . '/../app/Config/firebase_credentials.json';
$credOk   = file_exists($credPath);
$credInfo = '';
if ($credOk) {
    $cred = json_decode(file_get_contents($credPath), true);
    $credInfo = 'project_id=' . ($cred['project_id'] ?? 'MISSING');
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8"><title>Test Notifications CRM</title>
<style>
body{font-family:'Segoe UI',sans-serif;background:#0f172a;color:#e2e8f0;padding:20px;margin:0}
h1{color:#38bdf8;border-bottom:1px solid #1e40af;padding-bottom:10px}
h2{color:#7dd3fc;margin-top:30px}
table{width:100%;border-collapse:collapse;margin-bottom:20px;font-size:13px}
th{background:#1e3a5f;color:#93c5fd;padding:8px 10px;text-align:left}
td{padding:7px 10px;border-bottom:1px solid #1e293b}
tr:hover td{background:#1e293b}
.btn{display:inline-block;padding:6px 14px;border-radius:6px;text-decoration:none;font-size:13px;font-weight:600;margin:2px;cursor:pointer}
.btn-green{background:#10b981;color:#fff}.btn-red{background:#ef4444;color:#fff}.btn-blue{background:#3b82f6;color:#fff}
.ok{color:#10b981}.warn{color:#f59e0b}.err{color:#ef4444}
.box{background:#1e293b;border-radius:8px;padding:15px;margin-bottom:20px}
pre{background:#1e293b;padding:10px;border-radius:6px;overflow-x:auto;font-size:12px;color:#94a3b8;max-height:300px;overflow-y:auto}
</style>
</head>
<body>
<h1>🔔 Diagnostic Notifications CRM</h1>

<div class="box">
<strong>Firebase Credentials:</strong>
<?php if($credOk): ?>
  <span class="ok">✅ Trouvé</span> — <?= htmlspecialchars($credInfo) ?>
<?php else: ?>
  <span class="err">❌ MANQUANT!</span> — Fichier requis: <code>app/Config/firebase_credentials.json</code>
<?php endif; ?>
</div>

<h2>📱 Tokens FCM</h2>
<?php if(empty($tokens)): ?>
<div class="box err">❌ Aucun token FCM. Connectez-vous sur l'app Flutter (staff + client) d'abord!</div>
<?php else: ?>
<table><tr><th>Clé</th><th>Token (preview)</th></tr>
<?php foreach($tokens as $t): ?>
<tr><td><?=htmlspecialchars($t['name'])?></td><td><code><?=htmlspecialchars($t['tok'])?>...</code></td></tr>
<?php endforeach; ?>
</table>
<?php endif; ?>

<h2>💳 Test Paiement</h2>
<?php if(empty($invoices)): ?>
<div class="box warn">⚠ Aucune facture non payée.</div>
<?php else: ?>
<table><tr><th>ID</th><th>N°</th><th>Total</th><th>Client</th><th>Agent</th><th>Action</th></tr>
<?php foreach($invoices as $inv): $amt=min((float)$inv['total'],10); ?>
<tr>
<td><?=$inv['id']?></td><td><?=htmlspecialchars($inv['formatted_number'])?></td>
<td><?=number_format($inv['total'],2)?></td><td><?=$inv['clientid']?></td><td><?=$inv['sale_agent']?></td>
<td><a class="btn btn-blue" href="?action=pay&inv=<?=$inv['id']?>&mode=<?=$modeId?>&amt=<?=$amt?>" target="_blank">💳 Payer <?=$amt?>€</a></td>
</tr>
<?php endforeach; ?>
</table>
<?php endif; ?>

<h2>📄 Test Devis (accept/decline)</h2>
<?php if(empty($estimates)): ?>
<div class="box warn">⚠ Aucun devis "Envoyé" (status=2).</div>
<?php else: ?>
<table><tr><th>ID</th><th>N°</th><th>Client</th><th>Agent</th><th>Contact</th><th>Actions</th></tr>
<?php foreach($estimates as $est):
$ct=$pdo->prepare("SELECT id,firstname,lastname FROM tblcontacts WHERE userid=? LIMIT 1");
$ct->execute([$est['clientid']]);$ct=$ct->fetch(PDO::FETCH_ASSOC);$cid=$ct['id']??0;
?>
<tr>
<td><?=$est['id']?></td><td><?=htmlspecialchars($est['formatted_number'])?></td>
<td><?=$est['clientid']?></td><td><?=$est['sale_agent']?></td>
<td><?=$cid ? "ID:$cid (".htmlspecialchars($ct['firstname'].' '.$ct['lastname']).")" : '<span class="err">Aucun</span>'?></td>
<td>
<?php if($cid): ?>
<a class="btn btn-green" href="?action=estimate&eid=<?=$est['id']?>&cid=<?=$cid?>&act=accept" target="_blank">✅ Accepter</a>
<a class="btn btn-red" href="?action=estimate&eid=<?=$est['id']?>&cid=<?=$cid?>&act=decline" target="_blank">❌ Décliner</a>
<?php endif; ?>
</td>
</tr>
<?php endforeach; ?>
</table>
<?php endif; ?>

<h2>📋 Test Offres (accept/decline)</h2>
<?php if(empty($proposals)): ?>
<div class="box warn">⚠ Aucune offre disponible.</div>
<?php else: ?>
<table><tr><th>ID</th><th>Sujet</th><th>Client</th><th>Staff</th><th>Contact</th><th>Actions</th></tr>
<?php foreach($proposals as $p):
$ct=$pdo->prepare("SELECT id,firstname,lastname FROM tblcontacts WHERE userid=? LIMIT 1");
$ct->execute([$p['rel_id']]);$ct=$ct->fetch(PDO::FETCH_ASSOC);$cid=$ct['id']??0;
?>
<tr>
<td><?=$p['id']?></td><td><?=htmlspecialchars($p['subject'])?></td>
<td><?=$p['rel_id']?></td><td><?=$p['assigned']?>/<?=$p['addedfrom']?></td>
<td><?=$cid?"ID:$cid":"<span class='err'>Aucun</span>"?></td>
<td>
<?php if($cid): ?>
<a class="btn btn-green" href="?action=proposal&pid=<?=$p['id']?>&cid=<?=$cid?>&act=accept" target="_blank">✅</a>
<a class="btn btn-red" href="?action=proposal&pid=<?=$p['id']?>&cid=<?=$cid?>&act=decline" target="_blank">❌</a>
<?php endif; ?>
</td>
</tr>
<?php endforeach; ?>
</table>
<?php endif; ?>

<h2>📬 Dernières notifications en BDD</h2>
<?php if(empty($notifs)): ?>
<div class="box warn">Aucune notification.</div>
<?php else: ?>
<table><tr><th>ID</th><th>Pour</th><th>Description</th><th>Date</th><th>Lu</th></tr>
<?php foreach($notifs as $n): ?>
<tr><td><?=$n['id']?></td><td><?=$n['touserid']?></td>
<td><?=htmlspecialchars($n['description'])?></td><td><?=$n['date']?></td>
<td><?=$n['isread']?'Oui':'<b>Non</b>'?></td></tr>
<?php endforeach; ?>
</table>
<?php endif; ?>

<h2>🪵 Logs FCM (aujourd'hui)</h2>
<pre><?=htmlspecialchars($logLines ?: 'Aucun log FCM trouvé pour aujourd\'hui.')?></pre>

<h2>👥 Contacts clients</h2>
<table><tr><th>Contact ID</th><th>Nom</th><th>Client ID</th><th>Société</th></tr>
<?php foreach($contacts as $c): ?>
<tr><td><?=$c['id']?></td><td><?=htmlspecialchars($c['firstname'].' '.$c['lastname'])?></td>
<td><?=$c['userid']?></td><td><?=htmlspecialchars($c['company']??'')?></td></tr>
<?php endforeach; ?>
</table>

</body></html>
