<flux:sidebar.nav class="px-2">
    <flux:sidebar.group heading="Expenses">
        <flux:sidebar.item
            icon="building-storefront"
            href="{{ route('suppliers.index') }}"
            :current="request()->routeIs('suppliers.*')"
            wire:navigate
        >Suppliers</flux:sidebar.item>

        <flux:sidebar.item
            icon="document-text"
            href="{{ route('purchase-invoices.index') }}"
            :current="request()->routeIs('purchase-invoices.*')"
            wire:navigate
        >Purchase Invoices</flux:sidebar.item>

        <flux:sidebar.item
            icon="adjustments-horizontal"
            href="{{ route('posting-rules.index') }}"
            :current="request()->routeIs('posting-rules.*')"
            wire:navigate
        >Posting Rules</flux:sidebar.item>
    </flux:sidebar.group>

    <flux:sidebar.group heading="Billing">
        <flux:sidebar.item
            icon="user-group"
            href="{{ route('clients.index') }}"
            :current="request()->routeIs('clients.*')"
            wire:navigate
        >Clients</flux:sidebar.item>

        <flux:sidebar.item
            icon="calendar-days"
            href="{{ route('payment-terms.index') }}"
            :current="request()->routeIs('payment-terms.*')"
            wire:navigate
        >Payment Terms</flux:sidebar.item>
    </flux:sidebar.group>

    <flux:sidebar.group heading="Accounting">
        <flux:sidebar.item
            icon="banknotes"
            href="{{ route('accounts.index') }}"
            :current="request()->routeIs('accounts.*')"
            wire:navigate
        >Accounts</flux:sidebar.item>

        <flux:sidebar.item
            icon="folder"
            href="{{ route('account-groups.index') }}"
            :current="request()->routeIs('account-groups.*')"
            wire:navigate
        >Account Groups</flux:sidebar.item>

        <flux:sidebar.item
            icon="chart-bar"
            href="{{ route('reports.expenses-by-account') }}"
            :current="request()->routeIs('reports.*')"
            wire:navigate
        >Reports</flux:sidebar.item>
    </flux:sidebar.group>

    <flux:sidebar.group heading="Settings">
        <flux:sidebar.item
            icon="cog-6-tooth"
            href="{{ route('settings.general') }}"
            :current="request()->routeIs('settings.general')"
            wire:navigate
        >General Settings</flux:sidebar.item>

        <flux:sidebar.item
            icon="shopping-cart"
            href="{{ route('settings.purchasing') }}"
            :current="request()->routeIs('settings.purchasing')"
            wire:navigate
        >Purchasing Settings</flux:sidebar.item>

        <flux:sidebar.item
            icon="shield-check"
            href="{{ route('roles.index') }}"
            :current="request()->routeIs('roles.*')"
            wire:navigate
        >Roles</flux:sidebar.item>

        <flux:sidebar.item
            icon="users"
            href="{{ route('users.index') }}"
            :current="request()->routeIs('users.*')"
            wire:navigate
        >Users</flux:sidebar.item>

        <flux:sidebar.item
            icon="cpu-chip"
            href="{{ route('llm-logs.index') }}"
            :current="request()->routeIs('llm-logs.*')"
            wire:navigate
        >LLM Logs</flux:sidebar.item>
    </flux:sidebar.group>
</flux:sidebar.nav>
