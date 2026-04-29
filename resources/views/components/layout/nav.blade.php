<flux:sidebar.nav class="px-2">
    <flux:sidebar.group heading="Expenses">
        <flux:sidebar.item
            icon="building-storefront"
            href="/suppliers"
            :current="request()->is('suppliers*')"
            wire:navigate
        >Suppliers</flux:sidebar.item>

        <flux:sidebar.item
            icon="document-text"
            href="/purchase-invoices"
            :current="request()->is('purchase-invoices*')"
            wire:navigate
        >Purchase Invoices</flux:sidebar.item>

        <flux:sidebar.item
            icon="adjustments-horizontal"
            href="/posting-rules"
            :current="request()->is('posting-rules*')"
            wire:navigate
        >Posting Rules</flux:sidebar.item>
    </flux:sidebar.group>

    <flux:sidebar.group heading="Accounting">
        <flux:sidebar.item
            icon="banknotes"
            href="/accounts"
            :current="request()->is('accounts*')"
            wire:navigate
        >Accounts</flux:sidebar.item>

        <flux:sidebar.item
            icon="folder"
            href="/account-groups"
            :current="request()->is('account-groups*')"
            wire:navigate
        >Account Groups</flux:sidebar.item>

        <flux:sidebar.item
            icon="chart-bar"
            href="/reports"
            :current="request()->is('reports*')"
            wire:navigate
        >Reports</flux:sidebar.item>
    </flux:sidebar.group>

    <flux:sidebar.group heading="Settings">
        <flux:sidebar.item
            icon="cog-6-tooth"
            href="/settings/general"
            :current="request()->is('settings/general*')"
            wire:navigate
        >General Settings</flux:sidebar.item>

        <flux:sidebar.item
            icon="shopping-cart"
            href="/settings/purchasing"
            :current="request()->is('settings/purchasing*')"
            wire:navigate
        >Purchasing Settings</flux:sidebar.item>

        <flux:sidebar.item
            icon="shield-check"
            href="/roles"
            :current="request()->is('roles*')"
            wire:navigate
        >Roles</flux:sidebar.item>

        <flux:sidebar.item
            icon="users"
            href="/users"
            :current="request()->is('users*')"
            wire:navigate
        >Users</flux:sidebar.item>

        <flux:sidebar.item
            icon="cpu-chip"
            href="/llm-logs"
            :current="request()->is('llm-logs*')"
            wire:navigate
        >LLM Logs</flux:sidebar.item>
    </flux:sidebar.group>
</flux:sidebar.nav>
