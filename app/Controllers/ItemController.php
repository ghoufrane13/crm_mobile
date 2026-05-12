<?php

namespace App\Controllers;

use App\Models\ItemModel;
use CodeIgniter\RESTful\ResourceController;
use CodeIgniter\API\ResponseTrait;

class ItemController extends ResourceController
{
    use ResponseTrait;

    protected ItemModel $itemModel;

    public function initController(
        \CodeIgniter\HTTP\RequestInterface $request,
        \CodeIgniter\HTTP\ResponseInterface $response,
        \Psr\Log\LoggerInterface $logger
    ): void {
        parent::initController($request, $response, $logger);
        $this->itemModel = new ItemModel();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // GET /api/items?q=&page=1&limit=25&group_id=
    // ─────────────────────────────────────────────────────────────────────────
    public function index()
    {
        $q       = $this->request->getGet('q')        ?? '';
        $page    = max(1, (int)($this->request->getGet('page')  ?? 1));
        $limit   = max(1, (int)($this->request->getGet('limit') ?? 25));
        $groupId = $this->request->getGet('group_id');

        $result = $this->itemModel->getList(
            $q,
            $page,
            $limit,
            $groupId !== null ? (int)$groupId : null
        );

        return $this->respond(['status' => 200] + $result);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // GET /api/items/search?q=
    //
    // CORRECTION CRITIQUE :
    //   ❌ Avant : strlen($q) < 1 → retournait [] quand q est vide,
    //              ce qui empêchait l'affichage de tous les articles au démarrage.
    //   ✅ Après  : q vide → retourne TOUS les articles (via searchAll()).
    //              q non vide → filtre dynamiquement via search($q).
    //
    // NOTE : searchAll() est maintenant défini dans ItemModel — l'absence de
    //        cette méthode était la cause du "Erreur serveur 500" affiché dans
    //        l'application Flutter.
    // ─────────────────────────────────────────────────────────────────────────
    public function search()
    {
        $q = trim($this->request->getGet('q') ?? '');

        // Lorsque la recherche est vide, on retourne la liste complète des articles
        // afin que l'écran Flutter affiche tous les articles dès l'ouverture.
        if ($q === '') {
            return $this->respond([
                'status' => 200,
                'data'   => $this->itemModel->searchAll(),
            ]);
        }

        return $this->respond([
            'status' => 200,
            'data'   => $this->itemModel->search($q),
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // GET /api/items/{id}
    // ─────────────────────────────────────────────────────────────────────────
    public function show($id = null)
    {
        $item = $this->itemModel->getDetail((int)$id);
        if (!$item) {
            return $this->failNotFound("Article introuvable (id=$id)");
        }
        return $this->respond(['status' => 200, 'data' => $item]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // POST /api/items
    // CORRECTION CRITIQUE : Model::insert() retourne l'ID inséré directement
    //   ❌ Avant : $this->itemModel->insert($data); $this->itemModel->db->insertID()
    //              → FATAL ERROR : $db est protected dans CI4 Model
    //   ✅ Après  : $newId = (int)$this->itemModel->insert($data);
    // ─────────────────────────────────────────────────────────────────────────
    public function create()
    {
        $body = $this->request->getJSON(true) ?? $this->request->getPost();

        $desc = trim($body['description'] ?? '');
        $rate = $body['rate'] ?? null;

        if ($desc === '') {
            return $this->fail('La désignation est obligatoire', 400);
        }
        if ($rate === null || !is_numeric($rate)) {
            return $this->fail('Le taux (rate) est obligatoire et doit être un nombre', 400);
        }

        $data = [
            'description'      => $desc,
            'long_description' => $body['long_description'] ?? null,
            'rate'             => (float)$rate,
            'rate_currency_2'  => (isset($body['rate_currency_2']) && $body['rate_currency_2'] !== '')
                ? (float)$body['rate_currency_2'] : null,
            'tax'              => !empty($body['tax'])  ? (int)$body['tax']  : null,
            'tax2'             => !empty($body['tax2']) ? (int)$body['tax2'] : null,
            'unit'             => $body['unit']     ?? null,
            'group_id'         => (int)($body['group_id'] ?? 0),
        ];

        // ✅ insert() en CI4 retourne l'ID inséré (int) ou false
        $newId = $this->itemModel->insert($data);

        if (!$newId) {
            return $this->fail('Erreur lors de la création de l\'article', 500);
        }

        return $this->respondCreated([
            'status'  => 201,
            'message' => 'Article créé avec succès',
            'id'      => (int)$newId,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // PUT /api/items/{id}
    // ─────────────────────────────────────────────────────────────────────────
    public function update($id = null)
    {
        if (!$this->itemModel->find((int)$id)) {
            return $this->failNotFound("Article introuvable (id=$id)");
        }

        $body = $this->request->getJSON(true) ?? [];
        $data = [];

        if (isset($body['description']))      $data['description']      = trim($body['description']);
        if (isset($body['long_description'])) $data['long_description'] = $body['long_description'];
        if (isset($body['rate']))             $data['rate']             = (float)$body['rate'];
        if (array_key_exists('rate_currency_2', $body))
            $data['rate_currency_2'] = ($body['rate_currency_2'] !== '') ? (float)$body['rate_currency_2'] : null;
        if (array_key_exists('tax', $body))   $data['tax']  = !empty($body['tax'])  ? (int)$body['tax']  : null;
        if (array_key_exists('tax2', $body))  $data['tax2'] = !empty($body['tax2']) ? (int)$body['tax2'] : null;
        if (isset($body['unit']))             $data['unit']     = $body['unit'];
        if (isset($body['group_id']))         $data['group_id'] = (int)$body['group_id'];

        if (empty($data)) {
            return $this->fail('Aucune donnée à mettre à jour', 400);
        }

        $this->itemModel->update((int)$id, $data);

        return $this->respond(['status' => 200, 'message' => 'Article mis à jour avec succès']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // DELETE /api/items/{id}
    // Vérifie dans tblitemable (vraie table Perfex — pas tblinvoiceitems)
    // ─────────────────────────────────────────────────────────────────────────
    public function delete($id = null)
    {
        $item = $this->itemModel->find((int)$id);
        if (!$item) {
            return $this->failNotFound("Article introuvable (id=$id)");
        }

        $used = $this->itemModel->isUsed((int)$id);
        if ($used['invoices'] > 0 || $used['quotes'] > 0) {
            return $this->fail(
                "Impossible de supprimer : cet article est utilisé dans {$used['invoices']} facture(s) et {$used['quotes']} devis.",
                409
            );
        }

        $this->itemModel->delete((int)$id);

        return $this->respondDeleted(['status' => 200, 'message' => 'Article supprimé avec succès']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // POST /api/items/{id}/duplicate
    // ─────────────────────────────────────────────────────────────────────────
    public function duplicate($id = null)
    {
        $newId = $this->itemModel->duplicate((int)$id);
        if (!$newId) {
            return $this->failNotFound("Article introuvable (id=$id)");
        }
        return $this->respondCreated([
            'status'  => 201,
            'message' => 'Article dupliqué avec succès',
            'id'      => $newId,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // GET /api/items/groups
    // Retourne { id, group_name } — alias correspondant à Flutter
    // ─────────────────────────────────────────────────────────────────────────
    public function groups()
    {
        return $this->respond(['status' => 200, 'data' => $this->itemModel->getGroups()]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // GET /api/items/taxes
    // Retourne { id, taxname, taxrate } — alias correspondant à Flutter
    // ─────────────────────────────────────────────────────────────────────────
    public function taxes()
    {
        return $this->respond(['status' => 200, 'data' => $this->itemModel->getTaxes()]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // GET /api/items/units
    // ─────────────────────────────────────────────────────────────────────────
    public function units()
    {
        return $this->respond(['status' => 200, 'data' => $this->itemModel->getUnits()]);
    }
}