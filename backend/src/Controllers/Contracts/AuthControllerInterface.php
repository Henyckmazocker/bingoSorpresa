<?php
namespace App\Controllers\Contracts;

interface AuthControllerInterface
{
    public function login(array $inputData);
    public function logout();
    public function checkAuth();
    public function me(?int $userId);
    public function deleteAccount(?int $userId, array $inputData);
}
