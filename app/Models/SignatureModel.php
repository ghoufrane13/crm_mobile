<?php

namespace App\Models;

use CodeIgniter\Model;

/**
 * SignatureModel
 *
 * Stocke les signatures dans des fichiers JSON + PNG
 * SANS modifier la base de données (aucune colonne ajoutée).
 *
 * Structure fichiers :
 *   public/uploads/signatures/{relType}_{relId}.png   ← image signature
 *   public/uploads/signatures/{relType}_{relId}.json  ← métadonnées (date, IP)
 *
 * relType : 'invoice' | 'quote'
 */
class SignatureModel extends Model
{
    protected $table      = 'tblinvoices';
    protected $primaryKey = 'id';
    protected $returnType = 'array';

    // ─────────────────────────────────────────────────────────────────────────
    // Chemins fichiers
    // ─────────────────────────────────────────────────────────────────────────
    private function sigDir(): string
    {
        $dir = ROOTPATH . 'public/uploads/signatures/';
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        return $dir;
    }

    private function pngPath(string $relType, int $relId): string
    {
        return $this->sigDir() . "{$relType}_{$relId}.png";
    }

    private function jsonPath(string $relType, int $relId): string
    {
        return $this->sigDir() . "{$relType}_{$relId}.json";
    }

    private function pngRelative(string $relType, int $relId): string
    {
        return "uploads/signatures/{$relType}_{$relId}.png";
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Résolution de table (pour vérifier l'existence du document seulement)
    // ─────────────────────────────────────────────────────────────────────────
    private function resolveTable(string $relType): string
    {
        return match ($relType) {
            'invoice' => 'tblinvoices',
            'quote'   => 'tblestimates',
            default   => throw new \InvalidArgumentException("relType doit être 'invoice' ou 'quote'"),
        };
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Sauvegarder une signature — SANS toucher à la base de données
    // ─────────────────────────────────────────────────────────────────────────
    public function saveSignature(string $relType, int $relId, string $base64Data, string $ip): string
    {
        // Décoder le base64 (avec ou sans préfixe data:image/png;base64,)
        $imageData = $base64Data;
        if (strpos($base64Data, ',') !== false) {
            $imageData = explode(',', $base64Data)[1];
        }
        $decoded = base64_decode($imageData);
        if ($decoded === false) {
            throw new \RuntimeException('signature_data invalide (base64 attendu)');
        }

        $signedAt     = date('Y-m-d H:i:s');
        $relativePath = $this->pngRelative($relType, $relId);

        // Écrire l'image PNG
        file_put_contents($this->pngPath($relType, $relId), $decoded);

        // Écrire les métadonnées JSON
        file_put_contents($this->jsonPath($relType, $relId), json_encode([
            'rel_type'       => $relType,
            'rel_id'         => $relId,
            'signature_file' => $relativePath,
            'signed_at'      => $signedAt,
            'signed_ip'      => $ip,
        ], JSON_PRETTY_PRINT));

        return $relativePath;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Récupérer les infos de signature d'un document
    // ─────────────────────────────────────────────────────────────────────────
    public function getSignature(string $relType, int $relId): ?array
    {
        $jsonFile = $this->jsonPath($relType, $relId);
        if (!file_exists($jsonFile)) {
            return null;
        }

        $meta    = json_decode(file_get_contents($jsonFile), true);
        $pngFile = $this->pngPath($relType, $relId);

        if (!$meta || !file_exists($pngFile)) {
            return null;
        }

        $base64 = 'data:image/png;base64,' . base64_encode(file_get_contents($pngFile));

        return [
            'signature_url' => base_url($meta['signature_file']),
            'signature_b64' => $base64,
            'signed_at'     => $meta['signed_at'],
            'signed_ip'     => $meta['signed_ip'],
            'file_exists'   => true,
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Vérifier si un document est signé
    // ─────────────────────────────────────────────────────────────────────────
    public function isSigned(string $relType, int $relId): bool
    {
        return file_exists($this->jsonPath($relType, $relId))
            && file_exists($this->pngPath($relType, $relId));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Bloc HTML de signature pour injection dans les PDFs TCPDF
    // ─────────────────────────────────────────────────────────────────────────
    public function getSignatureHtmlBlock(string $relType, int $relId): string
    {
        $sig = $this->getSignature($relType, $relId);
        if (!$sig) return '';

        return '
            <div style="margin-top:30px;border-top:1px solid #ccc;padding-top:15px;page-break-inside:avoid;">
                <p style="font-size:10px;color:#555;margin:0 0 8px;">
                    <strong>Signature électronique</strong><br>
                    Signé le : ' . htmlspecialchars($sig['signed_at'] ?? '') . '<br>
                    IP : ' . htmlspecialchars($sig['signed_ip'] ?? '') . '
                </p>
                <img src="' . htmlspecialchars($sig['signature_b64'] ?? '') . '"
                     style="max-height:80px;max-width:300px;border:1px solid #eee;display:block;" />
            </div>
        ';
    }

    // ─────────────────────────────────────────────────────────────────────────
    // ensureColumns — conservé pour compatibilité, ne fait rien
    // ─────────────────────────────────────────────────────────────────────────
    public function ensureColumns(string $relType): void
    {
        // Intentionnellement vide — aucune modification de la base de données.
    }
}