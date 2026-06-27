/* ==========================================================================
   Merlin user guide — navigation + chrome
   ========================================================================== */

const REPO_URL = 'https://github.com/nettsite/merlin';

const NAV = [
    {
        group: 'Getting Started',
        pages: [
            { file: 'index.html', title: 'Welcome to Merlin' },
        ],
    },
    {
        group: 'Expenses',
        pages: [
            { file: 'suppliers.html', title: 'Suppliers' },
            { file: 'purchase-invoices.html', title: 'Purchase Invoices' },
            { file: 'posting-rules.html', title: 'Automatic Posting' },
            { file: 'bank-statements.html', title: 'Bank Statements' },
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
            { file: 'accounts.html', title: 'Accounts' },
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
            { file: 'users.html', title: 'Users & Access' },
            { file: 'settings.html', title: 'Settings' },
        ],
    },
];

/* ---------- helpers ----------------------------------------------------- */

function currentFile() {
    const path = window.location.pathname.split('/').pop();
    return path === '' ? 'index.html' : path;
}

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
            <img src="../logo.svg" alt="" onerror="this.style.display='none'">
            <span>Merlin User Guide</span>
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

applyStoredTheme();
document.addEventListener('DOMContentLoaded', () => {
    buildHeader();
    buildSidebar();
    buildToc();
    buildPageNav();
    buildFooter();
});
