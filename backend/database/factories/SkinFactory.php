<?php

namespace Database\Factories;

use App\Models\Skin;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Skin>
 */
class SkinFactory extends Factory
{
    protected $model = Skin::class;

    public function definition(): array
    {
        return [
            'name' => fake()->words(2, true),
            'description' => fake()->sentence(),
            'config' => [
                'brand' => 'acme',
                'appName' => 'Acme Workspace',
                'logo' => '/storage/skins/logo.svg',
                'favicon' => '/storage/skins/favicon.ico',
                'theme' => [
                    'palette' => [
                        'primary' => '#0052CC',
                        'secondary' => '#7C3AED',
                        'mode' => 'system',
                        'modes' => ['light', 'dark'],
                    ],
                    'typography' => [
                        'fontFamily' => 'Inter, Helvetica, Arial, sans-serif',
                        'scale' => 1.0,
                    ],
                    'shape' => ['borderRadius' => 12],
                    'layout' => 'comfortable',
                ],
                'menu' => ['order' => [], 'hidden' => []],
                'i18n' => ['appTitle' => 'Acme Workspace', 'labels' => []],
                'customCss' => '',
            ],
            'logo_path' => null,
            'favicon_path' => null,
            'is_active' => false,
            'created_by' => User::factory(),
        ];
    }
}
