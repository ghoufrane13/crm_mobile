<?php

namespace App\Controllers;

use App\Models\SignatureModel;
use CodeIgniter\RESTful\ResourceController;
use CodeIgniter\API\ResponseTrait;

class SignatureController extends ResourceController
{
    use ResponseTrait;

    protected SignatureModel $signatureModel;

    // Types autorisés (quote = estimate, invoice = facture, proposal = offre)
    private array $allowedTypes = ['quote', 'invoice', 'proposal'];

    public function initController(
        \CodeIgniter\HTTP\RequestInterface  $request,
        \CodeIgniter\HTTP\ResponseInterface $response,
        \Psr\Log\LoggerInterface            $logger
    ): void {
        parent::initController($request, $response, $logger);
        $this->signatureModel = new SignatureModel();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // POST /api/signature/save
    // Body JSON : { rel_id, rel_type (quote|invoice|proposal), signature_data (base64 PNG) }
    // ─────────────────────────────────────────────────────────────────────────
    public function save()
    {
        $body    = $this->request->getJSON(true) ?? $this->request->getPost();
        $relId   = (int)($body['rel_id']   ?? 0);
        $relType = $body['rel_type']        ?? '';
        $sigData = $body['signature_data']  ?? '';

        if (!$relId || !$relType || !$sigData) {
            return $this->respond([
                'status'  => false,
                'message' => 'rel_id, rel_type et signature_data sont requis',
            ], 400);
        }

        if (!in_array($relType, $this->allowedTypes)) {
            return $this->respond([
                'status'  => false,
                'message' => "rel_type doit être : " . implode(', ', $this->allowedTypes),
            ], 400);
        }

        // Vérifier que le document existe en base
        $tableMap = [
            'invoice'  => 'tblinvoices',
            'quote'    => 'tblestimates',
            'proposal' => 'tblproposals',
        ];
        $table  = $tableMap[$relType];
        $pkMap  = [
            'invoice'  => 'id',
            'quote'    => 'id',
            'proposal' => 'id',
        ];
        $exists = \Config\Database::connect()
            ->table($table)
            ->where($pkMap[$relType], $relId)
            ->get()->getRowArray();

        if (!$exists) {
            return $this->respond([
                'status'  => false,
                'message' => "Document introuvable (id=$relId, type=$relType)",
            ], 404);
        }

        // Sauvegarder la signature (PNG + JSON) — sans toucher à la DB
        try {
            $relativePath = $this->signatureModel->saveSignature(
                $relType,
                $relId,
                $sigData,
                $this->request->getIPAddress()
            );
        } catch (\RuntimeException $e) {
            return $this->respond([
                'status'  => false,
                'message' => $e->getMessage(),
            ], 400);
        }

        return $this->respond([
            'status'    => true,
            'message'   => 'Signature enregistrée avec succès',
            'file'      => $relativePath,
            'signed_at' => date('Y-m-d H:i:s'),
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // GET /api/signature/{relType}/{relId}
    // Retourne les infos de signature + base64 de l'image
    // ─────────────────────────────────────────────────────────────────────────
    public function get($relType = null, $relId = null)
    {
        if (!in_array($relType, $this->allowedTypes)) {
            return $this->respond([
                'status'  => false,
                'message' => "relType doit être : " . implode(', ', $this->allowedTypes),
            ], 400);
        }

        $sig = $this->signatureModel->getSignature($relType, (int)$relId);

        if (!$sig) {
            return $this->respond([
                'status' => true,   // la requête a réussi
                'signed' => false,
                'data'   => null,
            ]);
        }

        return $this->respond([
            'status' => true,
            'signed' => true,
            'data'   => $sig,
        ]);
    }
}