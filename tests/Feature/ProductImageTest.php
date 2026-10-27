<?php

namespace Tests\Feature;

use App\Modules\Catalog\Models\Product;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Spatie\MediaLibrary\Conversions\Jobs\PerformConversionsJob;
use Tests\Support\PosWorld;
use Tests\TestCase;

/** รูปสินค้า: หลายรูป, เลือกรูปที่แสดง, เก็บแยก tenant แบบ private */
class ProductImageTest extends TestCase
{
    use PosWorld;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpWorld();
    }

    private function upload(Product $product, string $name = 'noodle.jpg'): array
    {
        return $this->api($this->ownerA, 'POST', "products/{$product->id}/images", [
            'image' => UploadedFile::fake()->image($name, 400, 300),
        ])->assertCreated()->json('data');
    }

    private function menuImageUrl(string $productId): ?string
    {
        $menu = $this->api($this->cashierA1, 'GET', 'menu')->assertOk()->json('data');
        $products = collect($menu['categories'])->flatMap(fn ($c) => $c['products'])->concat($menu['uncategorized']);

        return $products->firstWhere('id', $productId)['image_url'];
    }

    public function test_uploaded_images_are_stored_privately_under_the_tenant(): void
    {
        Storage::fake('local');

        $this->upload($this->noodleA, 'first.jpg');
        $product = $this->upload($this->noodleA, 'second.png');

        $this->assertCount(2, $product['images']);
        $this->assertSame([true, false], array_column($product['images'], 'is_primary'));
        $media = $this->noodleA->fresh()->getMedia(Product::IMAGES);
        foreach ($media as $item) {
            $this->assertSame($this->tenantA->id, $item->tenant_id);
            $this->assertStringStartsWith("tenants/{$this->tenantA->id}/media/{$item->uuid}/", $item->getPathRelativeToRoot());
            Storage::disk('local')->assertExists($item->getPathRelativeToRoot('thumb'));
        }
    }

    public function test_menu_shows_the_chosen_image_and_falls_back_when_it_is_removed(): void
    {
        Storage::fake('local');
        $this->upload($this->noodleA);
        $images = $this->upload($this->noodleA)['images'];
        $this->assertSame($images[0]['thumb_url'], $this->menuImageUrl($this->noodleA->id));

        $chosen = $this->api($this->ownerA, 'PUT', "products/{$this->noodleA->id}/primary-image", ['media_id' => $images[1]['id']])
            ->assertOk()->json('data.images');
        $this->assertSame([false, true], array_column($chosen, 'is_primary'));
        $this->assertSame($images[1]['thumb_url'], $this->menuImageUrl($this->noodleA->id));

        $remaining = $this->api($this->ownerA, 'DELETE', "products/{$this->noodleA->id}/images/{$images[1]['id']}")->assertOk()->json('data.images');
        $this->assertSame([$images[0]['id']], array_column($remaining, 'id'));
        $this->assertTrue($remaining[0]['is_primary']);
        $this->assertSame($images[0]['thumb_url'], $this->menuImageUrl($this->noodleA->id));
        $this->assertNull($this->menuImageUrl($this->waterA->id));
    }

    public function test_original_is_served_until_the_queued_conversion_finishes(): void
    {
        Storage::fake('local');
        Queue::fake([PerformConversionsJob::class]);

        $image = $this->upload($this->noodleA)['images'][0];

        Queue::assertPushed(PerformConversionsJob::class);
        $this->assertStringNotContainsString('/thumb', $image['thumb_url']);
        $this->get($image['thumb_url'])->assertOk();
    }

    public function test_image_urls_require_a_valid_signature(): void
    {
        Storage::fake('local');
        $url = $this->upload($this->noodleA)['images'][0]['thumb_url'];

        $this->get($url)->assertOk();
        $this->get(strtok($url, '?'))->assertForbidden();
    }

    public function test_cashier_cannot_manage_images(): void
    {
        Storage::fake('local');

        $this->api($this->cashierA1, 'POST', "products/{$this->noodleA->id}/images", [
            'image' => UploadedFile::fake()->image('noodle.jpg', 400, 300),
        ])->assertForbidden();
    }

    public function test_images_of_another_tenant_cannot_be_reached(): void
    {
        Storage::fake('local');
        $imageA = $this->upload($this->noodleA)['images'][0];

        $this->api($this->ownerB, 'POST', "products/{$this->noodleA->id}/images", [
            'image' => UploadedFile::fake()->image('x.jpg', 400, 300),
        ], store: $this->storeB1)->assertNotFound();
        // ใช้สินค้าของตัวเองแต่อ้าง media ของ tenant อื่น
        $this->api($this->ownerB, 'DELETE', "products/{$this->noodleB->id}/images/{$imageA['id']}", store: $this->storeB1)
            ->assertNotFound()->assertJsonPath('error.code', 'image_not_found');
        $this->api($this->ownerB, 'PUT', "products/{$this->noodleB->id}/primary-image", ['media_id' => $imageA['id']], store: $this->storeB1)
            ->assertNotFound();

        $this->assertDatabaseHas('media', ['id' => $imageA['id']]);
        $this->assertNull($this->noodleB->fresh()->primary_media_id);
    }

    public function test_non_image_upload_is_rejected(): void
    {
        Storage::fake('local');

        $this->api($this->ownerA, 'POST', "products/{$this->noodleA->id}/images", [
            'image' => UploadedFile::fake()->create('menu.pdf', 100, 'application/pdf'),
        ])->assertUnprocessable()->assertJsonPath('error.code', 'validation_failed')->assertJsonStructure(['error' => ['details' => ['image']]]);
        $this->assertDatabaseCount('media', 0);
    }

    public function test_product_image_count_is_limited(): void
    {
        Storage::fake('local');
        for ($i = 0; $i < Product::MAX_IMAGES; $i++) {
            $this->upload($this->noodleA);
        }

        $this->api($this->ownerA, 'POST', "products/{$this->noodleA->id}/images", [
            'image' => UploadedFile::fake()->image('extra.jpg', 400, 300),
        ])->assertConflict()->assertJsonPath('error.code', 'too_many_images');
        $this->assertDatabaseCount('media', Product::MAX_IMAGES);
    }
}
