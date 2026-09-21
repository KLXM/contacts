<?php

declare(strict_types=1);

namespace KLXM\Contacts;

use rex;
use rex_sql;
use rex_sql_column;
use rex_sql_index;
use rex_sql_table;

/**
 * Tabellen des Addons. Ein Kontakt gehört zu genau einem Adressbuch und zu beliebig vielen Listen darin.
 *
 * @internal
 */
final class Schema
{
    public const array TABLES = ['book', 'contact', 'item', 'list', 'list_member', 'change'];

    public static function table(string $name): string
    {
        return rex::getTable('contacts_' . $name);
    }

    public static function ensure(): void
    {
        rex_sql_table::get(self::table('book'))
            ->ensurePrimaryIdColumn()
            ->ensureColumn(new rex_sql_column('name', 'varchar(191)'))
            ->ensureColumn(new rex_sql_column('slug', 'varchar(191)'))
            ->ensureColumn(new rex_sql_column('color', 'varchar(9)', false, '#3788d8'))
            ->ensureColumn(new rex_sql_column('description', 'text', true))
            ->ensureColumn(new rex_sql_column('dav_enabled', 'tinyint(1)', false, '1'))
            ->ensureColumn(new rex_sql_column('priority', 'int(10)', false, '0'))
            ->ensureColumn(new rex_sql_column('sync_token', 'int(10) unsigned', false, '1'))
            ->ensureGlobalColumns()
            ->ensureIndex(new rex_sql_index('slug', ['slug'], rex_sql_index::UNIQUE))
            ->ensure();

        rex_sql_table::get(self::table('contact'))
            ->ensurePrimaryIdColumn()
            ->ensureColumn(new rex_sql_column('book_id', 'int(10) unsigned'))
            ->ensureColumn(new rex_sql_column('uid', 'varchar(191)'))
            ->ensureColumn(new rex_sql_column('uri', 'varchar(191)'))
            ->ensureColumn(new rex_sql_column('etag', 'varchar(32)'))
            ->ensureColumn(new rex_sql_column('is_company', 'tinyint(1)', false, '0'))
            ->ensureColumn(new rex_sql_column('prefix', 'varchar(191)', false, ''))
            ->ensureColumn(new rex_sql_column('first_name', 'varchar(191)', false, ''))
            ->ensureColumn(new rex_sql_column('middle_name', 'varchar(191)', false, ''))
            ->ensureColumn(new rex_sql_column('last_name', 'varchar(191)', false, ''))
            ->ensureColumn(new rex_sql_column('suffix', 'varchar(191)', false, ''))
            ->ensureColumn(new rex_sql_column('nickname', 'varchar(191)', false, ''))
            ->ensureColumn(new rex_sql_column('organization', 'varchar(191)', false, ''))
            ->ensureColumn(new rex_sql_column('department', 'varchar(191)', false, ''))
            ->ensureColumn(new rex_sql_column('job_title', 'varchar(191)', false, ''))
            ->ensureColumn(new rex_sql_column('birthday', 'varchar(10)', true))
            ->ensureColumn(new rex_sql_column('note', 'text', true))
            ->ensureColumn(new rex_sql_column('photo_media', 'varchar(191)', true))
            ->ensureColumn(new rex_sql_column('photo_data', 'mediumblob', true))
            ->ensureColumn(new rex_sql_column('photo_type', 'varchar(32)', true))
            ->ensureColumn(new rex_sql_column('display_name', 'varchar(191)', false, ''))
            ->ensureColumn(new rex_sql_column('sort_name', 'varchar(191)', false, ''))
            ->ensureColumn(new rex_sql_column('extra_vcard', 'mediumtext', true))
            ->ensureColumn(new rex_sql_column('is_public', 'tinyint(1)', false, '0'))
            ->ensureColumn(new rex_sql_column('public_fields', 'varchar(191)', false, ''))
            ->ensureGlobalColumns()
            ->ensureIndex(new rex_sql_index('book_uri', ['book_id', 'uri'], rex_sql_index::UNIQUE))
            ->ensureIndex(new rex_sql_index('book_uid', ['book_id', 'uid']))
            ->ensureIndex(new rex_sql_index('book_sort', ['book_id', 'sort_name']))
            ->ensure();

        rex_sql_table::get(self::table('item'))
            ->ensurePrimaryIdColumn()
            ->ensureColumn(new rex_sql_column('contact_id', 'int(10) unsigned'))
            ->ensureColumn(new rex_sql_column('kind', 'varchar(16)'))
            ->ensureColumn(new rex_sql_column('label', 'varchar(191)', false, ''))
            ->ensureColumn(new rex_sql_column('value', 'text'))
            ->ensureColumn(new rex_sql_column('data', 'text', true))
            ->ensureColumn(new rex_sql_column('priority', 'int(10)', false, '0'))
            ->ensureColumn(new rex_sql_column('is_public', 'tinyint(1)', false, '0'))
            ->ensureIndex(new rex_sql_index('contact', ['contact_id', 'priority']))
            ->ensure();

        rex_sql_table::get(self::table('list'))
            ->ensurePrimaryIdColumn()
            ->ensureColumn(new rex_sql_column('book_id', 'int(10) unsigned'))
            ->ensureColumn(new rex_sql_column('uid', 'varchar(191)'))
            ->ensureColumn(new rex_sql_column('uri', 'varchar(191)'))
            ->ensureColumn(new rex_sql_column('etag', 'varchar(32)'))
            ->ensureColumn(new rex_sql_column('name', 'varchar(191)'))
            ->ensureColumn(new rex_sql_column('pending_members', 'text', true))
            ->ensureGlobalColumns()
            ->ensureIndex(new rex_sql_index('book_uri', ['book_id', 'uri'], rex_sql_index::UNIQUE))
            ->ensure();

        rex_sql_table::get(self::table('list_member'))
            ->ensureColumn(new rex_sql_column('list_id', 'int(10) unsigned'))
            ->ensureColumn(new rex_sql_column('contact_id', 'int(10) unsigned'))
            ->setPrimaryKey(['list_id', 'contact_id'])
            ->ensureIndex(new rex_sql_index('contact', ['contact_id']))
            ->ensure();

        rex_sql_table::get(self::table('change'))
            ->ensurePrimaryIdColumn()
            ->ensureColumn(new rex_sql_column('book_id', 'int(10) unsigned'))
            ->ensureColumn(new rex_sql_column('uri', 'varchar(191)'))
            ->ensureColumn(new rex_sql_column('operation', 'tinyint(1)'))
            ->ensureColumn(new rex_sql_column('sync_token', 'int(10) unsigned'))
            ->ensureIndex(new rex_sql_index('book_token', ['book_id', 'sync_token']))
            ->ensure();
    }

    public static function drop(): void
    {
        foreach (self::TABLES as $name) {
            rex_sql::factory()->setQuery('DROP TABLE IF EXISTS ' . rex_sql::factory()->escapeIdentifier(self::table($name)));
        }
    }
}
