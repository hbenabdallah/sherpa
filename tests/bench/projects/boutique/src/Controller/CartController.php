<?php

namespace Boutique\Controller;

use Boutique\Routing\Route;
use Boutique\User\UserRepo;

final class CartController
{
    public function __construct(private readonly UserRepo $users = new UserRepo()) {}

    #[Route('/cart/items', method: 'POST')]
    public function add(int $userId, int $productId, int $quantity): array
    {
        $user = $this->users->find($userId);

        return ['user' => $user?->email, 'product' => $productId, 'quantity' => $quantity];
    }
}
