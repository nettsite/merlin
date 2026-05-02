<flux:navbar class="max-lg:hidden">
    <flux:navbar.item href="{{ route('dashboard') }}" :current="request()->routeIs('dashboard')" wire:navigate>Dashboard</flux:navbar.item>

    <flux:dropdown>
        <flux:navbar.item
            icon:trailing="chevron-down"
            :current="request()->is('suppliers*', 'purchase-invoices*', 'posting-rules*')"
        >Expenses</flux:navbar.item>
        <flux:navmenu>
            <flux:navmenu.item href="{{ route('suppliers.index') }}" wire:navigate>Suppliers</flux:navmenu.item>
            <flux:navmenu.item href="{{ route('purchase-invoices.index') }}" wire:navigate>Purchase Invoices</flux:navmenu.item>
            <flux:navmenu.item href="{{ route('posting-rules.index') }}" wire:navigate>Posting Rules</flux:navmenu.item>
        </flux:navmenu>
    </flux:dropdown>

    <flux:dropdown>
        <flux:navbar.item
            icon:trailing="chevron-down"
            :current="request()->is('clients*', 'payment-terms*', 'sales-invoices*', 'recurring-invoices*')"
        >Billing</flux:navbar.item>
        <flux:navmenu>
            <flux:navmenu.item href="{{ route('clients.index') }}" wire:navigate>Clients</flux:navmenu.item>
            <flux:navmenu.item href="{{ route('sales-invoices.index') }}" wire:navigate>Sales Invoices</flux:navmenu.item>
            <flux:navmenu.item href="{{ route('recurring-invoices.index') }}" wire:navigate>Recurring Invoices</flux:navmenu.item>
            <flux:navmenu.item href="{{ route('payment-terms.index') }}" wire:navigate>Payment Terms</flux:navmenu.item>
        </flux:navmenu>
    </flux:dropdown>

    <flux:dropdown>
        <flux:navbar.item
            icon:trailing="chevron-down"
            :current="request()->is('accounts*', 'account-groups*', 'reports*')"
        >Accounting</flux:navbar.item>
        <flux:navmenu>
            <flux:navmenu.item href="{{ route('accounts.index') }}" wire:navigate>Accounts</flux:navmenu.item>
            <flux:navmenu.item href="{{ route('account-groups.index') }}" wire:navigate>Account Groups</flux:navmenu.item>
            <flux:navmenu.item href="{{ route('reports.expenses-by-account') }}" wire:navigate>Reports</flux:navmenu.item>
        </flux:navmenu>
    </flux:dropdown>

    <flux:dropdown>
        <flux:navbar.item
            icon:trailing="chevron-down"
            :current="request()->is('settings/*', 'roles*', 'users*', 'llm-logs*')"
        >Settings</flux:navbar.item>
        <flux:navmenu>
            <flux:navmenu.item href="{{ route('settings.general') }}" wire:navigate>General Settings</flux:navmenu.item>
            <flux:navmenu.item href="{{ route('settings.purchasing') }}" wire:navigate>Purchasing Settings</flux:navmenu.item>
            <flux:navmenu.item href="{{ route('settings.billing') }}" wire:navigate>Billing Settings</flux:navmenu.item>
            <flux:navmenu.item href="{{ route('roles.index') }}" wire:navigate>Roles</flux:navmenu.item>
            <flux:navmenu.item href="{{ route('users.index') }}" wire:navigate>Users</flux:navmenu.item>
            <flux:navmenu.item href="{{ route('llm-logs.index') }}" wire:navigate>LLM Logs</flux:navmenu.item>
        </flux:navmenu>
    </flux:dropdown>
</flux:navbar>
