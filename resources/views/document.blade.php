<div style="width: 210mm; height: 297mm; padding: 25mm 19mm">
    <table style="width: 100%; border-collapse: collapse; border: 1px solid black;">
        <tr>
            <td style="width: 50%; border: 1px solid black; padding: 5px;">
                <h1 style="text-align: center;">{{ match ($document->role) {
                    'I' => 'Invoice',
                    'R' => 'Reciept',
                    'C' => 'Credit Note',
                    default => $document->status,
                } }}</h1>
                <p style="text-align: center;">{{ $document->number }}</p>
            </td>
            <td style="width: 50%; border: 1px solid black; padding: 5px;">
                {{-- <h2 style="text-align: center;">{{ $document->author }}</h2>
                <p style="text-align: center;">{{ $document->created_at }}</p> --}}
            </td>
        </tr>
</div>
