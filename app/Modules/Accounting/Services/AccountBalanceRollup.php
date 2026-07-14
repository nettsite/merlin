<?php

namespace App\Modules\Accounting\Services;

use App\Modules\Accounting\Models\Account;

class AccountBalanceRollup
{
    /**
     * Sum every account's debit/credit into its top-most ancestor (walking the
     * parent_id chain), so a client's receivable sub-account rolls up into the
     * shared control account instead of appearing as its own report line.
     * Accounts with no parent are left as-is.
     *
     * @param  array<string, array{debit: float, credit: float}>  $acc  Keyed by account_id.
     * @return array<string, array{debit: float, credit: float}> Keyed by the surviving (root) account_id.
     */
    public static function rollupToRoots(array $acc): array
    {
        if (empty($acc)) {
            return $acc;
        }

        $parentOf = [];
        $frontier = array_keys($acc);

        while (! empty($frontier)) {
            $batch = Account::whereIn('id', $frontier)->get(['id', 'parent_id'])->keyBy('id');
            $frontier = [];

            foreach ($batch as $id => $account) {
                if (array_key_exists($id, $parentOf)) {
                    continue;
                }

                $parentOf[$id] = $account->parent_id;

                if ($account->parent_id !== null && ! array_key_exists($account->parent_id, $parentOf)) {
                    $frontier[] = $account->parent_id;
                }
            }
        }

        $rolled = [];

        foreach ($acc as $id => $totals) {
            $rootId = $id;

            while (($parentOf[$rootId] ?? null) !== null) {
                $rootId = $parentOf[$rootId];
            }

            if (! isset($rolled[$rootId])) {
                $rolled[$rootId] = ['debit' => 0.0, 'credit' => 0.0];
            }

            $rolled[$rootId]['debit'] += $totals['debit'];
            $rolled[$rootId]['credit'] += $totals['credit'];
        }

        return $rolled;
    }
}
