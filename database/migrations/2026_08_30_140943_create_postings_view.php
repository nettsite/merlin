<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * The single derivation site for double-entry postings, replacing the
     * journal_entries/journal_lines tables. Every branch below reproduces
     * exactly what the deleted post*Journal() methods used to write, read
     * from documents/document_lines directly instead of from a stored
     * entry. Columns are named to match what journal_lines exposed
     * (entry_date, account_id, party_id, debit, credit, description,
     * document_id, source) so every report that queried the table needs
     * only the table name swapped, not its query rewritten.
     *
     * The "all lines coded" NOT EXISTS guard on the invoice/credit-note
     * branches reproduces the deleted journal methods' all-or-nothing rule
     * (an invoice with one uncoded line posted nothing at all, not a partial
     * entry) — without it, the AR/AP leg (which doesn't depend on line
     * coding) could appear while an income/expense leg silently didn't,
     * leaving an apparently real but actually unbalanced set of rows.
     *
     * Every row for one document_id is one accounting event — mirroring
     * the old journal_entries row each document ever got exactly one of
     * (an invoice posts once, a credit note applies once, a payment is
     * created once). accounts/show's "contra account" derivation groups on
     * document_id for exactly this reason.
     *
     * Portable UNION ALL only — this runs unchanged on MariaDB (prod) and
     * SQLite (the test suite). No `||` (MariaDB treats it as logical OR
     * unless PIPES_AS_CONCAT is set) — description strings are static
     * literals or a line's own description, never built by concatenation.
     */
    public function up(): void
    {
        $allLinesCoded = 'NOT EXISTS (
            SELECT 1 FROM document_lines dl2
            WHERE dl2.document_id = d.id AND dl2.deleted_at IS NULL AND dl2.account_id IS NULL
        )';

        $sql = "CREATE VIEW postings AS

            -- Sales invoice: AR debit for the full total.
            SELECT d.id AS document_id, d.issue_date AS entry_date, d.receivable_account_id AS account_id,
                   d.party_id, d.total AS debit, 0 AS credit, 'Invoice total' AS description, 'sales_invoice_ar' AS source
              FROM documents d
             WHERE d.document_type = 'sales_invoice' AND d.status <> 'draft' AND d.deleted_at IS NULL
               AND d.receivable_account_id IS NOT NULL AND {$allLinesCoded}

            UNION ALL

            -- Sales invoice: income credit, per line.
            SELECT d.id, d.issue_date, dl.account_id, d.party_id, 0, dl.line_total, dl.description, 'sales_invoice_income'
              FROM documents d
              JOIN document_lines dl ON dl.document_id = d.id AND dl.deleted_at IS NULL
             WHERE d.document_type = 'sales_invoice' AND d.status <> 'draft' AND d.deleted_at IS NULL
               AND dl.account_id IS NOT NULL AND {$allLinesCoded}

            UNION ALL

            -- Sales invoice: VAT credit, where the invoice carries tax.
            SELECT d.id, d.issue_date, d.tax_account_id, d.party_id, 0, d.tax_total, 'VAT', 'sales_invoice_vat'
              FROM documents d
             WHERE d.document_type = 'sales_invoice' AND d.status <> 'draft' AND d.deleted_at IS NULL
               AND d.tax_total > 0 AND d.tax_account_id IS NOT NULL AND {$allLinesCoded}

            UNION ALL

            -- Purchase invoice: expense debit, per line.
            SELECT d.id, d.issue_date, dl.account_id, d.party_id, dl.line_total, 0, dl.description, 'purchase_invoice_expense'
              FROM documents d
              JOIN document_lines dl ON dl.document_id = d.id AND dl.deleted_at IS NULL
             WHERE d.document_type = 'purchase_invoice' AND d.status IN ('posted', 'partially_paid', 'paid') AND d.deleted_at IS NULL
               AND dl.account_id IS NOT NULL AND {$allLinesCoded}

            UNION ALL

            -- Purchase invoice: AP credit for the full total.
            SELECT d.id, d.issue_date, d.payable_account_id, d.party_id, 0, d.total, 'Invoice total', 'purchase_invoice_ap'
              FROM documents d
             WHERE d.document_type = 'purchase_invoice' AND d.status IN ('posted', 'partially_paid', 'paid') AND d.deleted_at IS NULL
               AND d.payable_account_id IS NOT NULL AND {$allLinesCoded}

            UNION ALL

            -- Credit note: line debit, reversing the income it credited.
            SELECT d.id, d.issue_date, dl.account_id, d.party_id, dl.line_total, 0, dl.description, 'credit_note_line'
              FROM documents d
              JOIN document_lines dl ON dl.document_id = d.id AND dl.deleted_at IS NULL
             WHERE d.document_type = 'credit_note' AND d.status IN ('issued', 'applied') AND d.deleted_at IS NULL
               AND dl.account_id IS NOT NULL AND {$allLinesCoded}

            UNION ALL

            -- Credit note: AR credit for the full total.
            SELECT d.id, d.issue_date, d.receivable_account_id, d.party_id, 0, d.total, 'Credit note applied', 'credit_note_ar'
              FROM documents d
             WHERE d.document_type = 'credit_note' AND d.status IN ('issued', 'applied') AND d.deleted_at IS NULL
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
