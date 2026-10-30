<?php

namespace Boutique\Controller;

use Boutique\Catalog\Product;
use Boutique\Catalog\ProductRepository;
use Boutique\Routing\Route;

final readonly class ProductController
{
    public function __construct(private ProductRepository $products = new ProductRepository()) {}

    #[Route('/products')]
    public function list(): array
    {
        return array_map(fn(Product $p): array => ['id' => $p->id, 'name' => $p->name], $this->products->all());
    }

    #[Route('/products/{id}')]
    public function show(int $id): ?array
    {
        $product = $this->products->find($id);

        return $product instanceof Product ? ['id' => $product->id, 'name' => $product->name, 'price' => $product->priceCents] : null;
    }
}
