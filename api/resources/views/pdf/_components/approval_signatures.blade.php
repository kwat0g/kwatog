{{-- Sprint P9 — reusable approval signature block.

     $approvals: array of:
       [
         'role'      => 'Prepared by' | 'Noted by' | 'Checked by'
                        | 'Reviewed by' | 'Approved by',
         'name'      => 'Maria Reyes'  // null/empty for pending steps
         'signed_at' => '2026-04-20'   // null/empty for pending steps
       ]

     Approved tiers render the typed name + date.
     Pending tiers leave the line blank for a physical signature.

     Designed for DomPDF; minimal CSS to keep render predictable. --}}
@php($_approvals = $approvals ?? $approvalRecords ?? [])
<table class="approval-signatures"
       style="width:100%; margin-top:30px; border-collapse:collapse; font-size:9pt;">
    <tr>
        @foreach ($_approvals as $rec)
            <td style="width:20%; vertical-align:bottom; padding:0 6px;">
                <div style="height:32px; border-bottom:1px solid #444;">
                    @if (! empty($rec['name']))
                        <div style="text-align:center; padding-top:18px; font-size:9pt;">
                            {{ $rec['name'] }}
                        </div>
                    @else
                        &nbsp;
                    @endif
                </div>
                <div style="margin-top:4px; text-align:center; font-size:8pt; color:#444; text-transform:uppercase; letter-spacing:0.04em;">
                    {{ $rec['role'] ?? '' }}
                </div>
                @if (! empty($rec['signed_at']))
                    <div style="text-align:center; font-size:8pt; color:#777;">
                        {{ $rec['signed_at'] }}
                    </div>
                @else
                    <div style="text-align:center; font-size:8pt; color:#bbb;">
                        Date: ____________
                    </div>
                @endif
            </td>
        @endforeach
    </tr>
</table>
