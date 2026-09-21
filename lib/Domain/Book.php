<?php

declare(strict_types=1);

namespace KLXM\Contacts\Domain;

final class Book
{
    public ?int $id = null;
    public string $name = '';
    public string $slug = '';
    public string $color = '#3788d8';
    public ?string $description = null;
    public bool $davEnabled = true;
    public int $priority = 0;
    public int $syncToken = 1;
}
