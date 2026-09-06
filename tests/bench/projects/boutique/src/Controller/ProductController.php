<?php

namespace Boutique\Controller;

use Boutique\Catalog\ProductRepository;
use Boutique\Routing\Route;

final class ProductController
{
    public function __construct(private readonly ProductRepository $products = new ProductRepository()) {}

    #[Route('/products')]
    public function list(): array
    {
        return array_map(fn($p) => ['id' => $p->id, 'name' => $p->name], $this->products->all());
    }

    #[Route('/products/{id}')]
    public function show(int $id): ?array
    {
        $product = $this->products->find($id);

        return $product === null ? null : ['id' => $product->id, 'name' => $product->name, 'price' => $product->priceCents];
    }
}
