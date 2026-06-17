<?php

/**
 * Merlin docs scaffolder.
 *
 * Stamps out one HTML file per page from a shared shell, with the correct
 * heading structure (and slugified ids) already in place. Run from the docs/
 * directory:
 *
 *     php scaffold.php
 *
 * Idempotent and NON-DESTRUCTIVE: it skips any page file that already exists,
 * so prose written by the content pass is never overwritten. Delete a page
 * file and re-run to regenerate just that one.
 *
 * The page list and headings here MUST stay in sync with:
 *   - assets/nav.js   (the NAV tree — sidebar order + prev/next)
 *   - content-outline.md  (the prose brief for each heading)
 */

/** Mirror of slugify() in assets/nav.js so server-stamped ids match. */
function slugify(string $text): string
{
    $text = strtolower(trim($text));
    $text = preg_replace('/[^\w\s-]/', '', $text);
    $text = preg_replace('/\s+/', '-', $text);

    return preg_replace('/-+/', '-', $text);
}

/**
 * Each page: file => [title, headings].
 * A heading is [level (2|3), text]. The <h1> is the title.
 */
$pages = [
    // ---- Getting Started ----
    'index.html' => ['Introduction', [
        [2, 'What is Merlin'],
        [2, 'Key features'],
        [2, 'How invoice automation works'],
        [2, 'Technology stack'],
        [2, 'Who this documentation is for'],
    ]],
    'installation.html' => ['Installation', [
        [2, 'Requirements'],
        [2, 'Quick install'],
        [2, 'Manual installation'],
        [3, 'Clone and install dependencies'],
        [3, 'Environment file'],
        [3, 'Database'],
        [3, 'Build front-end assets'],
        [2, 'Creating your first user'],
        [2, 'Running the development server'],
    ]],
    'configuration.html' => ['Configuration', [
        [2, 'Environment variables'],
        [3, 'Database'],
        [3, 'Anthropic (Claude) API'],
        [3, 'Exchange rates'],
        [3, 'Invoice watch folder'],
        [3, 'Queue'],
        [2, 'Configuration files'],
        [2, 'Model health and fallback'],
    ]],
    'seeding.html' => ['Seeding Reference Data', [
        [2, 'Why seed'],
        [2, 'Available seeders'],
        [3, 'Roles and permissions'],
        [3, 'Chart of accounts'],
        [3, 'Default admin user'],
        [3, 'Debtor account group'],
        [3, 'Payment terms'],
        [2, 'Running all seeders'],
        [2, 'Re-running seeders safely'],
    ]],

    // ---- Core Concepts ----
    'architecture.html' => ['Architecture', [
        [2, 'Module structure'],
        [2, 'Domains at a glance'],
        [2, 'UUID primary keys'],
        [2, 'The morph map'],
        [2, 'Volt pages and the CRUD framework'],
        [2, 'Services and the pipeline'],
    ]],
    'parties.html' => ['Parties & Contacts', [
        [2, 'The Party model'],
        [2, 'Persons versus Businesses'],
        [2, 'Party relationships'],
        [3, 'Relationship metadata'],
        [2, 'Contact assignments'],
        [2, 'Addresses'],
    ]],
    'roles-permissions.html' => ['Roles & Permissions', [
        [2, 'How authorization works'],
        [2, 'The Administrator role'],
        [2, 'Permission naming'],
        [2, 'Model permissions'],
        [2, 'Workflow permissions'],
        [2, 'Managing roles in the UI'],
        [2, 'Assigning permissions to users'],
    ]],
    'settings.html' => ['Settings', [
        [2, 'How settings are stored'],
        [2, 'Settings groups'],
        [2, 'Editing settings'],
        [2, 'Settings reference'],
    ]],

    // ---- Expenses ----
    'suppliers.html' => ['Suppliers', [
        [2, 'What is a supplier'],
        [2, 'Creating a supplier'],
        [2, 'Supplier fields'],
        [2, 'Default payable account and payment term'],
        [2, 'Pending suppliers from the pipeline'],
        [2, 'The supplier detail page'],
        [2, 'Permissions'],
    ]],
    'purchase-invoices.html' => ['Purchase Invoices', [
        [2, 'Overview'],
        [2, 'Uploading invoices'],
        [3, 'Manual upload'],
        [3, 'The watch folder'],
        [2, 'The invoice list'],
        [2, 'Reviewing an invoice'],
        [3, 'Editing line items'],
        [3, 'Allocating GL accounts'],
        [2, 'Status actions'],
        [2, 'Reprocessing'],
        [2, 'Permissions'],
    ]],
    'invoice-pipeline.html' => ['The Invoice Pipeline', [
        [2, 'Pipeline overview'],
        [2, 'Text extraction'],
        [2, 'File-type detection (Magika)'],
        [2, 'LLM extraction'],
        [3, 'Tiered model fallback'],
        [3, 'Reconciliation'],
        [3, 'Model health and retirement'],
        [2, 'Supplier resolution'],
        [2, 'Account resolution'],
        [2, 'Exchange rates'],
        [2, 'Posting rule evaluation'],
        [2, 'VAT handling'],
    ]],
    'document-lifecycle.html' => ['Document Lifecycle', [
        [2, 'Statuses'],
        [2, 'The state machine'],
        [2, 'Transitions'],
        [3, 'Mark as reviewed'],
        [3, 'Approve'],
        [3, 'Post'],
        [3, 'Dispute'],
        [3, 'Reject'],
        [2, 'Document activity log'],
        [2, 'Permissions'],
    ]],
    'posting-rules.html' => ['Posting Rules', [
        [2, 'What posting rules do'],
        [2, 'Conditions'],
        [2, 'Actions'],
        [2, 'Auto-posting and confidence'],
        [2, 'Managing posting rules'],
        [2, 'Permissions'],
    ]],
    'llm-logs.html' => ['LLM Logs', [
        [2, 'What is logged'],
        [2, 'The log list'],
        [2, 'Reading a log entry'],
        [2, 'Permissions'],
    ]],

    // ---- Billing ----
    'clients.html' => ['Clients', [
        [2, 'What is a client'],
        [2, 'Creating a client'],
        [2, 'Client fields'],
        [2, 'Contacts'],
        [2, 'Default receivable account and payment term'],
        [2, 'Permissions'],
    ]],
    'sales-invoices.html' => ['Sales Invoices', [
        [2, 'Overview'],
        [2, 'Creating a sales invoice'],
        [2, 'Invoice fields and line items'],
        [2, 'Tax and totals'],
        [2, 'Sending an invoice'],
        [2, 'Recording payments'],
        [2, 'Voiding an invoice'],
        [2, 'PDF generation'],
        [2, 'Permissions'],
    ]],
    'recurring-invoices.html' => ['Recurring Invoices', [
        [2, 'Overview'],
        [2, 'Creating a recurring invoice'],
        [2, 'Frequency and billing day'],
        [2, 'Auto-send'],
        [2, 'How invoices are generated'],
        [2, 'Permissions'],
    ]],
    'payment-terms.html' => ['Payment Terms', [
        [2, 'What payment terms do'],
        [2, 'Term rules'],
        [2, 'Fields'],
        [2, 'Due-date calculation'],
        [2, 'Managing payment terms'],
        [2, 'Permissions'],
    ]],

    // ---- Accounting ----
    'chart-of-accounts.html' => ['Chart of Accounts', [
        [2, 'Account structure'],
        [2, 'Account fields'],
        [2, 'Direct posting'],
        [2, 'System accounts'],
        [2, 'The seeded chart'],
        [2, 'Permissions'],
    ]],
    'account-groups.html' => ['Account Groups', [
        [2, 'What account groups are'],
        [2, 'Account types'],
        [2, 'Fields'],
        [2, 'Managing account groups'],
        [2, 'Permissions'],
    ]],

    // ---- Reports ----
    'reports.html' => ['Reports', [
        [2, 'Expenses by Account'],
        [2, 'Expenses by Supplier'],
        [2, 'LLM Performance'],
        [2, 'Date ranges and the financial year'],
        [2, 'Permissions'],
    ]],

    // ---- Administration ----
    'users.html' => ['Users', [
        [2, 'Managing users'],
        [2, 'User fields'],
        [2, 'Assigning roles'],
        [2, 'Panel access'],
        [2, 'Permissions'],
    ]],
    'general-settings.html' => ['General Settings', [
        [2, 'Company details'],
        [2, 'Currency and locale'],
        [2, 'Financial year'],
        [2, 'Permissions'],
    ]],
    'purchasing-settings.html' => ['Purchasing Settings', [
        [2, 'Tax settings'],
        [2, 'Default payable account'],
        [2, 'Confidence thresholds'],
        [2, 'Matching tolerances'],
        [2, 'Settings reference'],
    ]],
    'billing-settings.html' => ['Billing Settings', [
        [2, 'Default accounts'],
        [2, 'Billing period day'],
        [2, 'Default payment term'],
        [2, 'Invoice email template'],
        [2, 'Settings reference'],
    ]],
    'email.html' => ['Email (NettMail)', [
        [2, 'The NettMail integration'],
        [2, 'Transactional versus campaign email'],
        [2, 'Invoice email templates'],
        [2, 'Configuring mail delivery'],
        [2, 'The Emails admin section'],
    ]],

    // ---- Troubleshooting ----
    'troubleshooting.html' => ['Troubleshooting', [
        [2, 'Invoices not processing'],
        [2, 'Vite manifest errors'],
        [2, 'Queued jobs never run'],
        [2, 'Model not found (404 from Claude)'],
        [2, 'Permission denied or missing menu items'],
        [2, 'Foreign-currency rates not updating'],
    ]],
    'faq.html' => ['Frequently Asked Questions', [
        [2, 'General'],
        [2, 'Invoice processing'],
        [2, 'Accounting'],
        [2, 'Billing'],
        [2, 'Security and data'],
    ]],
];

