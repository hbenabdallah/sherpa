<?php

namespace Boutique\Catalog;

final class ProductRepository
{
    /** @var array<int, Product> */
    private array $products;

    public function __construct()
    {
        $this->products = [
            1 => new Product(1, 'Carnet', 450),
            2 => new Product(2, 'Stylo plume', 2990),
            3 => new Product(3, 'Encre bleue', 790),
        ];
    }

    public function find(int $id): ?Product
    {
        return $this->products[$id] ?? null;
    }

    /** @return Product[] */
    public function all(): array
    {
        return array_values($this->products);
    }
}
