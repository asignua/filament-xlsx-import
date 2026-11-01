<?php

declare(strict_types=1);

namespace Workbench\Database\Seeders;

use Illuminate\Database\Seeder;
use Workbench\App\Models\Category;
use Workbench\App\Models\Contact;
use Workbench\App\Models\Product;
use Workbench\App\Models\Tag;
use Workbench\App\Models\User;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        User::query()->firstOrCreate(['email' => 'emma@example.com'], ['name' => 'Emma', 'password' => 'password', 'is_active' => true]);

        $fruit = new Category;
        $fruit->forceFill(['name' => 'Fruit'])->save();
        $bakery = new Category;
        $bakery->forceFill(['name' => 'Bakery'])->save();

        foreach (['organic', 'seasonal', 'sale'] as $name) {
            $tag = new Tag;
            $tag->forceFill(['name' => $name])->save();
        }

        foreach ([
            ['APL-001', 'Apple, Gala', '1.20', 120, 'active', $fruit],
            ['PER-002', 'Pear, Conference', '1.80', 40, 'active', $fruit],
            ['BRD-010', 'Sourdough loaf', '3.50', 15, 'draft', $bakery],
        ] as [$sku, $name, $price, $stock, $status, $category]) {
            $product = new Product;
            $product->category()->associate($category);
            $product->forceFill(['sku' => $sku, 'name' => $name, 'price' => $price, 'stock' => $stock, 'status' => $status])->save();
        }

        $contact = new Contact;
        $contact->forceFill(['name' => 'Ada Lovelace', 'email' => 'ada@example.com', 'city' => 'Lviv'])->save();
    }
}
