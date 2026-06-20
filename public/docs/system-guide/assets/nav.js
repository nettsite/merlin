/* ==========================================================================
   Merlin documentation — navigation + chrome
   Single source of truth for the sidebar. Builds the header, sidebar, footer,
   prev/next links, and the scroll-spy "On this page" TOC at runtime so each
   page file contains ONLY its content. No build step, no fetch (works on
   file:// and GitHub Pages alike).

   To add a page: add an entry to NAV below and create the matching HTML file
   (or run scaffold.php). Nothing else needs editing.
   ========================================================================== */

const REPO_URL = 'https://github.com/nettsite/merlin';

/**
 * Ordered nav tree. `file` is relative to the docs/ root (pages are flat).
 * Order here defines both sidebar order and prev/next sequence.
 */
const NAV = [
    {
        group: 'Getting Started',
        pages: [
            { file: 'index.html', title: 'Introduction' },
            { file: 'installation.html', title: 'Installation' },
            { file: 'configuration.html', title: 'Configuration' },
            { file: 'seeding.html', title: 'Seeding Reference Data' },
        ],
    },
    {
        group: 'Core Concepts',
        pages: [
            { file: 'architecture.html', title: 'Architecture' },
            { file: 'parties.html', title: 'Parties & Contacts' },
            { file: 'roles-permissions.html', title: 'Roles & Permissions' },
            { file: 'settings.html', title: 'Settings' },
        ],
    },
    {
        group: 'Expenses',
        pages: [
            { file: 'suppliers.html', title: 'Suppliers' },
            { file: 'purchase-invoices.html', title: 'Purchase Invoices' },
            { file: 'invoice-pipeline.html', title: 'The Invoice Pipeline' },
            { file: 'document-lifecycle.html', title: 'Document Lifecycle' },
            { file: 'posting-rules.html', title: 'Posting Rules' },
            { file: 'llm-logs.html', title: 'LLM Logs' },
        ],
    },
    {
        group: 'Billing',
        pages: [
            { file: 'clients.html', title: 'Clients' },
            { file: 'sales-invoices.html', title: 'Sales Invoices' },
            { file: 'recurring-invoices.html', title: 'Recurring Invoices' },
            { file: 'payment-terms.html', title: 'Payment Terms' },
        ],
    },
    {
        group: 'Accounting',
        pages: [
            { file: 'chart-of-accounts.html', title: 'Chart of Accounts' },
            { file: 'account-groups.html', title: 'Account Groups' },
        ],
    },
    {
        group: 'Reports',
        pages: [
            { file: 'reports.html', title: 'Reports' },
        ],
    },
    {
        group: 'Administration',
        pages: [
            { file: 'users.html', title: 'Users' },
            { file: 'general-settings.html', title: 'General Settings' },
            { file: 'purchasing-settings.html', title: 'Purchasing Settings' },
            { file: 'billing-settings.html', title: 'Billing Settings' },
            { file: 'email.html', title: 'Email (NettMail)' },
        ],
    },
    {
        group: 'Troubleshooting',
        pages: [
            { file: 'troubleshooting.html', title: 'Troubleshooting' },
            { file: 'faq.html', title: 'FAQ' },
        ],
    },
];

/* ---------- helpers ----------------------------------------------------- */

function currentFile() {
    const path = window.location.pathname.split('/').pop();
    return path === '' ? 'index.html' : path;
}

/** Flatten NAV into an ordered list for prev/next. */
function flatPages() {
    return NAV.flatMap((g) => g.pages);
}

function slugify(text) {
    return text
        .toLowerCase()
        .trim()
        .replace(/[^\w\s-]/g, '')
        .replace(/\s+/g, '-')
        .replace(/-+/g, '-');
}

/* ---------- header ------------------------------------------------------ */

function buildHeader() {
    const header = document.createElement('header');
    header.id = 'docs-header';
    header.innerHTML = `
        <button class="sidebar-toggle" aria-label="Toggle navigation" title="Menu">☰</button>
        <a class="brand" href="index.html">
            <img src="../public/logo.svg" alt="" onerror="this.style.display='none'">
            <span>Merlin System Guide</span>
        </a>
        <span class="header-spacer"></span>
        <a class="header-link" href="${REPO_URL}" target="_blank" rel="noopener">GitHub</a>
        <button class="theme-toggle" aria-label="Toggle dark mode" title="Toggle theme">◐</button>
    `;
    document.body.prepend(header);

    header.querySelector('.theme-toggle').addEventListener('click', toggleTheme);
    header.querySelector('.sidebar-toggle').addEventListener('click', () => {
        document.getElementById('docs-sidebar')?.classList.toggle('open');
    });
}

