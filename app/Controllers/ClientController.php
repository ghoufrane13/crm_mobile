<?php

namespace App\Controllers;

use CodeIgniter\RESTful\ResourceController;
use CodeIgniter\API\ResponseTrait;

class ClientController extends ResourceController
{
    use ResponseTrait;

    // ─────────────────────────────────────────────────────────────────────────
    // GET /api/clients
    //
    // Query params (all optional):
    //   ?search=acme        — filter by company name (case-insensitive LIKE)
    //   ?limit=200          — max rows returned  (default: 200, max: 500)
    //   ?page=1             — page number for cursor-style paging
    // ─────────────────────────────────────────────────────────────────────────
    public function index()
    {
        $db = \Config\Database::connect();

        $search = $this->request->getGet('search') ?? '';
        $limit  = min(500, max(1, (int)($this->request->getGet('limit') ?? 200)));
        $page   = max(1, (int)($this->request->getGet('page') ?? 1));
        $offset = ($page - 1) * $limit;

        $builder = $db->table('tblclients')
            ->select([
                'userid AS id',
                'company AS name',
                'email',
                'phonenumber AS phone',
                'city',
                'country',
            ])
            ->orderBy('company', 'ASC');

        if ($search !== '') {
            $builder->like('company', $search, 'both');
        }

        $total   = (clone $builder)->countAllResults(false);
        $clients = $builder->limit($limit, $offset)->get()->getResultArray();

        $clients = array_map(static function (array $c): array {
            return [
                'id'      => (int) $c['id'],
                'name'    => $c['name']    ?? '',
                'email'   => $c['email']   ?? '',
                'phone'   => $c['phone']   ?? '',
                'city'    => $c['city']    ?? '',
                'country' => $c['country'] ?? '',
            ];
        }, $clients);

        return $this->respond([
            'status' => 200,
            'data'   => $clients,
            'meta'   => [
                'total'       => $total,
                'page'        => $page,
                'limit'       => $limit,
                'total_pages' => max(1, (int) ceil($total / $limit)),
            ],
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // GET /api/clients/{id}
    // ─────────────────────────────────────────────────────────────────────────
    public function show($id = null)
    {
        $db     = \Config\Database::connect();
        $client = $db->table('tblclients')
            ->select([
                'userid AS id',
                'company AS name',
                'email',
                'phonenumber AS phone',
                'city',
                'country',
            ])
            ->where('userid', (int) $id)
            ->get()->getRowArray();

        if (!$client) {
            return $this->failNotFound("Client introuvable (id=$id)");
        }

        $client['id'] = (int) $client['id'];

        return $this->respond(['status' => 200, 'data' => $client]);
    }
}