<?php
defined('MOODLE_INTERNAL') || die();

/**
 * Upgrade script for the SmartVideo plugin.
 *
 * @param int $oldversion The version we are upgrading from.
 * @return bool True on success.
 */
function xmldb_smartvideo_upgrade($oldversion) {
    global $DB;

    $dbman = $DB->get_manager(); // Loads the database manager

    if ($oldversion < 2025011412) {

        // Define table smartvideo to be modified.
        $table = new xmldb_table('smartvideo');

        // 1. Add 'summary' field
        // Field parameters: Name, Type, Precision, Unsigned, Not Null, Sequence, Default, Previous Field
        $field = new xmldb_field('summary', XMLDB_TYPE_TEXT, null, null, null, null, null, 'timemodified');
        
        // Only add if it doesn't exist yet
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // 2. Add 'summaryformat' field
        $field2 = new xmldb_field('summaryformat', XMLDB_TYPE_INTEGER, '4', null, XMLDB_NOTNULL, null, '1', 'summary');
        
        if (!$dbman->field_exists($table, $field2)) {
            $dbman->add_field($table, $field2);
        }

        // Savepoint reached.
        upgrade_mod_savepoint(true, 2025020400, 'smartvideo');
    }

    return true;
}