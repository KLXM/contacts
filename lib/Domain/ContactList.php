<?php

declare(strict_types=1);

namespace KLXM\Contacts\Domain;

/**
 * Eine Liste innerhalb eines Adressbuchs. Kontakte können in beliebig vielen Listen stehen.
 * In Kontakte-Apps erscheint sie als Gruppe.
 */
final class ContactList
{
    public ?int $id = null;
    public int $bookId = 0;
    public string $uid = '';
    public string $uri = '';
    public string $etag = '';
    public string $name = '';
    public ?\DateTimeImmutable $updatedAt = null;

    /** @var list<int> Kontakt-IDs */
    public array $memberIds = [];

    /** @var list<string> UIDs aus einer App, deren Kontakt noch nicht angekommen ist */
    public array $pendingMembers = [];
}