/* ---------- sidebar ----------------------------------------------------- */

function buildSidebar() {
    const aside = document.getElementById('docs-sidebar');
    if (!aside) {
        return;
    }
    const here = currentFile();
    aside.innerHTML = NAV.map((g) => `
        <div class="nav-group">
            <p class="nav-group-title">${g.group}</p>
            <ul>
                ${g.pages.map((p) => `
                    <li><a href="${p.file}" class="${p.file === here ? 'active' : ''}">${p.title}</a></li>
                `).join('')}
            </ul>
        </div>
    `).join('');
}

/* ---------- on this page (TOC) + heading anchors ----------------------- */

function buildToc() {
    const toc = document.getElementById('docs-toc');
    const content = document.getElementById('docs-content');
    if (!toc || !content) {
        return;
    }

    const headings = [...content.querySelectorAll('h2, h3')];
    if (headings.length === 0) {
        toc.style.display = 'none';
        return;
    }

    headings.forEach((h) => {
        if (!h.id) {
            h.id = slugify(h.textContent);
        }
        // Self-link anchor revealed on hover
        const a = document.createElement('a');
        a.href = `#${h.id}`;
        a.className = 'heading-anchor';
        a.textContent = '#';
        h.appendChild(a);
    });

    toc.innerHTML = `
        <p class="toc-title">On this page</p>
        <ul>
            ${headings.map((h) => `
                <li class="lvl-${h.tagName === 'H3' ? '3' : '2'}">
                    <a href="#${h.id}">${h.firstChild.textContent.trim()}</a>
                </li>
            `).join('')}
        </ul>
    `;

    setupScrollSpy(headings, toc);
}

function setupScrollSpy(headings, toc) {
    const links = new Map(
        [...toc.querySelectorAll('a')].map((a) => [a.getAttribute('href').slice(1), a])
    );

    const observer = new IntersectionObserver(
        (entries) => {
            entries.forEach((entry) => {
                if (entry.isIntersecting) {
                    links.forEach((l) => l.classList.remove('active'));
                    links.get(entry.target.id)?.classList.add('active');
                }
            });
        },
        { rootMargin: '-10% 0px -75% 0px', threshold: 0 }
    );

    headings.forEach((h) => observer.observe(h));
}

/* ---------- prev / next ------------------------------------------------- */

function buildPageNav() {
    const content = document.getElementById('docs-content');
    if (!content) {
        return;
    }
    const article = content.querySelector('article') || content;
    const pages = flatPages();
    const idx = pages.findIndex((p) => p.file === currentFile());
    if (idx === -1) {
        return;
    }

    const prev = pages[idx - 1];
    const next = pages[idx + 1];
    const nav = document.createElement('nav');
    nav.className = 'page-nav';
    nav.innerHTML = `
        ${prev ? `<a class="prev" href="${prev.file}"><span class="dir">Previous</span>${prev.title}</a>` : '<span></span>'}
        ${next ? `<a class="next" href="${next.file}"><span class="dir">Next</span>${next.title}</a>` : '<span></span>'}
    `;
    article.appendChild(nav);
}

/* ---------- footer ------------------------------------------------------ */

function buildFooter() {
    const footer = document.createElement('footer');
    footer.id = 'docs-footer';
    footer.innerHTML = `
        <span>Merlin — open-source business management for small businesses.</span>
        <span><a href="${REPO_URL}" target="_blank" rel="noopener">github.com/nettsite/merlin</a></span>
    `;
    document.getElementById('docs-shell')?.appendChild(footer);
}

/* ---------- theme ------------------------------------------------------- */

function applyStoredTheme() {
    const stored = localStorage.getItem('merlin-docs-theme');
    const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
    document.documentElement.setAttribute('data-theme', stored || (prefersDark ? 'dark' : 'light'));
}

function toggleTheme() {
    const next = document.documentElement.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
    document.documentElement.setAttribute('data-theme', next);
    localStorage.setItem('merlin-docs-theme', next);
}

/* ---------- boot -------------------------------------------------------- */

applyStoredTheme(); // set before paint to avoid flash
document.addEventListener('DOMContentLoaded', () => {
    buildHeader();
    buildSidebar();
    buildToc();
    buildPageNav();
    buildFooter();
});
