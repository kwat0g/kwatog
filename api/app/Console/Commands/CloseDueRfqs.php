<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Purchasing\Models\RequestForQuote;
use App\Modules\Purchasing\Services\RequestForQuoteService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class CloseDueRfqs extends Command
{
    protected $signature = 'purchasing:close-due-rfqs';
    protected $description = 'Close open supplier RFQs whose server-side deadline has passed';

    public function __construct(private readonly RequestForQuoteService $rfqs)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $due = RequestForQuote::query()->where('status', 'open')->where('closes_at', '<=', now())->get();
        $closed = 0;
        $errors = 0;
        foreach ($due as $rfq) {
            try {
                $this->rfqs->closeDue($rfq);
                $closed++;
            } catch (\Throwable $e) {
                $errors++;
                Log::error('purchasing:close-due-rfqs failed', ['rfq_id' => $rfq->id, 'error' => $e->getMessage()]);
            }
        }
        $this->info("Closed {$closed} due RFQ(s).".($errors ? " {$errors} failed." : ''));
        return $errors > 0 ? self::FAILURE : self::SUCCESS;
    }
}
