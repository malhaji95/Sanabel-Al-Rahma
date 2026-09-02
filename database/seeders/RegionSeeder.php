<?php

namespace Database\Seeders;

use App\Models\Region;
use Illuminate\Database\Seeder;

/**
 * Region names are data, never code. This seeder only puts the first rows in
 * place; an admin adds and edits regions from the panel afterwards.
 */
class RegionSeeder extends Seeder
{
    public function run(): void
    {
        $tree = json_decode(file_get_contents(database_path('data/regions.json')), true);

        $this->insert($tree, null);
    }

    private function insert(array $nodes, ?int $parentId): void
    {
        foreach ($nodes as $node) {
            $region = Region::updateOrCreate(
                ['name_ar' => $node['name_ar'], 'parent_id' => $parentId],
                ['type' => $node['type'], 'is_active' => true],
            );

            if (! empty($node['children'])) {
                $this->insert($node['children'], $region->id);
            }
        }
    }
}
