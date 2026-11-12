<?php

declare(strict_types=1);

namespace Tests\Feature\B2B;

use App\Modules\Auth\Models\User;
use App\Modules\B2B\Models\PortalContractTemplate;
use App\Modules\B2B\Services\PortalContractPdfProcessor;
use App\Modules\B2B\Services\PortalContractPdfService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Mockery;
use Tests\TestCase;

class PortalContractPdfServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_pdf_fields_are_mapped_to_reviewed_customer_data_and_filled(): void
    {
        Storage::fake('local');
        $templatePath = 'portal-contract-templates/customer/honda.pdf';
        $source = "%PDF-1.4\nAcroForm\n%%EOF";
        Storage::disk('local')->put($templatePath, $source);
        $template = PortalContractTemplate::query()->create([
            'name' => 'Honda master agreement',
            'realm' => 'customer',
            'file_path' => $templatePath,
            'filename' => 'honda.pdf',
            'size' => strlen($source),
            'pdf_fields' => [['name' => 'Buyer Name', 'type' => 'Text']],
            'field_mappings' => ['Buyer Name' => 'customer_name'],
            'required_fields' => ['Buyer Name'],
            'version' => 1,
            'source_sha256' => hash('sha256', $source),
            'created_by' => User::factory()->create()->id,
        ]);
        $processor = Mockery::mock(PortalContractPdfProcessor::class);
        $processor->shouldReceive('fill')
            ->once()
            ->with(Storage::disk('local')->path($templatePath), ['Buyer Name' => 'Honda Philippines'])
            ->andReturn('/tmp/filled-honda.pdf');
        $this->app->instance(PortalContractPdfProcessor::class, $processor);

        $result = app(PortalContractPdfService::class)->fill($template, ['customer_name' => 'Honda Philippines']);

        $this->assertSame('/tmp/filled-honda.pdf', $result);
    }

    public function test_pdf_fill_rejects_missing_required_values_before_processing(): void
    {
        $template = new PortalContractTemplate([
            'realm' => 'customer',
            'pdf_fields' => [['name' => 'Buyer Name', 'type' => 'Text']],
            'field_mappings' => ['Buyer Name' => 'customer_name'],
            'required_fields' => ['Buyer Name'],
        ]);

        $this->expectException(ValidationException::class);
        app(PortalContractPdfService::class)->fill($template, ['customer_name' => null]);
    }

    public function test_pdf_fill_rejects_unknown_field_mappings_and_realm_values(): void
    {
        $template = new PortalContractTemplate([
            'realm' => 'supplier',
            'pdf_fields' => [['name' => 'Vendor Name', 'type' => 'Text']],
            'field_mappings' => ['Vendor Name' => 'customer_name'],
            'required_fields' => [],
        ]);

        $this->expectException(ValidationException::class);
        app(PortalContractPdfService::class)->fill($template, ['customer_name' => 'Should not cross realms']);
    }
}
