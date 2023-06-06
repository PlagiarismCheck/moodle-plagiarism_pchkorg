<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * @package   plagiarism_pchkorg
 * @category  plagiarism
 * @copyright PlagiarismCheck.org, https://plagiarismcheck.org/
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

function xmldb_plagiarism_pchkorg_upgrade($oldversion) {
    global $DB;

    $dbman = $DB->get_manager();


    if ($oldversion < 2021072801) {

        $table = new xmldb_table('plagiarism_pchkorg_files');

        $field1 = new xmldb_field('signature', XMLDB_TYPE_CHAR, '40', null, null, null, null, null);
        $field1->setComment('Signature');

        $field2 = new xmldb_field('attempt', XMLDB_TYPE_INTEGER, '5', null, null, null, 0, null);
        $field2->setComment('Sending attempts');

        $field3 = new xmldb_field('itemid', XMLDB_TYPE_INTEGER, '10', null, null, null, null, null);
        $field3->setComment('ID of file');

        if (!$dbman->field_exists($table, $field1)) {
            $dbman->add_field($table, $field1);
        }

        if (!$dbman->field_exists($table, $field2)) {
            $dbman->add_field($table, $field2);
        }

        if (!$dbman->field_exists($table, $field3)) {
            $dbman->add_field($table, $field3);
        }

        upgrade_plugin_savepoint(true, 2021072801, 'plagiarism', 'pchkorg');
    }

    if ($oldversion < 2023060713) {
        $table = new xmldb_table('plagiarism_pchkorg_files');

        $field1 = new xmldb_field('scoreai', XMLDB_TYPE_NUMBER, '4,2', XMLDB_UNSIGNED, null, null, null, null);
        $field1->setComment('AI score');

        if (!$dbman->field_exists($table, $field1)) {
            $dbman->add_field($table, $field1);
        }
    }

    return true;
}
