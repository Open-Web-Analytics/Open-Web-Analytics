<?php
namespace OWA\Module\Base\Entity;
//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Licensed under GPL v2.0 http://www.gnu.org/copyleft/gpl.html
//
/**
 * old id -> new id, for the 32-bit to 63-bit dimension id migration.
 *
 * DELIBERATELY NOT IN THE MODULE'S ENTITY LIST, so a fresh installation does not
 * create it. A new installation derives 63-bit ids from its first event and will
 * never run the migration, so the table would sit there empty forever. The
 * command creates it on demand instead; entityFactory() resolves this class
 * through the compat alias map rather than the entity list, so nothing else
 * changes.
 *
 * It exists so the fact-side rewrite can be a JOIN rather than millions of
 * individual statements.
 *
 * A REAL table, not a MySQL TEMPORARY one, because a temporary table is scoped
 * to one connection and would not survive a killed run -- and surviving one is
 * the point. Between runs it is the only record linking an old id to its new
 * one: once the old dimension rows have been dropped it cannot be rebuilt, since
 * rebuilding reads dimension rows and by then they are all wide.
 *
 * It lives exactly as long as it is needed. The command drops it at the moment
 * completion is verified, in the same step that clears use_32bit_hash, because
 * from then on there is nothing to resume and a further run refuses on the flag.
 * Keeping it beyond that would leave a six-figure row count behind on a large
 * installation to answer a question nobody asks.
 *
 * Keyed by (entity, old_id) rather than old_id alone, because two dimensions can
 * legitimately hold the same crc32 value for different content.
 */
class GuidMap extends \OWA\Core\Entity {

    function __construct() {

        $this->setTableName('guid_map');

        // Surrogate key: the natural key is (entity, old_id), but entity is a
        // string and old_id repeats across dimensions, so neither is a primary
        // key on its own.
        $id = new \OWA\Module\Base\Classes\DbColumn( 'id', OWA_DTD_BIGINT );
        $id->setPrimaryKey();
        $this->setProperty($id);

        // Which dimension this row belongs to, e.g. 'base.document'. Two
        // dimensions can legitimately hold the same crc32 value for different
        // content, so the map has to be scoped.
        $entity = new \OWA\Module\Base\Classes\DbColumn( 'entity', OWA_DTD_VARCHAR255 );
        $entity->setIndex();
        $this->setProperty($entity);

        // The 32-bit crc32 id being replaced. Indexed because the fact-side
        // rewrite joins on it.
        $old_id = new \OWA\Module\Base\Classes\DbColumn( 'old_id', OWA_DTD_BIGINT );
        $old_id->setIndex();
        $this->setProperty($old_id);

        $new_id = new \OWA\Module\Base\Classes\DbColumn( 'new_id', OWA_DTD_BIGINT );
        $this->setProperty($new_id);
    }
}
