<?php

use Boutique\User\UserRepo;

function test_user_repo_finds_known_users(): void
{
    $users = new UserRepo();

    assert_same('alice@example.test', $users->find(1)?->email);
    assert_same(null, $users->find(99));
}
