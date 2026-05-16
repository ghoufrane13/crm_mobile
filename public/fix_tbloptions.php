<?php
/**
 * fix_tbloptions.php — Ajoute le UNIQUE KEY manquant sur tbloptions.name
 *
 * PROBLÈME : Le code NotificationController utilise
 *   INSERT INTO tbloptions ... ON DUPLICATE KEY UPDATE
 * mais la table n'a PAS de contrainte UNIQUE sur `name`.
 * Sans UNIQUE KEY, MySQL ne détecte jamais de doublon → l'UPDATE ne se
 * déclenche jamais. De plus, des insertions peuvent échouer silencieusement
 * si un doublon existe sans contrainte.
 *
 * EXÉCUTION : une seule fois via http://localhost/fix_tbloptions.php
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

$host = 'localhost';
$db   = 'crm_db';
$user = 'root';
$pass = '';

echo "<h2>🔧 Fix tbloptions — Ajout UNIQUE KEY sur `name`</h2>";

try {
    $pdo = new PDO("mysql:host=$host;dbname=$db;charset=utf8mb4", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);

    // 1. Vérifier si le UNIQUE KEY existe déjà
    $indexes = $pdo->query("SHOW INDEX FROM `tbloptions` WHERE Column_name = 'name'")->fetchAll(PDO::FETCH_ASSOC);
    $hasUnique = false;
    foreach ($indexes as $idx) {
        if ((int)$idx['Non_unique'] === 0) {
            $hasUnique = true;
            break;
        }
    }

    if ($hasUnique) {
        echo "<p>✅ UNIQUE KEY sur <code>name</code> existe déjà — rien à faire.</p>";
    } else {
        // 2. Supprimer les doublons éventuels AVANT d'ajouter la contrainte
        $dupes = $pdo->query("
            SELECT name, COUNT(*) as cnt FROM tbloptions
            GROUP BY name HAVING cnt > 1
        ")->fetchAll(PDO::FETCH_ASSOC);

        if (!empty($dupes)) {
            echo "<p>⚠️ Doublons détectés, nettoyage…</p>";
            foreach ($dupes as $d) {
                $name = $d['name'];
                // Garder le dernier ID (le plus récent), supprimer les autres
                $pdo->exec("
                    DELETE t1 FROM tbloptions t1
                    INNER JOIN tbloptions t2
                    WHERE t1.name = t2.name AND t1.id < t2.id
                    AND t1.name = " . $pdo->quote($name)
                );
                echo "<p>  → Nettoyé doublon pour <code>{$name}</code></p>";
            }
        }

        // 3. Ajouter la contrainte UNIQUE
        $pdo->exec("ALTER TABLE `tbloptions` ADD UNIQUE KEY `unique_name` (`name`)");
        echo "<p>✅ UNIQUE KEY <code>unique_name</code> ajouté avec succès sur <code>tbloptions.name</code></p>";
    }

    // 4. Afficher l'état actuel
    $rows = $pdo->query("SELECT id, name, SUBSTRING(value,1,40) as val_preview, autoload FROM tbloptions ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
    echo "<h3>Contenu actuel de tbloptions (" . count($rows) . " lignes)</h3>";
    echo "<table border='1' cellpadding='6' style='border-collapse:collapse; font-family:monospace; font-size:13px'>";
    echo "<tr><th>id</th><th>name</th><th>value (40 chars)</th><th>autoload</th></tr>";
    foreach ($rows as $r) {
        echo "<tr><td>{$r['id']}</td><td>{$r['name']}</td><td>{$r['val_preview']}</td><td>{$r['autoload']}</td></tr>";
    }
    echo "</table>";

    // 5. Vérifier les index
    echo "<h3>Index sur tbloptions</h3>";
    $idxs = $pdo->query("SHOW INDEX FROM `tbloptions`")->fetchAll(PDO::FETCH_ASSOC);
    echo "<table border='1' cellpadding='6' style='border-collapse:collapse; font-family:monospace; font-size:13px'>";
    echo "<tr><th>Key_name</th><th>Column_name</th><th>Non_unique</th></tr>";
    foreach ($idxs as $i) {
        echo "<tr><td>{$i['Key_name']}</td><td>{$i['Column_name']}</td><td>{$i['Non_unique']}</td></tr>";
    }
    echo "</table>";

    echo "<p style='color:green; font-weight:bold'>✅ Terminé. Vous pouvez supprimer ce fichier.</p>";

} catch (Exception $e) {
    echo "<p style='color:red'>❌ Erreur : " . htmlspecialchars($e->getMessage()) . "</p>";
}
