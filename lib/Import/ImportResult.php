<?php

declare(strict_types=1);

namespace KLXM\Contacts\Import;

final class ImportResult
{
    public int $created = 0;
    public int $updated = 0;
    public int $lists = 0;

    /** @var list<string> */
    public array $errors = [];
}
