<?php

namespace Boutique\Controller;

use Boutique\Billing\Invoice;
use Boutique\Billing\InvoiceLine;
use Boutique\Catalog\ProductRepository;
use Boutique\Routing\Route;
use Boutique\User\UserRepo;

final class InvoiceController
{
    public function __construct(
        private readonly UserRepo $users = new UserRepo(),
        private readonly ProductRepository $products = new ProductRepository(),
    ) {}

    #[Route('/invoices/preview', method: 'POST')]
    public function preview(int $userId, array $items): array
    {
        $user = $this->users->find($userId);
        $invoice = new Invoice($user?->country ?? 'FR');

        foreach ($items as $productId => $quantity) {
            $product = $this->products->find($productId);
            if ($product !== null) {
                $invoice->add(new InvoiceLine($product, $quantity));
            }
        }

        return ['subtotal' => $invoice->subtotal(), 'total' => $invoice->total()];
    }
}
