-- ============================================================
-- Table : tblstripe_payments
-- Traçage des PaymentIntents Stripe liés aux factures
-- À exécuter dans phpMyAdmin ou MySQL CLI
-- ============================================================

CREATE TABLE IF NOT EXISTS `tblstripe_payments` (
    `id`                INT          UNSIGNED NOT NULL AUTO_INCREMENT,
    `invoice_id`        INT          UNSIGNED NOT NULL,
    `client_id`         INT          UNSIGNED NOT NULL DEFAULT 0,
    `payment_intent_id` VARCHAR(255) NOT NULL COMMENT 'pi_xxx de Stripe',
    `amount`            DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    `currency`          VARCHAR(10)  NOT NULL DEFAULT 'eur',
    `stripe_status`     VARCHAR(50)  NOT NULL DEFAULT 'requires_payment_method'
                        COMMENT 'requires_payment_method | processing | succeeded | canceled',
    `payment_id`        INT          UNSIGNED NULL DEFAULT NULL
                        COMMENT 'FK vers tblpayments après confirmation',
    `created_at`        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `confirmed_at`      DATETIME     NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_payment_intent` (`payment_intent_id`),
    KEY `idx_invoice_id` (`invoice_id`),
    KEY `idx_stripe_status` (`stripe_status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
