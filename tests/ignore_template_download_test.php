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
require_once($CFG->dirroot . '/plagiarism/pchkorg/classes/ignore_template_download.php');
require_once(__DIR__ . '/fixtures/fake_transport.php');

/**
 * The part of the template download proxy that is not the request itself.
 *
 * ignoretemplate.php keeps require_login(), require_sesskey() and send_file();
 * everything it decides is here, and is tested here.
 *
 * @package    plagiarism_pchkorg
 * @category   test
 * @copyright  PlagiarismCheck.org, https://plagiarismcheck.org/
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class ignore_template_download_test extends \advanced_testcase {
    /** @var \stdClass Course the activity lives in. */
    private $course;

    /** @var \stdClass Assignment course module. */
    private $cm;

    /** @var \stdClass A user who may edit activities. */
    private $teacher;

    /**
     * A course, an assignment, an editing teacher and the feature switched on.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
        \plagiarism_pchkorg_config_model::reset_caches();

        $generator = $this->getDataGenerator();
        $this->course = $generator->create_course();
        $assign = $generator->create_module('assign', ['course' => $this->course->id]);
        $this->cm = get_coursemodule_from_instance('assign', $assign->id);

        $this->teacher = $generator->create_user();
        $generator->enrol_user($this->teacher->id, $this->course->id, 'editingteacher');
        $this->setUser($this->teacher);

        $this->set_site_config('pchkorg_enable_ignore_templates', '1');
        $this->set_site_config('pchkorg_token', 'G-institutional-token');
        \plagiarism_pchkorg_config_model::reset_caches();
    }

    /**
     * The original bytes are returned under the name and type the service
     * reported for that template.
     */
    public function test_resolve_returns_the_original_file(): void {
        $transport = new \plagiarism_pchkorg_fake_transport([
            $this->templatelist([
                ['id' => 7, 'filename' => 'rubric.docx', 'mime_type' => 'application/msword'],
            ]),
            'RAW-DOCX-BYTES',
        ]);

        $file = \plagiarism_pchkorg_ignore_template_download::resolve(
            $this->cm,
            7,
            $this->teacher,
            $this->configmodel(),
            $this->provider($transport)
        );

        $this->assertSame('RAW-DOCX-BYTES', $file->content);
        $this->assertSame('rubric.docx', $file->filename);
        $this->assertSame('application/msword', $file->mime);
    }

    /**
     * The key sent with both calls names the activity in the URL, so a
     * template of another activity is never even asked for by name alone.
     */
    public function test_resolve_asks_for_the_activity_key(): void {
        $transport = new \plagiarism_pchkorg_fake_transport([
            $this->templatelist([['id' => 7, 'filename' => 'rubric.docx']]),
            'RAW',
        ]);

        \plagiarism_pchkorg_ignore_template_download::resolve(
            $this->cm,
            7,
            $this->teacher,
            $this->configmodel(),
            $this->provider($transport)
        );

        $key = \plagiarism_pchkorg_assignment_key::for_cmid($this->cm->id);
        $this->assertSame($key, $transport->request(0)['params']['assignment_key']);
        $this->assertSame($key, $transport->request(1)['params']['assignment_key']);
        $this->assertSame(7, $transport->request(1)['params']['template_id']);
    }

    /**
     * A template the service will not hand over becomes its own error, so the
     * teacher is told what happened rather than downloading an error body.
     */
    public function test_resolve_reports_a_refused_download(): void {
        $transport = new \plagiarism_pchkorg_fake_transport([
            $this->templatelist([]),
            json_encode(['success' => false, 'code' => 'template_not_found']),
        ]);

        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage(
            get_string('pchkorg_ignore_template_error_template_not_found', 'plagiarism_pchkorg')
        );

        \plagiarism_pchkorg_ignore_template_download::resolve(
            $this->cm,
            7,
            $this->teacher,
            $this->configmodel(),
            $this->provider($transport)
        );
    }

    /**
     * A template of another activity is refused by the service; the plugin
     * reports that rather than inventing an answer of its own.
     */
    public function test_resolve_refuses_a_template_of_another_activity(): void {
        $transport = new \plagiarism_pchkorg_fake_transport([
            $this->templatelist([['id' => 7, 'filename' => 'ours.docx']]),
            json_encode(['success' => false, 'code' => 'template_assignment_mismatch']),
        ]);

        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage(
            get_string('pchkorg_ignore_template_error_template_assignment_mismatch', 'plagiarism_pchkorg')
        );

        \plagiarism_pchkorg_ignore_template_download::resolve(
            $this->cm,
            99,
            $this->teacher,
            $this->configmodel(),
            $this->provider($transport)
        );
    }

    /**
     * An unreachable service is reported as such, not as a missing template.
     */
    public function test_resolve_reports_an_unreachable_service(): void {
        $transport = new \plagiarism_pchkorg_fake_transport([$this->templatelist([]), '']);

        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage(
            get_string('pchkorg_ignore_template_error_unreachable', 'plagiarism_pchkorg')
        );

        \plagiarism_pchkorg_ignore_template_download::resolve(
            $this->cm,
            7,
            $this->teacher,
            $this->configmodel(),
            $this->provider($transport)
        );
    }

    /**
     * A template the list does not name still downloads, under a name derived
     * from its id.
     */
    public function test_resolve_falls_back_to_a_generated_filename(): void {
        $transport = new \plagiarism_pchkorg_fake_transport([
            $this->templatelist([['id' => 7]]),
            'RAW',
        ]);

        $file = \plagiarism_pchkorg_ignore_template_download::resolve(
            $this->cm,
            7,
            $this->teacher,
            $this->configmodel(),
            $this->provider($transport)
        );

        $this->assertSame('ignore-template-7', $file->filename);
        $this->assertSame(
            \plagiarism_pchkorg_ignore_template_download::DEFAULT_MIME,
            $file->mime
        );
    }

    /**
     * The name the service reports is not trusted as a filename. Core keeps
     * the dots and drops the separators, which is what stops it naming a path.
     */
    public function test_resolve_cleans_the_filename(): void {
        $transport = new \plagiarism_pchkorg_fake_transport([
            $this->templatelist([['id' => 7, 'filename' => '../../etc/passwd']]),
            'RAW',
        ]);

        $file = \plagiarism_pchkorg_ignore_template_download::resolve(
            $this->cm,
            7,
            $this->teacher,
            $this->configmodel(),
            $this->provider($transport)
        );

        $this->assertStringNotContainsString('/', $file->filename);
        $this->assertStringNotContainsString('\\', $file->filename);
        $this->assertStringNotContainsString('passwd', dirname('/tmp/' . $file->filename));
    }

    /**
     * A student may not read an activity's templates.
     */
    public function test_resolve_refuses_a_user_without_the_capability(): void {
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $this->course->id, 'student');
        $this->setUser($student);

        $transport = new \plagiarism_pchkorg_fake_transport();

        $this->expectException(\required_capability_exception::class);

        try {
            \plagiarism_pchkorg_ignore_template_download::resolve(
                $this->cm,
                7,
                $student,
                $this->configmodel(),
                $this->provider($transport)
            );
        } finally {
            $this->assertSame(0, $transport->request_count(), 'refused before any request');
        }
    }

    /**
     * With the feature off there is nothing to download, even for a teacher.
     */
    public function test_resolve_refuses_while_the_feature_is_off(): void {
        global $DB;

        $DB->set_field(
            'plagiarism_pchkorg_config',
            'value',
            '0',
            ['cm' => 0, 'name' => 'pchkorg_enable_ignore_templates']
        );
        \plagiarism_pchkorg_config_model::reset_caches();

        $transport = new \plagiarism_pchkorg_fake_transport();

        $this->expectException(\moodle_exception::class);

        try {
            \plagiarism_pchkorg_ignore_template_download::resolve(
                $this->cm,
                7,
                $this->teacher,
                $this->configmodel(),
                $this->provider($transport)
            );
        } finally {
            $this->assertSame(0, $transport->request_count(), 'refused before any request');
        }
    }

    /**
     * An API provider wired to a fake transport.
     *
     * @param \plagiarism_pchkorg_fake_transport $transport
     * @return \plagiarism_pchkorg_api_provider
     */
    private function provider($transport) {
        return new \plagiarism_pchkorg_api_provider(
            'G-institutional-token',
            'https://service.example',
            $transport
        );
    }

    /**
     * Config model.
     *
     * @return \plagiarism_pchkorg_config_model
     */
    private function configmodel() {
        return new \plagiarism_pchkorg_config_model();
    }

    /**
     * A template list response.
     *
     * @param array $templates
     * @return string
     */
    private function templatelist(array $templates) {
        return json_encode([
            'success' => true,
            'data' => ['templates' => $templates],
        ]);
    }

    /**
     * Set site config.
     *
     * @param string $name
     * @param string $value
     */
    private function set_site_config($name, $value) {
        global $DB;

        $record = new \stdClass();
        $record->cm = 0;
        $record->name = $name;
        $record->value = $value;
        $DB->insert_record('plagiarism_pchkorg_config', $record);
    }
}
