<?php

namespace Tests\Feature;

use App\Models\Salon;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ServiceImageImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_access_service_image_import_endpoints(): void
    {
        $this->postJson('/dashboard/services/import-image/analyze')
            ->assertUnauthorized();

        $this->postJson('/dashboard/services/import-image/store', ['services' => []])
            ->assertUnauthorized();
    }

    public function test_analyze_validates_required_image_file(): void
    {
        [, , $user] = $this->salonSetup();

        $this->actingAs($user)
            ->postJson('/dashboard/services/import-image/analyze')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('image');
    }

    public function test_analyze_rejects_non_image_files(): void
    {
        [, , $user] = $this->salonSetup();

        $this->actingAs($user)
            ->postJson('/dashboard/services/import-image/analyze', [
                'image' => UploadedFile::fake()->create('services.txt', 10, 'text/plain'),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('image');
    }

    public function test_analyze_rejects_too_large_images(): void
    {
        [, , $user] = $this->salonSetup();

        $this->actingAs($user)
            ->postJson('/dashboard/services/import-image/analyze', [
                'image' => UploadedFile::fake()->image('services.jpg')->size(9000),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('image');
    }

    public function test_analyze_returns_extracted_services_without_creating_records(): void
    {
        config(['services.gemini.key' => 'test-key']);
        [$salon, , $user] = $this->salonSetup();

        Http::fake(['*' => Http::response([
            'candidates' => [[
                'content' => ['parts' => [[
                    'text' => json_encode([
                        'services' => [[
                        'name' => 'Tuns dama',
                        'category' => 'Coafor',
                        'duration_minutes' => 45,
                            'price' => 120,
                            'description' => 'Tuns, spalat si aranjat',
                            'notes' => null,
                        ]],
                    ]),
                ]]],
            ]],
        ], 200)]);

        $this->actingAs($user)
            ->postJson('/dashboard/services/import-image/analyze', [
                'image' => UploadedFile::fake()->image('services.jpg'),
            ])
            ->assertOk()
            ->assertJsonPath('services.0.name', 'Tuns dama')
            ->assertJsonPath('services.0.category', 'Coafor')
            ->assertJsonPath('services.0.duration_minutes', 45)
            ->assertJsonPath('services.0.price', 120);

        $this->assertSame(0, $salon->services()->count());
    }

    public function test_analyze_handles_invalid_ai_json_gracefully(): void
    {
        config(['services.gemini.key' => 'test-key']);
        [, , $user] = $this->salonSetup();

        Http::fake(['*' => Http::response([
            'candidates' => [[
                'content' => ['parts' => [['text' => 'not json']]],
            ]],
        ], 200)]);

        $this->actingAs($user)
            ->postJson('/dashboard/services/import-image/analyze', [
                'image' => UploadedFile::fake()->image('services.png'),
            ])
            ->assertOk()
            ->assertJsonPath('services', [])
            ->assertJsonPath('warning', 'invalid_json');
    }

    public function test_analyze_recovers_json_from_markdown_fence_and_trailing_commas(): void
    {
        config(['services.gemini.key' => 'test-key']);
        [, , $user] = $this->salonSetup();

        Http::fake(['*' => Http::response([
            'candidates' => [[
                'content' => ['parts' => [[
                    'text' => <<<'JSON'
```json
{
  "services": [
    {
      "name": "Manichiura",
      "category": "Unghii",
      "duration_minutes": 60,
      "price": 120,
      "description": null,
      "notes": null,
    },
  ],
  "warning": null,
}
```
JSON,
                ]]],
            ]],
        ], 200)]);

        $this->actingAs($user)
            ->postJson('/dashboard/services/import-image/analyze', [
                'image' => UploadedFile::fake()->image('services.webp'),
            ])
            ->assertOk()
            ->assertJsonPath('services.0.name', 'Manichiura')
            ->assertJsonPath('services.0.category', 'Unghii')
            ->assertJsonPath('warning', null);
    }

    public function test_analyze_accepts_bare_services_array_response(): void
    {
        config(['services.gemini.key' => 'test-key']);
        [, , $user] = $this->salonSetup();

        Http::fake(['*' => Http::response([
            'candidates' => [[
                'content' => ['parts' => [[
                    'text' => json_encode([[
                        'name' => 'Pedichiura',
                        'category' => 'Unghii',
                        'duration_minutes' => 50,
                        'price' => 100,
                    ]]),
                ]]],
            ]],
        ], 200)]);

        $this->actingAs($user)
            ->postJson('/dashboard/services/import-image/analyze', [
                'image' => UploadedFile::fake()->image('services.png'),
            ])
            ->assertOk()
            ->assertJsonPath('services.0.name', 'Pedichiura')
            ->assertJsonPath('services.0.category', 'Unghii');
    }

    public function test_analyze_flattens_category_group_response(): void
    {
        config(['services.gemini.key' => 'test-key']);
        [, , $user] = $this->salonSetup();

        Http::fake(['*' => Http::response([
            'candidates' => [[
                'content' => ['parts' => [[
                    'text' => json_encode([
                        'categories' => [
                            [
                                'name' => 'ANTI-WRINKLE INJECTIONS (BOTOX)',
                                'services' => [
                                    ['name' => '1 zona', 'price' => 170],
                                    ['name' => '2 zone', 'price' => 220],
                                ],
                            ],
                            [
                                'name' => 'LIP FILLERS',
                                'services' => [
                                    ['name' => '0.5ml Lip Enhancement', 'price' => 160],
                                ],
                            ],
                        ],
                        'warning' => null,
                    ]),
                ]]],
            ]],
        ], 200)]);

        $this->actingAs($user)
            ->postJson('/dashboard/services/import-image/analyze', [
                'image' => UploadedFile::fake()->image('luxe-aesthetics.jpg'),
            ])
            ->assertOk()
            ->assertJsonPath('services.0.name', '1 zona')
            ->assertJsonPath('services.0.category', 'ANTI-WRINKLE INJECTIONS (BOTOX)')
            ->assertJsonPath('services.2.name', '0.5ml Lip Enhancement')
            ->assertJsonPath('services.2.category', 'LIP FILLERS');
    }

    public function test_analyze_flattens_heading_keyed_response(): void
    {
        config(['services.gemini.key' => 'test-key']);
        [, , $user] = $this->salonSetup();

        Http::fake(['*' => Http::response([
            'candidates' => [[
                'content' => ['parts' => [[
                    'text' => json_encode([
                        'DERMAL FILLERS' => [
                            ['name' => 'Cheek Filler (1ml)', 'price' => 250],
                            ['name' => 'Chin Filler', 'price' => 250],
                        ],
                    ]),
                ]]],
            ]],
        ], 200)]);

        $this->actingAs($user)
            ->postJson('/dashboard/services/import-image/analyze', [
                'image' => UploadedFile::fake()->image('luxe-aesthetics.png'),
            ])
            ->assertOk()
            ->assertJsonPath('services.0.name', 'Cheek Filler (1ml)')
            ->assertJsonPath('services.0.category', 'DERMAL FILLERS')
            ->assertJsonPath('services.1.category', 'DERMAL FILLERS');
    }

    public function test_store_creates_selected_services_for_current_salon(): void
    {
        [$salon, $location, $user] = $this->salonSetup();

        $this->actingAs($user)
            ->postJson('/dashboard/services/import-image/store', [
                'services' => [[
                    'name' => 'Tuns dama',
                    'category' => 'Coafor',
                    'duration_minutes' => 45,
                    'price' => 120,
                    'description' => 'Tuns si spalat',
                    'notes' => 'Par mediu',
                    'selected' => true,
                ]],
            ])
            ->assertOk()
            ->assertJsonPath('created_count', 1)
            ->assertJsonPath('skipped', []);

        $service = $salon->services()->where('name', 'Tuns dama')->firstOrFail();
        $this->assertSame('120', $service->price);
        $this->assertSame('RON', $service->currency);
        $this->assertSame('Coafor', $service->type);
        $this->assertSame(45, $service->duration);
        $this->assertSame([$location->id], $service->location_ids);
        $this->assertStringContainsString('Tuns si spalat', $service->notes);
        $this->assertStringContainsString('Par mediu', $service->notes);
        $this->assertSame(['Coafor'], $salon->refresh()->service_categories);
    }

    public function test_store_merges_imported_categories_without_duplicates(): void
    {
        [$salon, , $user] = $this->salonSetup();
        $salon->update(['service_categories' => ['Coafor']]);

        $this->actingAs($user)
            ->postJson('/dashboard/services/import-image/store', [
                'services' => [
                    [
                        'name' => 'Vopsit',
                        'category' => 'Coafor',
                        'duration_minutes' => 90,
                        'price' => 250,
                        'selected' => true,
                    ],
                    [
                        'name' => 'Manichiura',
                        'category' => 'Unghii',
                        'duration_minutes' => 60,
                        'price' => 120,
                        'selected' => true,
                    ],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('created_count', 2);

        $this->assertSame(['Coafor', 'Unghii'], $salon->refresh()->service_categories);
        $this->assertSame('Unghii', $salon->services()->where('name', 'Manichiura')->value('type'));
    }

    public function test_store_skips_unselected_services(): void
    {
        [$salon, , $user] = $this->salonSetup();

        $this->actingAs($user)
            ->postJson('/dashboard/services/import-image/store', [
                'services' => [[
                    'name' => 'Masaj',
                    'duration_minutes' => 60,
                    'price' => 200,
                    'selected' => false,
                ]],
            ])
            ->assertOk()
            ->assertJsonPath('created_count', 0);

        $this->assertFalse($salon->services()->where('name', 'Masaj')->exists());
    }

    public function test_store_skips_duplicates_by_normalized_name(): void
    {
        [$salon, , $user] = $this->salonSetup();
        $salon->services()->create([
            'name' => 'Tuns dama',
            'price' => '100',
            'duration' => 30,
            'location_ids' => [],
        ]);

        $this->actingAs($user)
            ->postJson('/dashboard/services/import-image/store', [
                'services' => [[
                    'name' => '  TUNS DAMA  ',
                    'duration_minutes' => 45,
                    'price' => 120,
                    'selected' => true,
                ]],
            ])
            ->assertOk()
            ->assertJsonPath('created_count', 0)
            ->assertJsonPath('skipped.0.reason', 'duplicate');

        $this->assertSame(1, $salon->services()->where('name', 'Tuns dama')->count());
    }

    public function test_store_validates_required_name_and_service_limit(): void
    {
        [, , $user] = $this->salonSetup();

        $this->actingAs($user)
            ->postJson('/dashboard/services/import-image/store', [
                'services' => [['name' => '', 'selected' => true]],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('services.0.name');

        $this->actingAs($user)
            ->postJson('/dashboard/services/import-image/store', [
                'services' => array_fill(0, 51, ['name' => 'Service', 'selected' => true]),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('services');
    }

    public function test_service_categories_can_be_saved_as_empty_list(): void
    {
        [$salon, , $user] = $this->salonSetup();
        $salon->update(['service_categories' => ['Coafor', 'Unghii']]);
        $salon->services()->create([
            'name' => 'Tuns dama',
            'type' => 'Coafor',
            'price' => '120',
            'duration' => 45,
        ]);

        $this->actingAs($user)
            ->from('/dashboard/services')
            ->put('/services/categories', ['categories' => []])
            ->assertRedirect('/dashboard/services');

        $this->assertSame([], $salon->refresh()->service_categories);
        $this->assertNull($salon->services()->where('name', 'Tuns dama')->value('type'));
    }

    public function test_service_category_rename_updates_existing_services(): void
    {
        [$salon, , $user] = $this->salonSetup();
        $salon->update(['service_categories' => ['Coafor', 'Unghii']]);
        $salon->services()->create([
            'name' => 'Tuns dama',
            'type' => 'Coafor',
            'price' => '120',
            'duration' => 45,
        ]);
        $salon->services()->create([
            'name' => 'Manichiura',
            'type' => 'Unghii',
            'price' => '90',
            'duration' => 60,
        ]);

        $this->actingAs($user)
            ->from('/dashboard/services')
            ->put('/services/categories', ['categories' => ['Hair', 'Unghii']])
            ->assertRedirect('/dashboard/services');

        $this->assertSame(['Hair', 'Unghii'], $salon->refresh()->service_categories);
        $this->assertSame('Hair', $salon->services()->where('name', 'Tuns dama')->value('type'));
        $this->assertSame('Unghii', $salon->services()->where('name', 'Manichiura')->value('type'));
    }

    public function test_service_category_case_only_rename_updates_existing_services(): void
    {
        [$salon, , $user] = $this->salonSetup();
        $salon->update(['service_categories' => ['PACKAGES']]);
        $salon->services()->create([
            'name' => 'Fresh Face Package',
            'type' => 'PACKAGES',
            'price' => '420',
            'duration' => 60,
        ]);

        $this->actingAs($user)
            ->from('/dashboard/services')
            ->put('/services/categories', ['categories' => ['Packages']])
            ->assertRedirect('/dashboard/services');

        $this->assertSame(['Packages'], $salon->refresh()->service_categories);
        $this->assertSame('Packages', $salon->services()->where('name', 'Fresh Face Package')->value('type'));
    }

    public function test_service_category_deletion_clears_only_removed_category_services(): void
    {
        [$salon, , $user] = $this->salonSetup();
        $salon->update(['service_categories' => ['Coafor', 'Unghii']]);
        $salon->services()->create([
            'name' => 'Tuns dama',
            'type' => 'Coafor',
            'price' => '120',
            'duration' => 45,
        ]);
        $salon->services()->create([
            'name' => 'Manichiura',
            'type' => 'Unghii',
            'price' => '90',
            'duration' => 60,
        ]);

        $this->actingAs($user)
            ->from('/dashboard/services')
            ->put('/services/categories', ['categories' => ['Unghii']])
            ->assertRedirect('/dashboard/services');

        $this->assertSame(['Unghii'], $salon->refresh()->service_categories);
        $this->assertNull($salon->services()->where('name', 'Tuns dama')->value('type'));
        $this->assertSame('Unghii', $salon->services()->where('name', 'Manichiura')->value('type'));
    }

    public function test_services_dashboard_source_contains_ai_import_button_and_modal(): void
    {
        $source = file_get_contents(resource_path('js/Pages/Dashboard/Index.tsx'));

        $this->assertStringContainsString("t('addService')", $source);
        $this->assertStringContainsString("t('importWithAi')", $source);
        $this->assertStringContainsString('<Sparkles className="h-4 w-4"', $source);
        $this->assertStringContainsString('ServiceImageImportModal', $source);
        $this->assertStringContainsString('/dashboard/services/import-image/analyze', $source);
        $this->assertStringContainsString('/dashboard/services/import-image/store', $source);
    }

    private function salonSetup(): array
    {
        $user = User::factory()->create();
        $salon = Salon::query()->create([
            'user_id' => $user->id,
            'name' => 'YouGo Studio',
        ]);
        $location = $salon->locations()->create([
            'name' => 'Central',
            'address' => 'Main Street',
        ]);

        return [$salon, $location, $user];
    }
}
