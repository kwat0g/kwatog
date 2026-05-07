<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Common\Enums\DocumentType;
use Tests\TestCase;

class DocumentTypeEnumTest extends TestCase
{
    public function test_payslip_is_confidential_by_default(): void
    {
        $this->assertTrue(DocumentType::Payslip->isConfidential());
        $this->assertTrue(DocumentType::PayrollRegister->isConfidential());
        $this->assertTrue(DocumentType::Bir2316->isConfidential());
    }

    public function test_invoice_and_po_are_not_confidential(): void
    {
        $this->assertFalse(DocumentType::Invoice->isConfidential());
        $this->assertFalse(DocumentType::PurchaseOrder->isConfidential());
        $this->assertFalse(DocumentType::Coc->isConfidential());
    }

    public function test_each_case_has_a_human_label(): void
    {
        foreach (DocumentType::cases() as $case) {
            $this->assertNotEmpty($case->label(), "Missing label for {$case->value}");
        }
    }
}
