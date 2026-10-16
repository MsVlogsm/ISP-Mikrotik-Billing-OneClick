<?php
namespace App\Contracts;
use App\Models\NetworkInventoryDevice;
interface OltReadOnlyAdapter {
    public function key(): string;
    public function supports(NetworkInventoryDevice $device): bool;
    public function read(NetworkInventoryDevice $device): array;
}
