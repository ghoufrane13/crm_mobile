<?php

namespace App\Controllers;

use CodeIgniter\RESTful\ResourceController;
use App\Models\PaymentModel;

class PaymentController extends ResourceController
{
    protected $format = 'json';
    protected PaymentModel $paymentModel;

    public function initController(
        \CodeIgniter\HTTP\RequestInterface  $request,
        \CodeIgniter\HTTP\ResponseInterface $response,
        \Psr\Log\LoggerInterface            $logger
    ): void {
        parent::initController($request, $response, $logger);
        $this->paymentModel = new PaymentModel();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Génère une référence de transaction unique : TXN-YYYYMMDD-XXXXXX
    // Utilisé si le client n'en fournit pas (ou fournit une chaîne vide)
    // ─────────────────────────────────────────────────────────────────────────
    private function _generateTransactionRef(): string
    {
        $date   = date('Ymd');
        $suffix = strtoupper(substr(bin2hex(random_bytes(4)), 0, 6));
        return "TXN-{$date}-{$suffix}";
    }

    // GET /api/payments/list[?invoice_id=X]
    public function list()
    {
        $invoiceId = $this->request->getVar('invoice_id');
        $payments  = $this->paymentModel->getList(
            $invoiceId ? (int)$invoiceId : null
        );
        return $this->respond([
            'status' => true,
            'data'   => $payments,
            'total'  => count($payments),
        ]);
    }

    // GET /api/payments/detail/:id
    public function detail($id = null)
    {
        if (!$id) return $this->respond(
            ['status' => false, 'message' => 'ID manquant'], 400);

        $payment = $this->paymentModel->getDetail((int)$id);
        if (!$payment) return $this->respond(
            ['status' => false, 'message' => 'Règlement introuvable'], 404);

        return $this->respond(['status' => true, 'payment' => $payment]);
    }

    // GET /api/payments/modes
    public function modes()
    {
        $modes = $this->paymentModel->getPaymentModes();
        return $this->respond(['status' => true, 'modes' => $modes]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // GET /api/payments/generate-ref
    // Endpoint optionnel : retourne une référence de transaction pré-générée
    // pour affichage immédiat dans le formulaire Flutter avant soumission
    // ─────────────────────────────────────────────────────────────────────────
    public function generateRef()
    {
        return $this->respond([
            'status'    => true,
            'reference' => $this->_generateTransactionRef(),
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // POST /api/payments/create
    // ✅ Référence de transaction auto-générée si absente
    // ✅ Écrit dans tblinvoicepaymentrecords + trace dans tblpayment_attempts
    // ─────────────────────────────────────────────────────────────────────────
    public function create()
    {
        $data      = $this->request->getJSON(true);
        $invoiceId = (int)($data['invoice_id']      ?? 0);
        $amount    = (float)($data['amount']         ?? 0);
        $gatewayId = (int)($data['payment_gateway'] ?? 0);
        $fee       = round((float)($data['fee']      ?? 0), 2);
        $note      = $data['note']      ?? null;
        $createdAt = $data['created_at'] ?? date('Y-m-d H:i:s');
        $dateOnly  = substr($createdAt, 0, 10);

        // ── Référence : utiliser celle fournie, ou en générer une ─────────────
        $reference = trim($data['reference'] ?? '');
        if (empty($reference)) {
            $reference = $this->_generateTransactionRef();
        }

        if (!$invoiceId) return $this->respond(
            ['status' => false, 'message' => 'invoice_id requis'], 400);
        if ($amount <= 0) return $this->respond(
            ['status' => false, 'message' => 'Montant invalide (doit être > 0)'], 400);
        if (!$gatewayId) return $this->respond(
            ['status' => false, 'message' => 'Mode de règlement requis'], 400);

        $db = \Config\Database::connect();

        // ── Vérifier la facture ───────────────────────────────────────────────
        $invoice = $db->table('tblinvoices')
            ->select('id, total, status')
            ->where('id', $invoiceId)
            ->get()->getRowArray();

        if (!$invoice) return $this->respond(
            ['status' => false, 'message' => 'Facture introuvable'], 404);

        if ((int)$invoice['status'] === 2) {
            return $this->respond([
                'status'  => false,
                'message' => 'Cette facture est déjà entièrement payée.',
            ], 400);
        }

        // ── Vérifier le solde restant ─────────────────────────────────────────
        $totalPaid = $this->paymentModel->getTotalPaid($invoiceId);
        $total     = (float)$invoice['total'];
        $totalDue  = round(max(0, $total - $totalPaid), 2);

        if ($amount > $totalDue + 0.001) {
            return $this->respond([
                'status'  => false,
                'message' => "Le montant ($amount) dépasse le solde restant ($totalDue).",
            ], 400);
        }

        // ── Récupérer le nom du mode de paiement ─────────────────────────────
        $mode = $db->table('tblpayment_modes')
            ->select('id, name')
            ->where('id', $gatewayId)
            ->where('active', 1)
            ->get()->getRowArray();

        if (!$mode) return $this->respond(
            ['status' => false, 'message' => 'Mode de paiement invalide'], 400);

        $gatewayName = $mode['name'];

        // ── Anti-doublon sur la référence ─────────────────────────────────────
        $existingRef = $db->table('tblinvoicepaymentrecords')
            ->where('transactionid', $reference)
            ->get()->getRowArray();
        if ($existingRef) {
            // Si doublon, regénérer silencieusement
            $reference = $this->_generateTransactionRef();
        }

        // ── Insérer dans tblinvoicepaymentrecords ─────────────────────────────
        $db->table('tblinvoicepaymentrecords')->insert([
            'invoiceid'     => $invoiceId,
            'amount'        => round($amount, 2),
            'paymentmode'   => $gatewayName,
            'paymentmethod' => $gatewayName,
            'transactionid' => $reference,
            'note'          => $note,
            'date'          => $dateOnly,
            'daterecorded'  => date('Y-m-d H:i:s'),
        ]);
        $paymentId = $db->insertID();

        // ── Tracer dans tblpayment_attempts (fee + gateway_id) ────────────────
        try {
            $db->table('tblpayment_attempts')->insert([
                'reference'       => $reference,
                'invoice_id'      => $invoiceId,
                'amount'          => round($amount, 2),
                'fee'             => $fee,
                'payment_gateway' => $gatewayId,
                'created_at'      => date('Y-m-d H:i:s'),
            ]);
        } catch (\Exception $e) {
            log_message('warning', 'tblpayment_attempts insert failed: ' . $e->getMessage());
        }

        // ── Recalculer le statut de la facture ────────────────────────────────
        $newTotalPaid = $this->paymentModel->getTotalPaid($invoiceId);
        $newTotalDue  = round(max(0, $total - $newTotalPaid), 2);

        if ($newTotalDue <= 0) {
            $newStatus = 2; // Payée
        } elseif ($newTotalPaid > 0) {
            $newStatus = 4; // Partiellement payée
        } else {
            $newStatus = 1; // Impayée
        }

        $db->table('tblinvoices')
            ->where('id', $invoiceId)
            ->update(['status' => $newStatus]);

        return $this->respond([
            'status'     => true,
            'message'    => 'Règlement enregistré avec succès',
            'payment_id' => $paymentId,
            'reference'  => $reference,   // ← retourner la référence utilisée
            'total_paid' => round($newTotalPaid, 2),
            'total_due'  => $newTotalDue,
            'new_status' => $newStatus,
        ], 201);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // PUT /api/payments/update/:id
    // ─────────────────────────────────────────────────────────────────────────
    public function update($id = null)
    {
        if (!$id) return $this->respond(
            ['status' => false, 'message' => 'ID manquant'], 400);

        $db      = \Config\Database::connect();
        $payment = $db->table('tblinvoicepaymentrecords')
            ->where('id', (int)$id)->get()->getRowArray();

        if (!$payment) return $this->respond(
            ['status' => false, 'message' => 'Règlement introuvable'], 404);

        $data = $this->request->getJSON(true);
        $upd  = [];

        if (isset($data['amount']))    $upd['amount']        = round((float)$data['amount'], 2);
        if (isset($data['reference'])) $upd['transactionid'] = $data['reference'];
        if (isset($data['note']))      $upd['note']          = $data['note'];

        if (isset($data['payment_gateway'])) {
            $mode = $db->table('tblpayment_modes')
                ->select('name')
                ->where('id', (int)$data['payment_gateway'])
                ->where('active', 1)
                ->get()->getRowArray();
            if ($mode) {
                $upd['paymentmode']   = $mode['name'];
                $upd['paymentmethod'] = $mode['name'];
            }
        }

        if (!empty($upd)) {
            $db->table('tblinvoicepaymentrecords')
                ->where('id', (int)$id)->update($upd);
        }

        $this->_recalcStatus($db, (int)$payment['invoiceid']);

        return $this->respond(['status' => true, 'message' => 'Règlement mis à jour']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // DELETE /api/payments/delete/:id
    // ─────────────────────────────────────────────────────────────────────────
    public function delete($id = null)
    {
        if (!$id) return $this->respond(
            ['status' => false, 'message' => 'ID manquant'], 400);

        $db      = \Config\Database::connect();
        $payment = $db->table('tblinvoicepaymentrecords')
            ->select('id, invoiceid')
            ->where('id', (int)$id)->get()->getRowArray();

        if (!$payment) return $this->respond(
            ['status' => false, 'message' => 'Règlement introuvable'], 404);

        $invoiceId = (int)$payment['invoiceid'];
        $db->table('tblinvoicepaymentrecords')->where('id', (int)$id)->delete();
        $this->_recalcStatus($db, $invoiceId);

        return $this->respond(['status' => true, 'message' => 'Règlement supprimé']);
    }

    // ── Helper recalcul statut ────────────────────────────────────────────────
    private function _recalcStatus($db, int $invoiceId): void
    {
        $invoice = $db->table('tblinvoices')
            ->select('total')->where('id', $invoiceId)
            ->get()->getRowArray();
        if (!$invoice) return;

        $totalPaid = $this->paymentModel->getTotalPaid($invoiceId);
        $total     = (float)$invoice['total'];
        $totalDue  = round(max(0, $total - $totalPaid), 2);

        $newStatus = $totalDue <= 0 ? 2 : ($totalPaid > 0 ? 4 : 1);
        $db->table('tblinvoices')
            ->where('id', $invoiceId)
            ->update(['status' => $newStatus]);
    }

    // =========================================================================
    // STRIPE
    // =========================================================================

    /**
     * POST /api/payments/stripe/create-intent
     */
    public function createStripeIntent()
    {
        $data      = $this->request->getJSON(true);
        $invoiceId = (int)($data['invoice_id']  ?? 0);
        $clientId  = (int)($data['client_id']   ?? 0);
        $reqAmount = (float)($data['amount']    ?? 0);

        if (!$invoiceId) return $this->respond(
            ['status' => false, 'message' => 'invoice_id requis'], 400);

        $db = \Config\Database::connect();

        $invoice = $db->table('tblinvoices')
            ->select('id, total, status, currency')
            ->where('id', $invoiceId)
            ->get()->getRowArray();

        if (!$invoice) return $this->respond(
            ['status' => false, 'message' => 'Facture introuvable'], 404);

        if ((int)$invoice['status'] === 2) return $this->respond([
            'status'  => false,
            'message' => 'Cette facture est déjà entièrement payée.',
        ], 400);

        $totalPaid = $this->paymentModel->getTotalPaid($invoiceId);
        $totalDue  = round(max(0, (float)$invoice['total'] - $totalPaid), 2);

        if ($totalDue <= 0) return $this->respond([
            'status'  => false,
            'message' => 'Aucun solde restant à payer.',
        ], 400);

        $amountToPay = $totalDue;
        if ($reqAmount > 0 && $reqAmount <= $totalDue + 0.001) {
            $amountToPay = round($reqAmount, 2);
        } elseif ($reqAmount > $totalDue + 0.001) {
            return $this->respond([
                'status'  => false,
                'message' => 'Le montant demandé dépasse le solde restant.',
            ], 400);
        }

        $currency  = 'usd';
        $amountInt = (int)round($amountToPay * 100);

        $stripeKey = env('STRIPE_SECRET_KEY', '');
        if (!$stripeKey) return $this->respond([
            'status'  => false,
            'message' => 'Clé Stripe non configurée.',
        ], 500);

        $ch = curl_init('https://api.stripe.com/v1/payment_intents');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_USERPWD        => "$stripeKey:",
            CURLOPT_POSTFIELDS     => http_build_query([
                'amount'                   => $amountInt,
                'currency'                 => $currency,
                'payment_method_types[]'   => 'card',
                'metadata[invoice_id]'     => $invoiceId,
                'metadata[client_id]'      => $clientId,
            ]),
            CURLOPT_HTTPHEADER => [
                'Stripe-Version: 2024-06-20',
                'Content-Type: application/x-www-form-urlencoded',
            ],
        ]);
        $response   = curl_exec($ch);
        $httpStatus = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError  = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            log_message('error', 'Stripe cURL Error: ' . $curlError);
            return $this->respond([
                'status'  => false,
                'message' => 'Erreur cURL : ' . $curlError,
            ], 500);
        }

        $stripe = json_decode($response, true);

        if ($httpStatus !== 200) {
            log_message('error', 'Stripe API Error: ' . json_encode($stripe));
            return $this->respond([
                'status'  => false,
                'message' => $stripe['error']['message'] ?? 'Erreur Stripe',
            ], 400);
        }

        return $this->respond([
            'status'            => true,
            'client_secret'     => $stripe['client_secret'],
            'payment_intent_id' => $stripe['id'],
            'amount'            => $amountToPay,
            'currency'          => $currency,
        ]);
    }

    /**
     * POST /api/payments/stripe/confirm
     */
    public function confirmStripePayment()
    {
        $data            = $this->request->getJSON(true);
        $paymentIntentId = $data['payment_intent_id'] ?? '';
        $invoiceId       = (int)($data['invoice_id']  ?? 0);

        if (!$paymentIntentId || !$invoiceId) return $this->respond([
            'status'  => false,
            'message' => 'payment_intent_id et invoice_id requis',
        ], 400);

        $stripeKey = env('STRIPE_SECRET_KEY', '');
        if (!$stripeKey) return $this->respond([
            'status'  => false,
            'message' => 'Clé Stripe non configurée.',
        ], 500);

        $ch = curl_init("https://api.stripe.com/v1/payment_intents/$paymentIntentId");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_USERPWD        => "$stripeKey:",
            CURLOPT_HTTPHEADER     => ['Stripe-Version: 2024-06-20'],
        ]);
        $response   = curl_exec($ch);
        $httpStatus = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpStatus !== 200) return $this->respond([
            'status'  => false,
            'message' => 'Impossible de vérifier le paiement Stripe.',
        ], 400);

        $pi = json_decode($response, true);

        if (($pi['status'] ?? '') !== 'succeeded') return $this->respond([
            'status'  => false,
            'message' => 'Paiement non confirmé par Stripe (statut : ' . ($pi['status'] ?? '?') . ').',
        ], 400);

        $db = \Config\Database::connect();

        // Anti-doublon
        $existing = $db->table('tblinvoicepaymentrecords')
            ->where('transactionid', $paymentIntentId)
            ->get()->getRowArray();

        if ($existing) return $this->respond([
            'status'  => false,
            'message' => 'Ce paiement a déjà été enregistré.',
        ], 409);

        $zeroDecimal = ['bif','clp','gnf','jpy','kmf','krw','mga','pyg','rwf','ugx','vnd','xaf','xof'];
        $currency    = strtolower($pi['currency'] ?? 'usd');
        $amount      = in_array($currency, $zeroDecimal)
            ? (float)$pi['amount']
            : round((float)$pi['amount'] / 100, 2);

        $db->table('tblinvoicepaymentrecords')->insert([
            'invoiceid'     => $invoiceId,
            'amount'        => $amount,
            'paymentmode'   => 'Stripe',
            'paymentmethod' => 'stripe',
            'date'          => date('Y-m-d'),
            'daterecorded'  => date('Y-m-d H:i:s'),
            'note'          => 'Paiement en ligne via Stripe (mode test)',
            'transactionid' => $paymentIntentId,
        ]);
        $paymentId = $db->insertID();

        $invoice = $db->table('tblinvoices')
            ->select('total')
            ->where('id', $invoiceId)
            ->get()->getRowArray();

        $newTotalPaid = $this->paymentModel->getTotalPaid($invoiceId);
        $totalDue     = round(max(0, (float)$invoice['total'] - $newTotalPaid), 2);
        $newStatus    = $totalDue <= 0 ? 2 : ($newTotalPaid > 0 ? 4 : 1);

        $db->table('tblinvoices')
            ->where('id', $invoiceId)
            ->update(['status' => $newStatus]);

        return $this->respond([
            'status'      => true,
            'message'     => 'Paiement Stripe enregistré avec succès.',
            'payment_id'  => $paymentId,
            'amount'      => $amount,
            'total_due'   => $totalDue,
            'new_status'  => $newStatus,
        ], 201);
    }
}