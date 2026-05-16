<?php

namespace App\Libraries;

/**
 * Conversion de devises pour les paiements partiels multi-devises.
 * Stripe : EUR / USD — Paymee : TND
 * Taux configurables via .env (EXCHANGE_RATE_EUR_TND, etc.)
 */
class CurrencyHelper
{
  private static array $codeCache = [];

  /** Taux : 1 unité de la clé gauche = X TND */
  private static function ratesToTnd(): array
  {
    return [
      'TND' => 1.0,
      'EUR' => (float) env('EXCHANGE_RATE_EUR_TND', 3.35),
      'USD' => (float) env('EXCHANGE_RATE_USD_TND', 3.10),
    ];
  }

  public static function normalizeCode(?string $code): string
  {
    $c = strtoupper(trim((string) $code));
    if ($c === '') return 'TND';
    if (in_array($c, ['€', 'EURO'], true)) return 'EUR';
    if ($c === '$') return 'USD';
    if (strlen($c) === 3) return $c;
    return 'TND';
  }

  public static function getCurrencyCodeById(int $currencyId): string
  {
    if ($currencyId <= 0) return 'TND';
    if (isset(self::$codeCache[$currencyId])) {
      return self::$codeCache[$currencyId];
    }

    $db  = \Config\Database::connect();
    $row = $db->table('tblcurrencies')
      ->select('name, symbol')
      ->where('id', $currencyId)
      ->get()->getRowArray();

    $code = 'TND';
    if ($row) {
      $name = strtoupper(trim($row['name'] ?? ''));
      $sym  = trim($row['symbol'] ?? '');
      if (str_contains($name, 'EUR') || $sym === '€') {
        $code = 'EUR';
      } elseif (str_contains($name, 'USD') || $sym === '$') {
        $code = 'USD';
      } elseif (str_contains($name, 'TND') || str_contains($name, 'DINAR')) {
        $code = 'TND';
      } else {
        $code = self::normalizeCode($name);
      }
    }

    self::$codeCache[$currencyId] = $code;
    return $code;
  }

  public static function getInvoiceCurrencyCode(int $invoiceId): string
  {
    $db = \Config\Database::connect();
    $row = $db->table('tblinvoices i')
      ->select('i.currency')
      ->where('i.id', $invoiceId)
      ->get()->getRowArray();

    return self::getCurrencyCodeById((int) ($row['currency'] ?? 0));
  }

  public static function toStripeIso(string $code): string
  {
    $code = self::normalizeCode($code);
    return match ($code) {
      'EUR' => 'eur',
      'USD' => 'usd',
      default => 'usd',
    };
  }

  /** Paymee n'accepte que le TND */
  public static function isPaymeeCurrencyAllowed(string $invoiceCode): bool
  {
    return true;
  }

  public static function convert(float $amount, string $fromCode, string $toCode): float
  {
    $from = self::normalizeCode($fromCode);
    $to   = self::normalizeCode($toCode);
    if ($from === $to) return round($amount, 2);

    $rates = self::ratesToTnd();
    $fromRate = $rates[$from] ?? 1.0;
    $toRate   = $rates[$to]   ?? 1.0;
    if ($fromRate <= 0 || $toRate <= 0) return round($amount, 2);

    $inTnd = $amount * $fromRate;
    return round($inTnd / $toRate, 2);
  }

  /**
   * Montant à enregistrer dans tblinvoicepaymentrecords.amount
   * (toujours exprimé dans la devise de la facture).
   */
  public static function toInvoiceCurrency(
    float $amount,
    string $paymentCurrency,
    int $invoiceId
  ): float {
    $invoiceCode = self::getInvoiceCurrencyCode($invoiceId);
    return self::convert($amount, $paymentCurrency, $invoiceCode);
  }

  public static function formatNote(
    string $gateway,
    float $originalAmount,
    string $originalCurrency,
    float $invoiceAmount,
    string $invoiceCurrency
  ): string {
    $orig = number_format($originalAmount, 2, '.', '');
    $inv  = number_format($invoiceAmount, 2, '.', '');
    if ($originalCurrency === $invoiceCurrency) {
      return "Paiement en ligne via {$gateway}";
    }
    return "Paiement via {$gateway} : {$orig} {$originalCurrency} "
      . "(équiv. {$inv} {$invoiceCurrency})";
  }

  public static function detectPaymentCurrency(array $payment): string
  {
    $method = strtolower($payment['paymentmethod'] ?? $payment['payment_mode'] ?? '');
    $mode   = strtolower($payment['paymentmode']   ?? '');
    $note   = $payment['note'] ?? '';

    if (str_contains($method, 'paymee') || str_contains($mode, 'paymee')) {
      return 'TND';
    }
    if (preg_match('/\[CUR:([A-Z]{3}):/', $note, $m)) {
      return $m[1];
    }
    if (str_contains($method, 'stripe') || str_contains($mode, 'stripe')) {
      if (preg_match('/\b(EUR|USD|TND)\b/i', $note, $m)) {
        return strtoupper($m[1]);
      }
    }
    return 'INVOICE';
  }

  public static function getTotalPaidInInvoiceCurrency(int $invoiceId): float
  {
    $db = \Config\Database::connect();
    $invoiceCode = self::getInvoiceCurrencyCode($invoiceId);

    $payments = $db->table('tblinvoicepaymentrecords')
      ->select('amount, paymentmethod, paymentmode, note')
      ->where('invoiceid', $invoiceId)
      ->get()->getResultArray();

    $total = 0.0;
    foreach ($payments as $p) {
      $amt  = (float) ($p['amount'] ?? 0);
      $note = $p['note'] ?? '';
      if (preg_match('/\[CUR:[A-Z]{3}:/', $note)) {
        $total += $amt;
        continue;
      }
      $payCur = self::detectPaymentCurrency($p);
      if ($payCur === 'INVOICE' || $payCur === $invoiceCode) {
        $total += $amt;
      } else {
        $total += self::convert($amt, $payCur, $invoiceCode);
      }
    }
    return round($total, 2);
  }
}