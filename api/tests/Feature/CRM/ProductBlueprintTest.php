<?php

declare(strict_types=1);

namespace Tests\Feature\CRM;

use App\Modules\Auth\Models\Permission;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\CRM\Models\Product;
use App\Modules\CRM\Models\ProductBlueprint;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProductBlueprintTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        Storage::fake('local');
    }

    public function test_custom_product_cannot_be_published_until_a_blueprint_is_uploaded(): void
    {
        $product = Product::factory()->create([
            'portal_visibility' => 'restricted',
            'is_customizable' => true,
            'specifications' => [
                'dimensions' => ['length' => '10', 'width' => '20', 'height' => '30', 'unit' => 'mm'],
                'material' => 'Steel',
            ],
        ]);
        $actor = $this->actor('crm.products.customer_access.manage', 'crm.products.manage', 'crm.products.view');
        $accessUrl = "/api/v1/crm/products/{$product->hash_id}/customer-access";
        $access = ['portal_visibility' => 'standard', 'portal_customer_ids' => []];

        $this->actingAs($actor)->putJson($accessUrl, $access)
            ->assertUnprocessable()->assertJsonValidationErrors(['blueprints']);

        $response = $this->actingAs($actor)->post("/api/v1/crm/products/{$product->hash_id}/blueprints", [
            'title' => 'Mounting assembly drawing',
            'revision' => 'B',
            'notes' => 'Use the updated hole spacing.',
            'file' => UploadedFile::fake()->createWithContent('mounting-b.pdf', "%PDF-1.4\nblueprint revision B\n"),
        ]);
        $response->assertCreated()->assertJsonMissingPath('data.path');
        $blueprint = ProductBlueprint::query()->where('product_id', $product->id)->firstOrFail();
        Storage::disk('local')->assertExists($blueprint->file_path);

        $this->actingAs($actor)->post("/api/v1/crm/products/{$product->hash_id}/blueprints", [
            'title' => 'Mounting assembly drawing',
            'revision' => 'B',
            'file' => UploadedFile::fake()->createWithContent('duplicate.pdf', "%PDF-1.4\nduplicate\n"),
        ])->assertUnprocessable()->assertJsonValidationErrors(['revision']);
        $this->assertSame(1, ProductBlueprint::query()->where('product_id', $product->id)->count());

        $this->actingAs($actor)->putJson($accessUrl, $access)->assertOk();
        $fileResponse = $this->actingAs($actor)->get("/api/v1/crm/products/{$product->hash_id}/blueprints/{$blueprint->hash_id}/file");
        $fileResponse->assertOk();
        $this->assertStringContainsString('private', $fileResponse->headers->get('Cache-Control'));
        $this->assertStringContainsString('no-store', $fileResponse->headers->get('Cache-Control'));

        $otherProduct = Product::factory()->create();
        $this->getJson("/api/v1/crm/products/{$otherProduct->hash_id}/blueprints/{$blueprint->hash_id}/file")
            ->assertNotFound();
        $this->actingAs($actor)->deleteJson("/api/v1/crm/products/{$product->hash_id}/blueprints/{$blueprint->hash_id}")
            ->assertUnprocessable();
        Storage::disk('local')->assertExists($blueprint->file_path);
    }

    public function test_blueprint_upload_rejects_unapproved_file_types(): void
    {
        $product = Product::factory()->create(['is_customizable' => true]);
        $actor = $this->actor('crm.products.manage');

        $this->actingAs($actor)->post("/api/v1/crm/products/{$product->hash_id}/blueprints", [
            'title' => 'Assembly source', 'revision' => 'A',
            'file' => UploadedFile::fake()->create('script.exe', 10, 'application/octet-stream'),
        ])->assertUnprocessable()->assertJsonValidationErrors(['file']);

        $this->assertSame(0, ProductBlueprint::query()->where('product_id', $product->id)->count());
    }

    private function actor(string ...$permissionSlugs): User
    {
        $role = Role::create(['name' => 'Product blueprint '.uniqid(), 'slug' => 'product_blueprint_'.uniqid()]);
        foreach ($permissionSlugs as $slug) {
            $permission = Permission::firstOrCreate(['slug' => $slug], ['name' => $slug, 'module' => 'crm']);
            $role->permissions()->syncWithoutDetaching([$permission->id]);
        }

        return User::factory()->create(['role_id' => $role->id]);
    }
}
