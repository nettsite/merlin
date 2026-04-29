<flux:navbar class="max-lg:hidden">
    <flux:navbar.item href="{{ route('dashboard') }}" :current="request()->routeIs('dashboard')" wire:navigate>Dashboard</flux:navbar.item>

    <flux:dropdown>
        <flux:navbar.item
            icon:trailing="chevron-down"
            :current="request()->is('suppliers*', 'purchase-invoices*', 'posting-rules*')"
        >Expenses</flux:navbar.item>
        <flux:navmenu>
            <flux:navmenu.item href="/suppliers" wire:navigate>Suppliers</flux:navmenu.item>
            <flux:navmenu.item href="/purchase-invoices" wire:navigate>Purchase Invoices</flux:navmenu.item>
            <flux:navmenu.item href="/posting-rules" wire:navigate>Posting Rules</flux:navmenu.item>
        </flux:navmenu>
    </flux:dropdown>

    <flux:dropdown>
        <flux:navbar.item
            icon:trailing="chevron-down"
            :current="request()->is('accounts*', 'account-groups*', 'reports*')"
        >Accounting</flux:navbar.item>
        <flux:navmenu>
            <flux:navmenu.item href="/accounts" wire:navigate>Accounts</flux:navmenu.item>
            <flux:navmenu.item href="/account-groups" wire:navigate>Account Groups</flux:navmenu.item>
            <flux:navmenu.item href="/reports" wire:navigate>Reports</flux:navmenu.item>
        </flux:navmenu>
    </flux:dropdown>

    <flux:dropdown>
        <flux:navbar.item
            icon:trailing="chevron-down"
            :current="request()->is('settings/*', 'roles*', 'users*', 'llm-logs*')"
        >Settings</flux:navbar.item>
        <flux:navmenu>
            <flux:navmenu.item href="/settings/general" wire:navigate>General Settings</flux:navmenu.item>
            <flux:navmenu.item href="/settings/purchasing" wire:navigate>Purchasing Settings</flux:navmenu.item>
            <flux:navmenu.item href="/roles" wire:navigate>Roles</flux:navmenu.item>
            <flux:navmenu.item href="/users" wire:navigate>Users</flux:navmenu.item>
            <flux:navmenu.item href="/llm-logs" wire:navigate>LLM Logs</flux:navmenu.item>
        </flux:navmenu>
    </flux:dropdown>
</flux:navbar>
