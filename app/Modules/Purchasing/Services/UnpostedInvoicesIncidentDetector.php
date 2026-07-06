<?php

namespace App\Modules\Purchasing\Services;

use App\Modules\Core\Contracts\IncidentDetector;
use App\Modules\Core\Models\Document;

/**
 * Fires while any purchase invoice sits in received/reviewed/approved — i.e.
 * has cleared initial extraction but hasn't reached the posted/rejected
 * terminal state. Clears once every such invoice has been posted (or
 * rejected/disputed out of this set).
 */
class UnpostedInvoicesIncidentDetector implements IncidentDetector
{
    private const UNPOSTED_STATUSES = ['received', 'reviewed', 'approved'];

    public function type(): string
    {
        return 'unposted_invoices';
    }

    public function check(): ?array
    {
        $byStatus = Document::purchaseInvoices()
            ->whereIn('status', self::UNPOSTED_STATUSES)
            ->selectRaw('status, COUNT(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status');

        $total = (int) $byStatus->sum();

        if ($total === 0) {
            return null;
        }

        return [
            'title' => 'Unposted invoices',
            'message' => sprintf('%d purchase invoice%s awaiting posting.', $total, $total === 1 ? '' : 's'),
            'metadata' => [
                'total' => $total,
                'by_status' => $byStatus->toArray(),
            ],
        ];
    }
}
