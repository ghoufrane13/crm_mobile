<?php

namespace App\Controllers;

use CodeIgniter\RESTful\ResourceController;
use CodeIgniter\API\ResponseTrait;

/**
 * ClientController
 *
 * Provides a read-only client list endpoint consumed by the Flutter app
 * when a task is linked to a customer (rel_type = 'customer').
 *
 * Route to add in app/Config/Routes.php:
 *   $routes->get('api/clients', 'ClientController::index');
 */
class ClientController extends ResourceController
{
    use ResponseTrait;

    // ─────────────────────────────────────────────────────────────────────────
    // GET /api/clients
    //
    // Query params (all optional):
    //   ?search=acme        — filter by company name (case-insensitive LIKE)
    //   ?active=1           — filter by active flag (default: active only)
    //   ?limit=200          — max rows returned  (default: 200, max: 500)
    //   ?page=1             — page number for cursor-style paging
    // ─────────────────────────────────────────────────────────────────────────
    public function index()
    {
        $db = \Config\Database::connect();

        $search = $this->request->getGet('search') ?? '';
        $active = $this->request->getGet('active') ?? '1';   // default: active clients only
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
                'active',
            ])
            ->orderBy('company', 'ASC');

        // Active filter — pass active=0 to include inactive clients
        if ($active !== '') {
            $builder->where('active', (int)$active);
        }

        // Full-text search on company name
        if ($search !== '') {
            $builder->like('company', $search, 'both');
        }

        $total   = (clone $builder)->countAllResults(false);
        $clients = $builder->limit($limit, $offset)->get()->getResultArray();

        // Normalise types so Flutter JSON parsing is predictable
        $clients = array_map(static function (array $c): array {
            return [
                'id'      => (int) $c['id'],
                'name'    => $c['name']  ?? '',
                'email'   => $c['email'] ?? '',
                'phone'   => $c['phone'] ?? '',
                'city'    => $c['city']  ?? '',
                'country' => $c['country'] ?? '',
                'active'  => (int) $c['active'],
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
    // Returns a single client — useful for displaying the linked client name
    // when editing an existing task.
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
                'active',
            ])
            ->where('userid', (int) $id)
            ->get()->getRowArray();

        if (!$client) {
            return $this->failNotFound("Client introuvable (id=$id)");
        }

        $client['id']     = (int) $client['id'];
        $client['active'] = (int) $client['active'];

        return $this->respond(['status' => 200, 'data' => $client]);
    }
}