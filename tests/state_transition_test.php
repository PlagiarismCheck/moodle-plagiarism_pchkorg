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
 * Tests for how polling moves records through the queue.
 *
 * @package   plagiarism_pchkorg
 * @category  test
 * @copyright PlagiarismCheck.org, https://plagiarismcheck.org/
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace plagiarism_pchkorg;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/plagiarism/pchkorg/lib.php');
require_once(__DIR__ . '/fixtures/fake_transport.php');

/**
 * Tests the state machine on plagiarism_pchkorg_files.
 *
 * @package   plagiarism_pchkorg
 * @category  test
 * @copyright PlagiarismCheck.org, https://plagiarismcheck.org/
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class state_transition_test extends \advanced_testcase {
    /**
     * Configuration caches outlive the per-test database reset.
     */
    protected function setUp(): void {
        parent::setUp();
        \plagiarism_pchkorg_config_model::reset_caches();
    }

    /**
     * A finished report moves the record to checked and stores both scores.
     */
    public function test_sent_becomes_checked(): void {
        global $DB;
        $this->resetAfterTest(true);

        $id = $this->queue_sent_record();
        $this->poll($this->checked_response('33.00', '4.50'));

        $record = $DB->get_record('plagiarism_pchkorg_files', ['id' => $id]);
        $this->assertEquals(\plagiarism_pchkorg_state::LOCAL_CHECKED, $record->state);
        $this->assertEquals(33.0, (float) $record->score);
        $this->assertEquals(4.5, (float) $record->scoreai);
        $this->assertEquals(4321, $record->reportid);
    }

    /**
     * AI detection is optional, so a checked text may have no AI score. This
     * used to dereference null and fatal on PHP 8.
     */
    public function test_checked_without_ai_report(): void {
        global $DB;
        $this->resetAfterTest(true);

        $id = $this->queue_sent_record();
        $this->poll($this->checked_response('12.00', null));

        $record = $DB->get_record('plagiarism_pchkorg_files', ['id' => $id]);
        $this->assertEquals(\plagiarism_pchkorg_state::LOCAL_CHECKED, $record->state);
        $this->assertEquals(12.0, (float) $record->score);
        $this->assertNull($record->scoreai);
    }

    /**
     * States the service treats as final but unusable fail the record, and do
     * so without a report present.
     */
    public function test_unusable_remote_states_fail_the_record(): void {
        global $DB;
        $this->resetAfterTest(true);

        $unusable = [
            'failed' => \plagiarism_pchkorg_state::REMOTE_FAILED,
            'temp failed' => \plagiarism_pchkorg_state::REMOTE_TEMP_FAILED,
            'dropped' => \plagiarism_pchkorg_state::REMOTE_DROPPED,
            'erased' => \plagiarism_pchkorg_state::REMOTE_ERASED,
        ];

        foreach ($unusable as $label => $remotestate) {
            $id = $this->queue_sent_record();
            $this->poll(json_encode([
                'success' => true,
                'data' => ['state' => $remotestate, 'report' => null, 'ai_report' => null],
            ]));

            $record = $DB->get_record('plagiarism_pchkorg_files', ['id' => $id]);
            $this->assertEquals(
                \plagiarism_pchkorg_state::LOCAL_ERROR,
                $record->state,
                $label
            );
        }
    }

    /**
     * A check still running leaves the record alone so it is polled again.
     */
    public function test_unfinished_check_stays_sent(): void {
        global $DB;
        $this->resetAfterTest(true);

        $id = $this->queue_sent_record();
        $this->poll(json_encode([
            'success' => true,
            'data' => ['state' => \plagiarism_pchkorg_state::REMOTE_SUBMITTED, 'report' => null],
        ]));

        $record = $DB->get_record('plagiarism_pchkorg_files', ['id' => $id]);
        $this->assertEquals(\plagiarism_pchkorg_state::LOCAL_SENT, $record->state);
    }

    /**
     * An unreachable service leaves the record alone rather than failing it.
     */
    public function test_unreachable_service_stays_sent(): void {
        global $DB;
        $this->resetAfterTest(true);

        $id = $this->queue_sent_record();
        $this->poll('');

        $record = $DB->get_record('plagiarism_pchkorg_files', ['id' => $id]);
        $this->assertEquals(\plagiarism_pchkorg_state::LOCAL_SENT, $record->state);
    }

    /**
     * Nothing is polled while the plugin is switched off site-wide.
     */
    public function test_disabled_plugin_polls_nothing(): void {
        $this->resetAfterTest(true);

        $this->queue_sent_record(false);
        $transport = new \plagiarism_pchkorg_fake_transport([$this->checked_response('1.00', null)]);
        \plagiarism_pchkorg_config_model::reset_caches();

        $plugin = new \plagiarism_plugin_pchkorg();
        $plugin->cron_update_reports($this->provider($transport));

        $this->assertSame(0, $transport->request_count());
    }

    /**
     * Insert one record in the sent state, waiting for a report.
     *
     * @param bool $enabled Whether the plugin is switched on site-wide.
     * @return int The record id.
     */
    private function queue_sent_record($enabled = true) {
        global $DB;

        if (!$DB->record_exists('plagiarism_pchkorg_config', ['cm' => 0, 'name' => 'pchkorg_use'])) {
            $config = new \stdClass();
            $config->cm = 0;
            $config->name = 'pchkorg_use';
            $config->value = $enabled ? '1' : '0';
            $DB->insert_record('plagiarism_pchkorg_config', $config);
        }

        $record = new \stdClass();
        $record->cm = 1;
        $record->userid = 2;
        $record->fileid = null;
        $record->itemid = 3;
        $record->signature = sha1('content');
        $record->textid = 9999;
        $record->state = \plagiarism_pchkorg_state::LOCAL_SENT;
        $record->score = 0;
        $record->attempt = 0;
        $record->created_at = time();

        \plagiarism_pchkorg_config_model::reset_caches();

        return $DB->insert_record('plagiarism_pchkorg_files', $record);
    }

    /**
     * Run one polling pass against a canned service response.
     *
     * @param string $response
     * @return void
     */
    private function poll($response) {
        $transport = new \plagiarism_pchkorg_fake_transport([$response]);
        $plugin = new \plagiarism_plugin_pchkorg();
        $plugin->cron_update_reports($this->provider($transport));
    }

    /**
     * Build a provider wired to the fake transport.
     *
     * @param \plagiarism_pchkorg_fake_transport $transport
     * @return \plagiarism_pchkorg_api_provider
     */
    private function provider($transport) {
        return new \plagiarism_pchkorg_api_provider(
            'personal-token',
            'https://service.example',
            $transport
        );
    }

    /**
     * A finished-report response, optionally carrying an AI score.
     *
     * @param string $percent
     * @param string|null $aipercent
     * @return string
     */
    private function checked_response($percent, $aipercent) {
        return json_encode([
            'success' => true,
            'data' => [
                'state' => \plagiarism_pchkorg_state::REMOTE_CHECKED,
                'report' => ['id' => 4321, 'percent' => $percent],
                'ai_report' => null === $aipercent ? null : ['processed_percent' => $aipercent],
            ],
        ]);
    }
}
