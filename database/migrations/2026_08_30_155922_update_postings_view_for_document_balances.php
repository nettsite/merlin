<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * status moved off `documents` onto `document_balances` (see
     * create_document_balances_table) — every branch here that filtered on
     * `d.status` now joins db and filters on `db.status` instead. Nothing
     * else about the view changes; re-run create_postings_view's own
     * comment for the rest of the reasoning.
     */
    public function up(): void
    {
        DB::statement('DROP VIEW IF EXISTS postings');

        $allLinesCoded = 'NOT EXISTS (
            SELECT 1 FROM document_lines dl2
            WHERE dl2.document_id = d.id AND dl2.deleted_at IS NULL AND dl2.account_id IS NULL
        )';

        $sql = "CREATE VIEW postings AS

            -- Sales invoice: AR debit for the full total.
            SELECT d.id AS document_id, d.issue_date AS entry_date, d.receivable_account_id AS account_id,
                   d.party_id, d.total AS debit, 0 AS credit, 'Invoice total' AS description, 'sales_invoice_ar' AS source
              FROM documents d
              JOIN document_balances db ON db.document_id = d.id
             WHERE d.document_type = 'sales_invoice' AND db.status <> 'draft' AND d.deleted_at IS NULL
               AND d.receivable_account_id IS NOT NULL AND {$allLinesCoded}

            UNION ALL

            -- Sales invoice: income credit, per line.
            SELECT d.id, d.issue_date, dl.account_id, d.party_id, 0, dl.line_total, dl.description, 'sales_invoice_income'
              FROM documents d
              JOIN document_balances db ON db.document_id = d.id
              JOIN document_lines dl ON dl.document_id = d.id AND dl.deleted_at IS NULL
             WHERE d.document_type = 'sales_invoice' AND db.status <> 'draft' AND d.deleted_at IS NULL
               AND dl.account_id IS NOT NULL AND {$allLinesCoded}

            UNION ALL

            -- Sales invoice: VAT credit, where the invoice carries tax.
            SELECT d.id, d.issue_date, d.tax_account_id, d.party_id, 0, d.tax_total, 'VAT', 'sales_invoice_vat'
              FROM documents d
              JOIN document_balances db ON db.document_id = d.id
             WHERE d.document_type = 'sales_invoice' AND db.status <> 'draft' AND d.deleted_at IS NULL
               AND d.tax_total > 0 AND d.tax_account_id IS NOT NULL AND {$allLinesCoded}

            UNION ALL

            -- Purchase invoice: expense debit, per line.
            SELECT d.id, d.issue_date, dl.account_id, d.party_id, dl.line_total, 0, dl.description, 'purchase_invoice_expense'
              FROM documents d
              JOIN document_balances db ON db.document_id = d.id
              JOIN document_lines dl ON dl.document_id = d.id AND dl.deleted_at IS NULL
             WHERE d.document_type = 'purchase_invoice' AND db.status IN ('posted', 'partially_paid', 'paid') AND d.deleted_at IS NULL
               AND dl.account_id IS NOT NULL AND {$allLinesCoded}

            UNION ALL

            -- Purchase invoice: AP credit for the full total.
            SELECT d.id, d.issue_date, d.payable_account_id, d.party_id, 0, d.total, 'Invoice total', 'purchase_invoice_ap'
              FROM documents d
              JOIN document_balances db ON db.document_id = d.id
             WHERE d.document_type = 'purchase_invoice' AND db.status IN ('posted', 'partially_paid', 'paid') AND d.deleted_at IS NULL
               AND d.payable_account_id IS NOT NULL AND {$allLinesCoded}

            UNION ALL

            -- Credit note: line debit, reversing the income it credited.
            SELECT d.id, d.issue_date, dl.account_id, d.party_id, dl.line_total, 0, dl.description, 'credit_note_line'
              FROM documents d
              JOIN document_balances db ON db.document_id = d.id
              JOIN document_lines dl ON dl.document_id = d.id AND dl.deleted_at IS NULL
             WHERE d.document_type = 'credit_note' AND db.status IN ('issued', 'applied') AND d.deleted_at IS NULL
               AND dl.account_id IS NOT NULL AND {$allLinesCoded}

            UNION ALL

            -- Credit note: AR credit for the full total.
            SELECT d.id, d.issue_date, d.receivable_account_id, d.party_id, 0, d.total, 'Credit note applied', 'credit_note_ar'
              FROM documents d
              JOIN document_balances db ON db.document_id = d.id
             WHERE d.document_type = 'credit_note' AND db.status IN ('issued', 'applied') AND d.deleted_at IS NULL
               AND d.receivable_account_id IS NOT NULL AND {$allLinesCoded}

            UNION ALL

            -- Inbound payment: contra (bank) debit.
            SELECT d.id, d.issue_date, d.contra_account_id, d.party_id, d.total, 0, 'Payment received', 'payment_in_contra'
              FROM documents d
             WHERE d.document_type = 'payment' AND d.direction = 'inbound' AND d.deleted_at IS NULL
               AND d.total > 0 AND d.contra_account_id IS NOT NULL

            UNION ALL

            -- Inbound payment: receivable credit (clearing AR, or a GL account
            -- for a reconciliation entry with no invoice behind it).
            SELECT d.id, d.issue_date, d.receivable_account_id, d.party_id, 0, d.total, 'Payment received', 'payment_in_receivable'
              FROM documents d
             WHERE d.document_type = 'payment' AND d.direction = 'inbound' AND d.deleted_at IS NULL
               AND d.total > 0 AND d.receivable_account_id IS NOT NULL

            UNION ALL

            -- Outbound payment: payable debit (clearing AP, or a GL account).
            SELECT d.id, d.issue_date, d.payable_account_id, d.party_id, d.total, 0, 'Payment made', 'payment_out_payable'
              FROM documents d
             WHERE d.document_type = 'payment' AND d.direction = 'outbound' AND d.deleted_at IS NULL
               AND d.total > 0 AND d.payable_account_id IS NOT NULL

            UNION ALL

            -- Outbound payment: contra (bank) credit.
            SELECT d.id, d.issue_date, d.contra_account_id, d.party_id, 0, d.total, 'Payment made', 'payment_out_contra'
              FROM documents d
             WHERE d.document_type = 'payment' AND d.direction = 'outbound' AND d.deleted_at IS NULL
               AND d.total > 0 AND d.contra_account_id IS NOT NULL
        ";

        DB::statement($sql);
    }
};
