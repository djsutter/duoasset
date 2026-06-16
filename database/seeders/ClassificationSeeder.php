<?php

namespace Database\Seeders;

use App\Models\Industry;
use App\Models\Sector;
use App\Models\SubIndustry;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class ClassificationSeeder extends Seeder
{
    /**
     * Sector => Industry => [SubIndustries] classification tree.
     *
     * Order matters: sectors, industries and sub-industries are persisted
     * with sort_order matching their position in this array.
     *
     * @var array<string, array<string, array<int, string>>>
     */
    private array $tree = [
        'Technology' => [
            'Software' => [
                'AI Infrastructure',
                'Enterprise Software',
                'Database Software',
            ],
            'Semiconductors' => [
                'GPU Manufacturers',
                'AI Accelerators',
            ],
            'Cybersecurity' => [],
            'Cloud Computing' => [],
            'Data Infrastructure' => [
                'Networking',
            ],
        ],
        'Industrials' => [
            'Aerospace & Defense' => [
                'Commercial Aerospace',
                'Defense Contractors',
            ],
            'Construction' => [],
            'Transportation' => [
                'Rail Transportation',
            ],
        ],
        'Energy' => [
            'Oil & Gas' => [
                'Exploration & Production',
            ],
            'Midstream' => [
                'Pipelines',
            ],
            'Oilfield Services' => [
                'Drilling Services',
            ],
            'Uranium' => [
                'Uranium Producers',
            ],
        ],
        'Materials' => [
            'Copper' => [
                'Copper Producers',
                'Copper Developers',
            ],
            'Gold' => [],
            'Silver' => [],
            'Lithium' => [
                'Lithium Producers',
            ],
            'Rare Earths' => [
                'Rare Earth Producers',
            ],
            'Fertilizers' => [],
            'Steel' => [],
        ],
        'Healthcare' => [
            'Biotechnology' => [
                'Gene Therapy',
                'Antibody Therapeutics',
            ],
            'Pharmaceuticals' => [],
            'Medical Devices' => [
                'Diagnostics',
            ],
        ],
        'Financials' => [
            'Banks' => [
                'Regional Banks',
            ],
            'Insurance' => [
                'Life Insurance',
            ],
            'Asset Management' => [
                'Wealth Management',
            ],
        ],
        'Consumer' => [
            'Retail' => [],
            'E-Commerce' => [],
            'Restaurants' => [],
        ],
        'Telecommunications' => [
            'Wireless' => [],
            'Fiber' => [],
            'Data Centers' => [],
        ],
        'Utilities' => [
            'Power Generation' => [],
            'Renewable Energy' => [],
        ],
        'Real Estate' => [
            'REITs' => [
                'Industrial REITs',
                'Data Center REITs',
            ],
        ],
    ];

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $sectorOrder = 0;

        foreach ($this->tree as $sectorName => $industries) {
            $sector = Sector::updateOrCreate(
                ['slug' => Str::slug($sectorName)],
                [
                    'name' => $sectorName,
                    'sort_order' => $sectorOrder++,
                ],
            );

            $industryOrder = 0;

            foreach ($industries as $industryName => $subIndustries) {
                $industry = Industry::updateOrCreate(
                    ['slug' => Str::slug($industryName)],
                    [
                        'sector_id' => $sector->id,
                        'name' => $industryName,
                        'sort_order' => $industryOrder++,
                    ],
                );

                $subOrder = 0;

                foreach ($subIndustries as $subName) {
                    SubIndustry::updateOrCreate(
                        ['slug' => Str::slug($subName)],
                        [
                            'industry_id' => $industry->id,
                            'name' => $subName,
                            'sort_order' => $subOrder++,
                        ],
                    );
                }
            }
        }
    }
}
