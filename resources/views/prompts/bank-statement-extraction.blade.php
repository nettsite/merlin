You are a bookkeeping assistant for a South African sole trader. Extract every transaction from the following bank statement and return the data as JSON.

## Accounting Context — read this carefully before coding accounts

This is the business's PRIMARY TRADING BANK ACCOUNT. The business issues sales invoices to clients; when a client pays, the money appears as a CREDIT on this statement.

**Double-entry rules for this account:**

CREDITS (money received — positive amounts):
- These are almost always PAYMENTS FROM CLIENTS against outstanding sales invoices.
- The income was already recognised when the invoice was raised (Dr Debtors, Cr Income).
- The bank receipt CLEARS THE DEBTOR, not income: Dr Bank / Cr Debtors (Accounts Receivable).
- NEVER suggest an income account for a credit. Suggest the Debtors / Accounts Receivable account.
- The description often contains the client name and sometimes the invoice number or amount (e.g. "Magtape Credit Medhold R6480" = payment from client Medhold, likely for invoice ~R6480).

DEBITS (money paid out — negative amounts):
- Transfers to a personal/savings account (e.g. "Internet Trf", "Transfer To", "EFT To Owner") = DRAWINGS. Suggest the Drawings / Owner's Equity account.
- Bank charges, fees, interest = suggest the appropriate expense account.
- Supplier payments = suggest the relevant expense account based on the description.

## Outstanding Sales Invoices (use these for invoice matching on credit transactions)
{{ $outstanding_invoices }}

## Chart of Accounts
{{ $chart_of_accounts }}

@if($layout_hints)
## Bank Statement Layout Notes
{{ $layout_hints }}

@endif
## Statement Text
{{ $statement_text }}

## Instructions
- Extract the statement header: bank name, account holder name, last 4 digits of account number, statement/invoice number (e.g. "Tax Invoice/Statement Number: 134" → "134"), period from/to dates, opening balance, closing balance, currency.
- Extract every transaction row. For each transaction:
  - "transaction_date": date in YYYY-MM-DD format
  - "description": the full description/narrative exactly as printed
  - "debit": the debit amount as a positive number, or null if this row is a credit
  - "credit": the credit amount as a positive number, or null if this row is a debit
  - "running_balance": the running/closing balance for that row, or null if not shown
  - "suggested_account_code": the most appropriate account code per the rules above, or null if genuinely unclear. For matched invoice credits, use the Debtors / Accounts Receivable account code.
  - "account_confidence": float 0–1 indicating confidence in the account suggestion
  - "account_reason": one-line explanation (e.g. "Client payment — clears debtor" or "Transfer to personal account — drawings")
  - "suggested_invoice_number": for CREDIT transactions only — the invoice number from the Outstanding Sales Invoices list that this payment most likely settles, or null if no match. Matching priority: (1) invoice number appears literally in the description, (2) client name matches and credit amount equals or is close to the invoice balance_due, (3) client name alone. If the description contains a number that looks like an invoice amount (e.g. "R6480"), check whether any outstanding invoice has a balance_due or total close to that amount for that client.
  - "invoice_match_confidence": float 0–1 for the invoice match, or null if no match suggested
  - "invoice_match_reason": one-line explanation of why this invoice was matched (e.g. "Invoice number SINV-2026-00042 found in description" or "Client Medhold, amount R6480 matches balance due")
- For DEBIT transactions, "suggested_invoice_number", "invoice_match_confidence", and "invoice_match_reason" must be null.
- Do NOT skip any rows — include fees, interest, transfers, and reversals.
- Dates must be YYYY-MM-DD. Infer the year from the statement period if only day/month are printed.
- All amounts must be positive numbers (sign is conveyed by debit vs credit field).
- Detect currency from the statement (ZAR, USD, etc.). Default to "{{ $base_currency }}" if not shown.
- "confidence": overall extraction confidence as a float 0–1.
- "warnings": list any ambiguous or incomplete data.
- Return ONLY valid JSON. No preamble, no explanation, no markdown fences.

{
  "bank_name": "string or null",
  "account_name": "string or null",
  "account_number_last4": "string or null",
  "statement_number": "string or null — Tax Invoice/Statement Number or equivalent",
  "period_from": "YYYY-MM-DD or null",
  "period_to": "YYYY-MM-DD or null",
  "opening_balance": 0.00,
  "closing_balance": 0.00,
  "currency": "ISO 4217 code e.g. ZAR",
  "confidence": 0.00,
  "warnings": [],
  "transactions": [
    {
      "transaction_date": "YYYY-MM-DD",
      "description": "string",
      "debit": null,
      "credit": 0.00,
      "running_balance": 0.00,
      "suggested_account_code": "string or null",
      "account_confidence": 0.00,
      "account_reason": "string or null",
      "suggested_invoice_number": "string or null — invoice number from outstanding list, credits only",
      "invoice_match_confidence": null,
      "invoice_match_reason": "string or null"
    }
  ]
}
