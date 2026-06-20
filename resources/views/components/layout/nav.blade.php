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
            icon="document-text"
            href="{{ route('sales-invoices.index') }}"
            :current="request()->routeIs('sales-invoices.*')"
            wire:navigate
        >Sales Invoices</flux:sidebar.item>

        <flux:sidebar.item
            icon="document-check"
            href="{{ route('quotes.index') }}"
            :current="request()->routeIs('quotes.*')"
            wire:navigate
        >Quotes</flux:sidebar.item>

        <flux:sidebar.item
            icon="document-minus"
            href="{{ route('credit-notes.index') }}"
            :current="request()->routeIs('credit-notes.*')"
            wire:navigate
        >Credit Notes</flux:sidebar.item>

        <flux:sidebar.item
            icon="arrow-path"
            href="{{ route('recurring-invoices.index') }}"
            :current="request()->routeIs('recurring-invoices.*')"
            wire:navigate
        >Recurring Invoices</flux:sidebar.item>

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
            icon="banknotes"
            href="{{ route('settings.billing') }}"
            :current="request()->routeIs('settings.billing')"
            wire:navigate
        >Billing Settings</flux:sidebar.item>

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

    <flux:sidebar.group heading="Help">
        <flux:sidebar.item
            icon="chat-bubble-left-right"
            href="{{ route('help') }}"
            :current="request()->routeIs('help')"
            wire:navigate
        >Help Chat</flux:sidebar.item>

        <flux:sidebar.item
            icon="book-open"
            href="/docs/user-guide/"
            target="_blank"
        >User Guide</flux:sidebar.item>

        <flux:sidebar.item
            icon="code-bracket"
            href="/docs/system-guide/"
            target="_blank"
        >System Guide</flux:sidebar.item>
    </flux:sidebar.group>

    <flux:sidebar.group heading="Emails">
        <flux:sidebar.item
            icon="chart-bar"
            href="{{ route('nettmail.dashboard') }}"
            :current="request()->routeIs('nettmail.dashboard')"
        >Dashboard</flux:sidebar.item>

        <flux:sidebar.item
            icon="envelope"
            href="{{ route('nettmail.templates.index') }}"
            :current="request()->routeIs('nettmail.templates.*')"
        >Templates</flux:sidebar.item>

        <flux:sidebar.item
            icon="user-group"
            href="{{ route('nettmail.contacts.index') }}"
            :current="request()->routeIs('nettmail.contacts.*')"
        >Contacts</flux:sidebar.item>

        <flux:sidebar.item
            icon="queue-list"
            href="{{ route('nettmail.lists.index') }}"
            :current="request()->routeIs('nettmail.lists.*')"
        >Lists</flux:sidebar.item>

        <flux:sidebar.item
            icon="funnel"
            href="{{ route('nettmail.segments.index') }}"
            :current="request()->routeIs('nettmail.segments.*')"
        >Segments</flux:sidebar.item>

        <flux:sidebar.item
            icon="megaphone"
            href="{{ route('nettmail.campaigns.index') }}"
            :current="request()->routeIs('nettmail.campaigns.*')"
        >Campaigns</flux:sidebar.item>

        <flux:sidebar.item
            icon="cog-6-tooth"
            href="{{ route('nettmail.settings') }}"
            :current="request()->routeIs('nettmail.settings')"
        >Settings</flux:sidebar.item>
    </flux:sidebar.group>
</flux:sidebar.nav>
