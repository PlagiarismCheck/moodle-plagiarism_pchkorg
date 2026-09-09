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

namespace plagiarism_pchkorg;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/plagiarism/pchkorg/lib.php');
require_once(__DIR__ . '/fixtures/fake_stored_file.php');

use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;
use plagiarism_pchkorg\privacy\provider;

/**
 * Privacy API implementation.
 *
 * @package    plagiarism_pchkorg
 * @category   test
 * @copyright  PlagiarismCheck.org, https://plagiarismcheck.org/
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class privacy_test extends \core_privacy\tests\provider_testcase {
    /** @var \stdClass Course the activity lives in. */
    private $course;

    /** @var \stdClass Assignment course module. */
    private $cm;

    /** @var \context_module Context of that activity. */
    private $context;

    /** @var \stdClass A user with a check. */
    private $student;

    /** @var \stdClass Another user with a check in the same activity. */
    private $otherstudent;

    /**
     * A course, an assignment and two students who each submitted once.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);

        $generator = $this->getDataGenerator();
        $this->course = $generator->create_course();
        $assign = $generator->create_module('assign', ['course' => $this->course->id]);
        $this->cm = get_coursemodule_from_instance('assign', $assign->id);
        $this->context = \context_module::instance($this->cm->id);

        $this->student = $generator->create_user(['email' => 'student@example.com']);
        $this->otherstudent = $generator->create_user(['email' => 'other@example.com']);
        $generator->enrol_user($this->student->id, $this->course->id, 'student');
        $generator->enrol_user($this->otherstudent->id, $this->course->id, 'student');

        $this->add_check($this->student->id);
        $this->add_check($this->otherstudent->id);
    }

    /**
     * Every table holding personal data is declared, including the one keyed by
     * email, which was previously missing.
     */
    public function test_metadata_declares_every_table(): void {
        $collection = provider::get_metadata(new \core_privacy\local\metadata\collection('plagiarism_pchkorg'));

        $tables = [];
        foreach ($collection->get_collection() as $item) {
            $tables[] = $item->get_name();
        }

        $this->assertContains('plagiarism_pchkorg_files', $tables);
        $this->assertContains('plagiarism_pchkorg_config', $tables);
        $this->assertContains('plagiarism_pchkorg_users', $tables);
    }

    /**
     * The message column stores the reason a check failed, and a locally built
     * reason names the submitter's email address, so it is personal data and has
     * to be declared like every other column.
     */
    public function test_metadata_declares_the_message_column(): void {
        $collection = provider::get_metadata(new \core_privacy\local\metadata\collection('plagiarism_pchkorg'));

        $fields = [];
        foreach ($collection->get_collection() as $item) {
            if ('plagiarism_pchkorg_files' === $item->get_name()) {
                $fields = \array_keys($item->get_privacy_fields());
            }
        }

        $this->assertContains('message', $fields);
    }

    /**
     * The name and the email both leave Moodle, so both are declared.
     */
    public function test_metadata_declares_transmitted_identity(): void {
        $collection = provider::get_metadata(new \core_privacy\local\metadata\collection('plagiarism_pchkorg'));

        $fields = [];
        foreach ($collection->get_collection() as $item) {
            if ('plagiarism_pchkorg' === $item->get_name()) {
                $fields = \array_keys($item->get_privacy_fields());
            }
        }

        $this->assertContains('email', $fields);
        $this->assertContains('name', $fields);
    }

    /**
     * Course-scoped access sends a course and a role alongside the hashed
     * email, so both are declared like everything else that leaves Moodle.
     */
    public function test_metadata_declares_course_access_fields(): void {
        $collection = provider::get_metadata(new \core_privacy\local\metadata\collection('plagiarism_pchkorg'));

        $fields = [];
        foreach ($collection->get_collection() as $item) {
            if ('plagiarism_pchkorg' === $item->get_name()) {
                $fields = \array_keys($item->get_privacy_fields());
            }
        }

        $this->assertContains('course_id', $fields);
        $this->assertContains('role', $fields);
    }

    /**
     * There is still exactly one external location, not one per feature.
     */
    public function test_metadata_declares_one_external_location(): void {
        $collection = provider::get_metadata(new \core_privacy\local\metadata\collection('plagiarism_pchkorg'));

        $external = 0;
        foreach ($collection->get_collection() as $item) {
            if ($item instanceof \core_privacy\local\metadata\types\external_location) {
                $external++;
            }
        }

        $this->assertSame(1, $external);
    }

    /**
     * The contexts reported are the activity's own module context.
     *
     * This is the regression test for the cm-id-as-context-id bug: the old
     * query handed course module ids to add_from_sql(), which wants context
     * ids, so it reported whatever context happened to share that number.
     */
    public function test_get_contexts_returns_the_module_context(): void {
        $contextlist = provider::get_contexts_for_userid($this->student->id);

        $this->assertCount(1, $contextlist);
        $this->assertEquals($this->context->id, $contextlist->get_contextids()[0]);
    }

    /**
     * A user with no checks is in no contexts.
     */
    public function test_get_contexts_is_empty_without_data(): void {
        $stranger = $this->getDataGenerator()->create_user();

        $this->assertCount(0, provider::get_contexts_for_userid($stranger->id));
    }

    /**
     * Everyone with a check in the activity is listed.
     */
    public function test_get_users_in_context(): void {
        $userlist = new userlist($this->context, 'plagiarism_pchkorg');
        provider::get_users_in_context($userlist);

        $userids = $userlist->get_userids();
        $this->assertCount(2, $userids);
        $this->assertContains((int) $this->student->id, $userids);
        $this->assertContains((int) $this->otherstudent->id, $userids);
    }

    /**
     * The export carries the scores, not just the fact that a check happened.
     */
    public function test_export_returns_the_checks(): void {
        $this->export_context_data_for_user($this->student->id, $this->context, 'plagiarism_pchkorg');
        $writer = writer::with_context($this->context);

        $this->assertTrue($writer->has_any_data());

        $data = $writer->get_data([get_string('pluginname', 'plagiarism_pchkorg')]);
        // scoreai is a decimal column, so compare the values, not their spelling.
        $this->assertCount(1, $data->checks);
        $this->assertEquals(42, $data->checks[0]->similarity_score);
        $this->assertEquals(7, $data->checks[0]->ai_score);
        $this->assertSame(
            get_string('privacy:state:checked', 'plagiarism_pchkorg'),
            $data->checks[0]->status
        );
    }

    /**
     * A stored failure reason is part of the subject's data, so an export
     * request returns it rather than only the fact that the check failed.
     */
    public function test_export_returns_the_failure_reason(): void {
        global $DB;

        $DB->set_field(
            'plagiarism_pchkorg_files',
            'message',
            'This file type is not supported.',
            ['userid' => $this->student->id]
        );

        $this->export_context_data_for_user($this->student->id, $this->context, 'plagiarism_pchkorg');
        $data = writer::with_context($this->context)->get_data([get_string('pluginname', 'plagiarism_pchkorg')]);

        $this->assertCount(1, $data->checks);
        $this->assertSame('This file type is not supported.', $data->checks[0]->error_message);
    }

    /**
     * A check that did not fail carries no reason, and the export does not
     * invent an empty one.
     */
    public function test_export_omits_an_absent_failure_reason(): void {
        $this->export_context_data_for_user($this->student->id, $this->context, 'plagiarism_pchkorg');
        $data = writer::with_context($this->context)->get_data([get_string('pluginname', 'plagiarism_pchkorg')]);

        $this->assertCount(1, $data->checks);
        // Uses property_exists() rather than assertObjectNotHasAttribute(),
        // which is deprecated in PHPUnit 9 and removed in 10, while CI spans
        // Moodle 3.9 to 5.2.
        $this->assertFalse(property_exists($data->checks[0], 'error_message'));
    }

    /**
     * One user's export never contains another user's scores.
     */
    public function test_export_is_limited_to_the_requested_user(): void {
        global $DB;

        $DB->set_field('plagiarism_pchkorg_files', 'score', 99, ['userid' => $this->otherstudent->id]);

        $this->export_context_data_for_user($this->student->id, $this->context, 'plagiarism_pchkorg');
        $data = writer::with_context($this->context)->get_data([get_string('pluginname', 'plagiarism_pchkorg')]);

        $this->assertCount(1, $data->checks);
        $this->assertNotEquals('99', (string) $data->checks[0]->similarity_score);
    }

    /**
     * The subsystem export puts the check beside the submission, under the
     * subcontext the activity is exporting into.
     */
    public function test_plagiarism_subsystem_export(): void {
        $subcontext = ['Submission'];

        provider::export_plagiarism_user_data(
            $this->student->id,
            $this->context,
            $subcontext,
            ['cmid' => $this->cm->id, 'userid' => $this->student->id, 'content' => 'An essay.']
        );

        $path = \array_merge($subcontext, [get_string('pluginname', 'plagiarism_pchkorg')]);
        $data = writer::with_context($this->context)->get_data($path);

        $this->assertCount(1, $data->checks);
        $this->assertEquals(42, $data->checks[0]->similarity_score);
    }

    /**
     * A file submission exports the check of that file, not of a text one.
     */
    public function test_plagiarism_subsystem_export_matches_the_file(): void {
        global $DB;

        $fileid = 4242;
        $DB->set_field('plagiarism_pchkorg_files', 'fileid', $fileid, ['userid' => $this->student->id]);
        $DB->set_field('plagiarism_pchkorg_files', 'score', 55, ['userid' => $this->student->id]);

        $file = new \plagiarism_pchkorg_fake_stored_file($fileid);

        provider::export_plagiarism_user_data(
            $this->student->id,
            $this->context,
            ['Submission'],
            ['cmid' => $this->cm->id, 'userid' => $this->student->id, 'file' => $file]
        );

        $path = ['Submission', get_string('pluginname', 'plagiarism_pchkorg')];
        $data = writer::with_context($this->context)->get_data($path);

        $this->assertCount(1, $data->checks);
        $this->assertEquals(55, $data->checks[0]->similarity_score);
    }

    /**
     * Deleting the activity removes every check in it.
     */
    public function test_delete_for_all_users_in_context(): void {
        global $DB;

        provider::delete_data_for_all_users_in_context($this->context);

        $this->assertSame(0, $DB->count_records('plagiarism_pchkorg_files', ['cm' => $this->cm->id]));
    }

    /**
     * Deleting one user leaves everybody else alone.
     */
    public function test_delete_for_user(): void {
        global $DB;

        $contextlist = new approved_contextlist(
            $this->student,
            'plagiarism_pchkorg',
            [$this->context->id]
        );
        provider::delete_data_for_user($contextlist);

        $this->assertSame(0, $DB->count_records('plagiarism_pchkorg_files', ['userid' => $this->student->id]));
        $this->assertSame(1, $DB->count_records('plagiarism_pchkorg_files', ['userid' => $this->otherstudent->id]));
    }

    /**
     * The record of a service registration is keyed by email, and goes too.
     */
    public function test_delete_for_user_forgets_the_service_registration(): void {
        global $DB;

        $DB->insert_record('plagiarism_pchkorg_users', (object) ['email' => $this->student->email]);
        $DB->insert_record('plagiarism_pchkorg_users', (object) ['email' => $this->otherstudent->email]);

        $contextlist = new approved_contextlist(
            $this->student,
            'plagiarism_pchkorg',
            [$this->context->id]
        );
        provider::delete_data_for_user($contextlist);

        $this->assertFalse($DB->record_exists('plagiarism_pchkorg_users', ['email' => $this->student->email]));
        $this->assertTrue($DB->record_exists('plagiarism_pchkorg_users', ['email' => $this->otherstudent->email]));
    }

    /**
     * A registration recorded under a namespaced username is forgotten too.
     *
     * A course-scoped site bookkeeps people by username rather than address, and
     * a site that has switched modes may hold either for the same person, so
     * deletion cannot key off whichever the current setting implies.
     */
    public function test_delete_for_user_forgets_a_username_registration(): void {
        global $DB;

        $login = \plagiarism_pchkorg_service_login::namespaced($this->student);
        $otherlogin = \plagiarism_pchkorg_service_login::namespaced($this->otherstudent);
        $DB->insert_record('plagiarism_pchkorg_users', (object) ['email' => $login]);
        $DB->insert_record('plagiarism_pchkorg_users', (object) ['email' => $otherlogin]);

        $contextlist = new approved_contextlist(
            $this->student,
            'plagiarism_pchkorg',
            [$this->context->id]
        );
        provider::delete_data_for_user($contextlist);

        $this->assertFalse($DB->record_exists('plagiarism_pchkorg_users', ['email' => $login]));
        $this->assertTrue($DB->record_exists('plagiarism_pchkorg_users', ['email' => $otherlogin]));
    }

    /**
     * Both identifiers go at once, for a person who has been registered twice.
     */
    public function test_delete_for_user_forgets_both_identifiers(): void {
        global $DB;

        $login = \plagiarism_pchkorg_service_login::namespaced($this->student);
        $DB->insert_record('plagiarism_pchkorg_users', (object) ['email' => $this->student->email]);
        $DB->insert_record('plagiarism_pchkorg_users', (object) ['email' => $login]);

        $contextlist = new approved_contextlist(
            $this->student,
            'plagiarism_pchkorg',
            [$this->context->id]
        );
        provider::delete_data_for_user($contextlist);

        $this->assertFalse($DB->record_exists('plagiarism_pchkorg_users', ['email' => $this->student->email]));
        $this->assertFalse($DB->record_exists('plagiarism_pchkorg_users', ['email' => $login]));
    }

    /**
     * Several users can be deleted from one activity at once.
     */
    public function test_delete_for_users(): void {
        global $DB;

        $userlist = new approved_userlist(
            $this->context,
            'plagiarism_pchkorg',
            [$this->student->id]
        );
        provider::delete_data_for_users($userlist);

        $this->assertSame(0, $DB->count_records('plagiarism_pchkorg_files', ['userid' => $this->student->id]));
        $this->assertSame(1, $DB->count_records('plagiarism_pchkorg_files', ['userid' => $this->otherstudent->id]));
    }

    /**
     * The plagiarism subsystem reaches the same deletion, so an activity that
     * deletes a submission takes its check with it.
     */
    public function test_plagiarism_subsystem_deletion(): void {
        global $DB;

        provider::delete_plagiarism_for_user($this->student->id, $this->context);
        $this->assertSame(0, $DB->count_records('plagiarism_pchkorg_files', ['userid' => $this->student->id]));

        provider::delete_plagiarism_for_context($this->context);
        $this->assertSame(0, $DB->count_records('plagiarism_pchkorg_files', ['cm' => $this->cm->id]));
    }

    /**
     * A context that is not an activity is ignored rather than fataling.
     */
    public function test_non_module_contexts_are_ignored(): void {
        global $DB;

        provider::delete_plagiarism_for_context(\context_course::instance($this->course->id));
        provider::delete_plagiarism_for_user($this->student->id, \context_system::instance());

        $this->assertSame(2, $DB->count_records('plagiarism_pchkorg_files', ['cm' => $this->cm->id]));
    }

    /**
     * A checked online-text submission for a user.
     *
     * @param int $userid
     * @return int Record id.
     */
    private function add_check($userid) {
        global $DB;

        $record = new \stdClass();
        $record->cm = $this->cm->id;
        $record->userid = $userid;
        $record->fileid = null;
        $record->itemid = 1;
        $record->state = \plagiarism_pchkorg_state::LOCAL_CHECKED;
        $record->score = 42;
        $record->scoreai = 7;
        $record->textid = 1234;
        $record->reportid = 5678;
        $record->signature = sha1('content ' . $userid);
        $record->attempt = 0;
        $record->created_at = time();

        return $DB->insert_record('plagiarism_pchkorg_files', $record);
    }
}
