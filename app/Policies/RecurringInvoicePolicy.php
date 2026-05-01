<?php

namespace App\Policies;

use App\Modules\Billing\Models\RecurringInvoice;
use App\Modules\Core\Models\User;

class RecurringInvoicePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('recurring-invoices-view-any');
    }

    public function view(User $user, RecurringInvoice $recurringInvoice): bool
    {
        return $user->can('recurring-invoices-view');
    }

    public function create(User $user): bool
    {
        return $user->can('recurring-invoices-create');
    }

    public function update(User $user, RecurringInvoice $recurringInvoice): bool
    {
        return $user->can('recurring-invoices-update');
    }

    public function delete(User $user, RecurringInvoice $recurringInvoice): bool
    {
        return $user->can('recurring-invoices-delete');
    }

    public function restore(User $user, RecurringInvoice $recurringInvoice): bool
    {
        return $user->can('recurring-invoices-restore');
    }

    public function forceDelete(User $user, RecurringInvoice $recurringInvoice): bool
    {
        return $user->can('recurring-invoices-force-delete');
    }
}