function renderPage(string $title, array $headings): string
{
    $body = "                <h1>{$title}</h1>\n";
    $body .= "                <p class=\"stub\">Intro paragraph for {$title}. Replace during the content pass.</p>\n";

    foreach ($headings as [$level, $text]) {
        $id = slugify($text);
        $tag = "h{$level}";
        $body .= "\n                <{$tag} id=\"{$id}\">{$text}</{$tag}>\n";
        $body .= "                <p class=\"stub\">Content for &ldquo;{$text}&rdquo; goes here. See content-outline.md.</p>\n";
    }

    return <<<HTML
<!DOCTYPE html>
<!-- Generated by scaffold.php. Content pass: edit ONLY inside <article>. Do not touch <head>, #docs-shell, or the script tag. -->
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{$title} — Merlin Docs</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/docs.css">
</head>
<body>
    <div id="docs-shell">
        <aside id="docs-sidebar"></aside>

        <main id="docs-content">
            <article>
{$body}            </article>
        </main>

        <nav id="docs-toc"></nav>
    </div>

    <script src="assets/nav.js"></script>
</body>
</html>

HTML;
}

$created = 0;
$skipped = 0;

foreach ($pages as $file => [$title, $headings]) {
    $path = __DIR__.'/'.$file;
    if (file_exists($path)) {
        echo "skip   {$file} (already exists)\n";
        $skipped++;

        continue;
    }
    file_put_contents($path, renderPage($title, $headings));
    echo "create {$file}\n";
    $created++;
}

echo "\nDone. {$created} created, {$skipped} skipped.\n";
