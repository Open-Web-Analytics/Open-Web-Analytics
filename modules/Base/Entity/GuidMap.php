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
 * Exists so the fact-side rewrite can be a JOIN rather than millions of
 * individual statements. It is scratch: the migration builds it, uses it, and
 * leaves it in place as a record of what was changed, which is the only thing
 * that can answer "what did this id used to be" after the fact.
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
