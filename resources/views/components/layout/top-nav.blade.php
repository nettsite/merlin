<flux:navbar class="max-lg:hidden">
    <flux:navbar.item href="{{ route('dashboard') }}" :current="request()->routeIs('dashboard')" wire:navigate>Dashboard</flux:navbar.item>

    <flux:dropdown>
        <flux:navbar.item
            icon:trailing="chevron-down"
            :current="request()->is('suppliers*', 'purchase-invoices*', 'payment-notifications*', 'posting-rules*')"
        >Expenses</flux:navbar.item>
        <flux:navmenu>
            <flux:navmenu.item href="{{ route('suppliers.index') }}" wire:navigate>Suppliers</flux:navmenu.item>
            <flux:navmenu.item href="{{ route('purchase-invoices.index') }}" wire:navigate>Purchase Invoices</flux:navmenu.item>
            <flux:navmenu.item href="{{ route('payment-notifications.index') }}" wire:navigate>Unmatched Payments</flux:navmenu.item>
            <flux:navmenu.item href="{{ route('posting-rules.index') }}" wire:navigate>Posting Rules</flux:navmenu.item>
        </flux:navmenu>
    </flux:dropdown>

    <flux:dropdown>
        <flux:navbar.item
            icon:trailing="chevron-down"
            :current="request()->is('clients*', 'contacts*', 'payment-terms*', 'sales-invoices*', 'quotes*', 'credit-notes*', 'recurring-invoices*')"
        >Billing</flux:navbar.item>
        <flux:navmenu>
            <flux:navmenu.item href="{{ route('clients.index') }}" wire:navigate>Clients</flux:navmenu.item>
            <flux:navmenu.item href="{{ route('contacts.index') }}" wire:navigate>Contacts</flux:navmenu.item>
            <flux:navmenu.item href="{{ route('sales-invoices.index') }}" wire:navigate>Sales Invoices</flux:navmenu.item>
            <flux:navmenu.item href="{{ route('quotes.index') }}" wire:navigate>Quotes</flux:navmenu.item>
            <flux:navmenu.item href="{{ route('credit-notes.index') }}" wire:navigate>Credit Notes</flux:navmenu.item>
            <flux:navmenu.item href="{{ route('recurring-invoices.index') }}" wire:navigate>Recurring Invoices</flux:navmenu.item>
            <flux:navmenu.item href="{{ route('payment-terms.index') }}" wire:navigate>Payment Terms</flux:navmenu.item>
        </flux:navmenu>
    </flux:dropdown>

    <flux:dropdown>
        <flux:navbar.item
            icon:trailing="chevron-down"
            :current="request()->is('accounts*', 'account-groups*', 'reports*', 'bank-statements*', 'bank-templates*')"
        >Accounting</flux:navbar.item>
        <flux:navmenu>
            <flux:navmenu.item href="{{ route('bank-statements.index') }}" wire:navigate>Bank Statements</flux:navmenu.item>
            <flux:navmenu.item href="{{ route('bank-templates.index') }}" wire:navigate>Bank Templates</flux:navmenu.item>
            <flux:navmenu.item href="{{ route('accounts.index') }}" wire:navigate>Accounts</flux:navmenu.item>
            <flux:navmenu.item href="{{ route('account-groups.index') }}" wire:navigate>Account Groups</flux:navmenu.item>
            <flux:navmenu.item href="{{ route('reports.income-statement') }}" wire:navigate>Reports</flux:navmenu.item>
        </flux:navmenu>
    </flux:dropdown>

    <flux:dropdown>
        <flux:navbar.item
            icon:trailing="chevron-down"
            :current="request()->is('settings*', 'roles*', 'users*', 'llm-logs*')"
        >Administration</flux:navbar.item>
        <flux:navmenu>
            <flux:navmenu.item href="{{ route('settings.index') }}" wire:navigate>Settings</flux:navmenu.item>
            <flux:navmenu.item href="{{ route('users.index') }}" wire:navigate>Users</flux:navmenu.item>
            <flux:navmenu.item href="{{ route('llm-logs.index') }}" wire:navigate>LLM Logs</flux:navmenu.item>
        </flux:navmenu>
    </flux:dropdown>

    <flux:dropdown>
        <flux:navbar.item
            icon:trailing="chevron-down"
            :current="request()->routeIs('help')"
        >Help</flux:navbar.item>
        <flux:navmenu>
            <flux:navmenu.item href="{{ route('help') }}" wire:navigate>Help Chat</flux:navmenu.item>
            <flux:navmenu.item href="/docs/user-guide/" target="_blank">User Guide</flux:navmenu.item>
            <flux:navmenu.item href="/docs/system-guide/" target="_blank">System Guide</flux:navmenu.item>
        </flux:navmenu>
    </flux:dropdown>

    <flux:dropdown>
        <flux:navbar.item
            icon:trailing="chevron-down"
            :current="request()->is('nettmail*')"
        >Emails</flux:navbar.item>
        <flux:navmenu>
            <flux:navmenu.item href="{{ route('nettmail.dashboard') }}">Dashboard</flux:navmenu.item>
            <flux:navmenu.item href="{{ route('nettmail.templates.index') }}">Templates</flux:navmenu.item>
            <flux:navmenu.item href="{{ route('nettmail.contacts.index') }}">Contacts</flux:navmenu.item>
            <flux:navmenu.item href="{{ route('nettmail.lists.index') }}">Lists</flux:navmenu.item>
            <flux:navmenu.item href="{{ route('nettmail.segments.index') }}">Segments</flux:navmenu.item>
            <flux:navmenu.item href="{{ route('nettmail.campaigns.index') }}">Campaigns</flux:navmenu.item>
            <flux:navmenu.item href="{{ route('nettmail.settings') }}">Settings</flux:navmenu.item>
        </flux:navmenu>
    </flux:dropdown>
</flux:navbar>
