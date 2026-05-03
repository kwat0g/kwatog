{{-- Sprint 8 — Task 76. Reusable approval signature block.

     Expects $approvalRecords: array of:
       [['role' => 'Prepared by', 'name' => 'Juan Cruz',  'signed_at' => '2026-04-20'],
        ['role' => 'Noted by',    'name' => 'Maria Reyes','signed_at' => '2026-04-20'],
        ['role' => 'Checked by',  'name' => 'Pedro Tan',  'signed_at' => '2026-04-21'],
        ['role' => 'Approved by', 'name' => 'Anna Diaz',  'signed_at' => '2026-04-21']]

     Designed for DomPDF; minimal CSS to keep render predictable.
--}}
<table class="approval-signatures" style="width:100%; margin-top:30px; border-collapse:collapse; font-size:9pt;">
    <tr>
        @foreach ($approvalRecords ?? [] as $rec)
            <td style="width:25%; vertical-align:bottom; padding:0 8px;">
                <div style="height:32px; border-bottom:1px solid #444;">&nbsp;</div>
                <div style="margin-top:4px; text-align:center; font-weight:500;">
                    {{ $rec['name'] ?? '—' }}
                </div>
                <div style="text-align:center; color:#777; font-size:8pt;">
                    {{ $rec['role'] ?? '' }}
                    @if (! empty($rec['signed_at'])) · {{ $rec['signed_at'] }} @endif
                </div>
            </td>
        @endforeach
    </tr>
</table>
