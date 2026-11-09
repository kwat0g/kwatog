<?php

declare(strict_types=1);

namespace Tests\Feature\CRM;

use App\Modules\Accounting\Models\Customer;
use App\Modules\Auth\Models\Permission;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\CRM\Models\Product;
use App\Modules\CRM\Models\ProductImage;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProductSpecificationGalleryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        Storage::fake('local');
    }

    public function test_product_catalog_visibility_requires_physical_specifications(): void
    {
        $product = Product::factory()->create(['portal_visibility' => 'restricted']);
        $customer = Customer::factory()->create();
        $actor = $this->actor('crm.products.customer_access.manage', 'crm.products.manage');
        $url = "/api/v1/crm/products/{$product->hash_id}/customer-access";

        $this->actingAs($actor)->putJson($url, [
            'portal_visibility' => 'restricted',
            'portal_customer_ids' => [$customer->hash_id],
        ])->assertUnprocessable()->assertJsonValidationErrors(['specifications']);

        $specifications = [
            'dimensions' => ['length' => '120.00', 'width' => '80.00', 'height' => '32.00', 'unit' => 'mm'],
            'material' => 'Aluminum 6061',
            'finish' => 'Black anodized',
            'tolerances' => '±0.10 mm',
            'notes' => 'Mounting holes on the 80 mm face.',
        ];
        $this->actingAs($actor)->putJson("/api/v1/crm/products/{$product->hash_id}", [
            'specifications' => $specifications,
        ])->assertOk();

        $this->actingAs($actor)->putJson($url, [
            'portal_visibility' => 'restricted',
            'portal_customer_ids' => [$customer->hash_id],
        ])->assertOk();

        $this->assertSame($specifications, $product->fresh()->specifications);
    }

    public function test_expected_margin_and_standard_cost_are_only_visible_to_cost_authorized_staff(): void
    {
        $product = Product::factory()->create(['standard_cost' => '12.50', 'base_selling_price' => '20.00']);

        $this->actingAs($this->actor('crm.products.view'))
            ->getJson("/api/v1/crm/products/{$product->hash_id}")
            ->assertOk()
            ->assertJsonPath('data.standard_cost', null)
            ->assertJsonPath('data.expected_margin_amount', null)
            ->assertJsonPath('data.expected_margin_percent', null);

        $this->actingAs($this->actor('crm.products.view', 'inventory.costs.review'))
            ->getJson("/api/v1/crm/products/{$product->hash_id}")
            ->assertOk()
            ->assertJsonPath('data.standard_cost', '12.50')
            ->assertJsonPath('data.expected_margin_amount', '7.50')
            ->assertJsonPath('data.expected_margin_percent', '37.50');
    }

    public function test_multiple_images_keep_order_and_primary_image_compatibility(): void
    {
        $product = Product::factory()->create();
        $actor = $this->actor('crm.products.manage', 'crm.products.view');

        $response = $this->actingAs($actor)->post("/api/v1/crm/products/{$product->hash_id}/images", [
            'images' => [
                UploadedFile::fake()->image('front.png'),
                UploadedFile::fake()->image('side.jpg'),
            ],
        ]);
        $response->assertCreated()->assertJsonCount(2, 'data.images');
        $first = ProductImage::query()->where('product_id', $product->id)->orderBy('position')->firstOrFail();
        $second = ProductImage::query()->where('product_id', $product->id)->orderBy('position')->skip(1)->firstOrFail();
        $this->assertSame(0, $first->position);
        $this->assertSame(1, $second->position);
        $this->assertTrue($first->is_primary);
        $this->assertSame($first->file_path, $product->fresh()->image_path);

        $this->actingAs($actor)->patchJson("/api/v1/crm/products/{$product->hash_id}/images/{$second->hash_id}/primary")
            ->assertOk()->assertJsonPath('data.image_url', "/api/v1/crm/products/{$product->hash_id}/image");
        $this->assertSame($second->file_path, $product->fresh()->image_path);

        $fileResponse = $this->actingAs($actor)->get("/api/v1/crm/products/{$product->hash_id}/images/{$second->hash_id}/file");
        $fileResponse->assertOk();
        $this->assertStringContainsString('private', $fileResponse->headers->get('Cache-Control'));
        $this->assertStringContainsString('no-store', $fileResponse->headers->get('Cache-Control'));
        $otherProduct = Product::factory()->create();
        $this->getJson("/api/v1/crm/products/{$otherProduct->hash_id}/images/{$second->hash_id}/file")->assertNotFound();

        $this->actingAs($actor)->deleteJson("/api/v1/crm/products/{$product->hash_id}/images/{$second->hash_id}")
            ->assertOk();
        $this->assertSame($first->file_path, $product->fresh()->image_path);
        Storage::disk('local')->assertMissing($second->file_path);
    }

    public function test_image_upload_rejects_non_images_and_excess_gallery_items(): void
    {
        $product = Product::factory()->create();
        $actor = $this->actor('crm.products.manage');

        $this->actingAs($actor)->post("/api/v1/crm/products/{$product->hash_id}/images", [
            'images' => [UploadedFile::fake()->create('not-an-image.pdf', 12, 'application/pdf')],
        ])->assertUnprocessable()->assertJsonValidationErrors(['images.0']);

        $many = array_map(static fn (int $index) => UploadedFile::fake()->image("photo-{$index}.jpg"), range(1, 11));
        $this->actingAs($actor)->post("/api/v1/crm/products/{$product->hash_id}/images", ['images' => $many])
            ->assertUnprocessable()->assertJsonValidationErrors(['images']);
        $this->assertSame(0, ProductImage::query()->where('product_id', $product->id)->count());
    }

    private function actor(string ...$permissionSlugs): User
    {
        $role = Role::create(['name' => 'Product gallery '.uniqid(), 'slug' => 'product_gallery_'.uniqid()]);
        foreach ($permissionSlugs as $slug) {
            $permission = Permission::firstOrCreate(['slug' => $slug], ['name' => $slug, 'module' => 'crm']);
            $role->permissions()->syncWithoutDetaching([$permission->id]);
        }

        return User::factory()->create(['role_id' => $role->id]);
    }
}
