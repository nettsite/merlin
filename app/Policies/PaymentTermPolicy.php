<?php

namespace App\Policies;

use App\Modules\Billing\Models\PaymentTerm;
use App\Modules\Core\Models\User;

class PaymentTermPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('payment-terms-view-any');
    }

    public function view(User $user, PaymentTerm $paymentTerm): bool
    {
        return $user->can('payment-terms-view');
    }

    public function create(User $user): bool
    {
        return $user->can('payment-terms-create');
    }

    public function update(User $user, PaymentTerm $paymentTerm): bool
    {
        return $user->can('payment-terms-update');
    }

    public function delete(User $user, PaymentTerm $paymentTerm): bool
    {
        return $user->can('payment-terms-delete');
    }

    public function restore(User $user, PaymentTerm $paymentTerm): bool
    {
        return $user->can('payment-terms-restore');
    }

    public function forceDelete(User $user, PaymentTerm $paymentTerm): bool
    {
        return $user->can('payment-terms-force-delete');
    }
}
