<?php

declare(strict_types=1);

$content = rex_markdown::factory()->parse((string) rex_file::get(rex_path::addon('contacts', 'README.md')), [rex_markdown::SOFT_LINE_BREAKS => false]);

echo '<article class="contacts-docs">' . $content . '</article>';
