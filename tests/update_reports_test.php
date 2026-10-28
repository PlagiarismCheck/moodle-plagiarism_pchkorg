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
 * Tests for which sent records update_reports polls, and what a poll that
 * cannot be answered does to them.
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
 * Tests the update_reports queue.
 *
 * @package   plagiarism_pchkorg
 * @category  test
 * @copyright PlagiarismCheck.org, https://plagiarismcheck.org/
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class update_reports_test extends \advanced_testcase {
    /** @var string The HTML page an older service sent for any 404. */
    const HTML_404 = '<!DOCTYPE html><html><head><title>Not Found</title></head><body>Not Found</body></html>';

    /** @var \progress_trace_buffer Collects what the run reports. */
    private $trace;

    /**
     * Configuration caches outlive the per-test database reset.
     */
    protected function setUp(): void {
        parent::setUp();
        \plagiarism_pchkorg_config_model::reset_caches();
        \plagiarism_pchkorg_api_provider::reset_caches();
        \plagiarism_pchkorg_service_login::reset_cache();
        $this->trace = new \progress_trace_buffer(new \text_progress_trace(), false);
    }

    /**
     * The outage this release fixes: the twenty oldest texts were deleted on
     * the service, which answered them with an HTML 404, and the poll kept
     * asking about those twenty for ever. Results behind them never arrived.
     *
     * One run now reaches the live text, and the live text's answer proves
     * the token works, so the dead ones fail instead of being asked again.
     */
    public function test_dead_texts_do_not_starve_the_queue(): void {
        global $DB;
        $this->resetAfterTest(true);

        $dead = [];
        for ($i = 0; $i < 20; $i++) {
            $dead[] = $this->queue_sent_record(1000 + $i);
        }
        $live = $this->queue_sent_record(2000);

        $transport = new \plagiarism_pchkorg_fake_transport([], function ($method, $url) {
            return false !== strpos($url, '/2000/')
                ? $this->checked_response('10.00')
                : ['status' => 404, 'body' => self::HTML_404];
        });
        $this->run_poll($transport, null, 'G-group-token');

        $this->assertEquals(21, $transport->request_count());
        $this->assertEquals(
            \plagiarism_pchkorg_state::LOCAL_CHECKED,
            $DB->get_field('plagiarism_pchkorg_files', 'state', ['id' => $live])
        );
        foreach ($dead as $id) {
            $record = $DB->get_record('plagiarism_pchkorg_files', ['id' => $id]);
            $this->assertEquals(\plagiarism_pchkorg_state::LOCAL_ERROR, $record->state);
            $this->assertSame(\plagiarism_plugin_pchkorg::NOT_FOUND_MESSAGE, $record->message);
        }
    }

    /**
     * The newest submission is asked about first, so its result arrives
     * within a minute however long the backlog behind it.
     */
    public function test_newest_record_is_polled_first(): void {
        $this->resetAfterTest(true);

        $this->queue_sent_record(101);
        $this->queue_sent_record(103);
        $this->queue_sent_record(102);

        $transport = new \plagiarism_pchkorg_fake_transport([$this->pending_response()]);
        $this->run_poll($transport, 0);

        $this->assertSame(1, $transport->request_count());
        $this->assertStringContainsString('/api/v1/text/102', $transport->request(0)['url']);
    }

    /**
     * A text that stays in processing goes to the back once asked about, so
     * it cannot keep an older record from ever being polled.
     */
    public function test_unresolved_record_goes_to_the_back(): void {
        global $DB;
        $this->resetAfterTest(true);

        $older = $this->queue_sent_record(201);
        $newer = $this->queue_sent_record(202);

        $first = new \plagiarism_pchkorg_fake_transport([$this->pending_response()]);
        $this->run_poll($first, 0);
        $this->assertStringContainsString('/api/v1/text/202', $first->request(0)['url']);
        $this->assertGreaterThan(0, (int) $DB->get_field('plagiarism_pchkorg_files', 'polled_at', ['id' => $newer]));

        $second = new \plagiarism_pchkorg_fake_transport([$this->checked_response('5.00')]);
        $this->run_poll($second, 0);
        $this->assertStringContainsString('/api/v1/text/201', $second->request(0)['url']);
        $this->assertEquals(
            \plagiarism_pchkorg_state::LOCAL_CHECKED,
            $DB->get_field('plagiarism_pchkorg_files', 'state', ['id' => $older])
        );
    }

    /**
     * A run stops once its time is up, and leaves the rest for the next run.
     */
    public function test_time_budget_ends_the_run(): void {
        $this->resetAfterTest(true);

        for ($i = 0; $i < 3; $i++) {
            $this->queue_sent_record(300 + $i);
        }

        $transport = new \plagiarism_pchkorg_fake_transport([], function () {
            return $this->pending_response();
        });
        $this->run_poll($transport, 0);

        $this->assertSame(1, $transport->request_count());
    }

    /**
     * No run asks about more records than the batch size.
     */
    public function test_batch_size_caps_the_run(): void {
        $this->resetAfterTest(true);

        for ($i = 0; $i <= \plagiarism_plugin_pchkorg::POLL_BATCH_SIZE; $i++) {
            $this->queue_sent_record(10000 + $i);
        }

        $transport = new \plagiarism_pchkorg_fake_transport([], function () {
            return $this->pending_response();
        });
        $this->run_poll($transport, 3600);

        $this->assertSame(\plagiarism_plugin_pchkorg::POLL_BATCH_SIZE, $transport->request_count());
    }

    /**
     * The service answers a missing text with a 404 in JSON. That alone is
     * proof enough: the record fails, with the reason stored.
     */
    public function test_json_404_fails_the_record(): void {
        global $DB;
        $this->resetAfterTest(true);

        $id = $this->queue_sent_record(400);
        $this->run_poll(new \plagiarism_pchkorg_fake_transport([
            ['status' => 404, 'body' => json_encode(['success' => false, 'message' => 'Text not found'])],
        ]));

        $record = $DB->get_record('plagiarism_pchkorg_files', ['id' => $id]);
        $this->assertEquals(\plagiarism_pchkorg_state::LOCAL_ERROR, $record->state);
        $this->assertSame(\plagiarism_plugin_pchkorg::NOT_FOUND_MESSAGE, $record->message);
    }

    /**
     * An older service also sent its HTML 404 for an unknown or disabled group
     * token. With nothing in the run to show the token works, a 404 could be
     * about the token, so nothing is failed: a revoked token must not wipe out
     * the whole queue.
     */
    public function test_html_404_without_proof_leaves_records_sent(): void {
        global $DB;
        $this->resetAfterTest(true);

        $ids = [$this->queue_sent_record(501), $this->queue_sent_record(502)];
        $this->run_poll(new \plagiarism_pchkorg_fake_transport([], function () {
            return ['status' => 404, 'body' => self::HTML_404];
        }), null, 'G-group-token');

        foreach ($ids as $id) {
            $record = $DB->get_record('plagiarism_pchkorg_files', ['id' => $id]);
            $this->assertEquals(\plagiarism_pchkorg_state::LOCAL_SENT, $record->state);
            $this->assertGreaterThan(0, (int) $record->polled_at);
        }
        $this->assertStringContainsString('left as sent', $this->trace->get_buffer());
    }

    /**
     * A refused token stops the run at once: every further request would be
     * refused too. Nothing is failed.
     */
    public function test_rejected_token_stops_the_run(): void {
        global $DB;
        $this->resetAfterTest(true);

        for ($i = 0; $i < 3; $i++) {
            $this->queue_sent_record(600 + $i);
        }

        $transport = new \plagiarism_pchkorg_fake_transport([], function () {
            return ['status' => 401, 'body' => json_encode(['success' => false])];
        });
        $this->run_poll($transport, null, 'G-group-token');

        $this->assertSame(1, $transport->request_count());
        $this->assertSame(3, $DB->count_records('plagiarism_pchkorg_files', [
            'state' => \plagiarism_pchkorg_state::LOCAL_SENT,
        ]));
        $this->assertStringContainsString('refused the API token', $this->trace->get_buffer());
    }

    /**
     * Answers that say nothing about the text leave it to be asked again,
     * and are reported with the status that came back.
     */
    public function test_unusable_answers_leave_records_sent(): void {
        global $DB;
        $this->resetAfterTest(true);

        $answers = [
            'forbidden' => ['status' => 403, 'body' => json_encode(['success' => false])],
            'server error' => ['status' => 500, 'body' => 'Internal Server Error'],
            'no response' => ['status' => 0, 'body' => 'Operation timed out'],
            'not json' => '<html>maintenance</html>',
        ];

        foreach ($answers as $label => $answer) {
            $id = $this->queue_sent_record(700);
            $this->run_poll(new \plagiarism_pchkorg_fake_transport([$answer]));

            $this->assertEquals(
                \plagiarism_pchkorg_state::LOCAL_SENT,
                $DB->get_field('plagiarism_pchkorg_files', 'state', ['id' => $id]),
                $label
            );
            $DB->delete_records('plagiarism_pchkorg_files', ['id' => $id]);
        }
        $this->assertStringContainsString('(HTTP 500)', $this->trace->get_buffer());
    }

    /**
     * The service now answers a deleted text with the final erased state.
     */
    public function test_erased_text_fails_with_a_reason(): void {
        global $DB;
        $this->resetAfterTest(true);

        $id = $this->queue_sent_record(800);
        $this->run_poll(new \plagiarism_pchkorg_fake_transport([json_encode([
            'success' => true,
            'data' => [
                'id' => 800,
                'state' => \plagiarism_pchkorg_state::REMOTE_ERASED,
                'is_deleted' => true,
                'report' => null,
                'ai_report' => null,
            ],
        ])]));

        $record = $DB->get_record('plagiarism_pchkorg_files', ['id' => $id]);
        $this->assertEquals(\plagiarism_pchkorg_state::LOCAL_ERROR, $record->state);
        $this->assertSame('Text was deleted on PlagiarismCheck.org', $record->message);
    }

    /**
     * A refresh puts a checked record back to sent. If its text has since
     * gone from the service, the record keeps the result it already had
     * rather than turning into a failed check.
     */
    public function test_refreshed_record_keeps_its_result_when_the_text_is_gone(): void {
        global $DB;
        $this->resetAfterTest(true);

        $id = $this->queue_sent_record(900);
        $DB->update_record('plagiarism_pchkorg_files', (object) [
            'id' => $id,
            'reportid' => 4321,
            'score' => 27,
            'scoreai' => 3.5,
        ]);

        $this->run_poll(new \plagiarism_pchkorg_fake_transport([
            ['status' => 404, 'body' => json_encode(['success' => false])],
        ]));

        $record = $DB->get_record('plagiarism_pchkorg_files', ['id' => $id]);
        $this->assertEquals(\plagiarism_pchkorg_state::LOCAL_CHECKED, $record->state);
        $this->assertEquals(27.0, (float) $record->score);
        $this->assertEquals(3.5, (float) $record->scoreai);
        $this->assertEquals(4321, $record->reportid);
    }

    /**
     * An AI score of 100 did not fit the old number(4,2) column.
     */
    public function test_ai_score_of_100_is_stored(): void {
        global $DB;
        $this->resetAfterTest(true);

        $id = $this->queue_sent_record(1100);
        $this->run_poll(new \plagiarism_pchkorg_fake_transport([$this->checked_response('40.00', '100.00')]));

        $record = $DB->get_record('plagiarism_pchkorg_files', ['id' => $id]);
        $this->assertEquals(\plagiarism_pchkorg_state::LOCAL_CHECKED, $record->state);
        $this->assertEquals(100.0, (float) $record->scoreai);
    }

    /**
     * A score of 100 arrives in whatever form the service's JSON gives it:
     * a decimal string as Doctrine reads it, a bare integer string, or a JSON
     * number. Every form must store for both scores and for both kinds of
     * token, as must the largest value the service's own decimal(5,2) holds.
     */
    public function test_full_scores_in_every_format_are_stored(): void {
        global $DB;
        $this->resetAfterTest(true);

        $formats = ['100.00', '100', 100, 100.0, '999.99'];
        $textid = 1500;

        foreach (['personal-token', 'G-group-token'] as $token) {
            foreach ($formats as $value) {
                $label = $token . ' ' . var_export($value, true);
                $id = $this->queue_sent_record(++$textid);

                $this->run_poll(
                    new \plagiarism_pchkorg_fake_transport([$this->checked_response($value, $value)]),
                    null,
                    $token
                );

                $record = $DB->get_record('plagiarism_pchkorg_files', ['id' => $id]);
                $this->assertEquals(\plagiarism_pchkorg_state::LOCAL_CHECKED, $record->state, $label);
                $this->assertEquals((float) $value, (float) $record->score, $label);
                $this->assertEquals((float) $value, (float) $record->scoreai, $label);
            }
        }
        $this->assertStringNotContainsString('could not store', $this->trace->get_buffer());
    }

    /**
     * A record whose result cannot be written is reported and sent to the
     * back; the run goes on to the records after it.
     */
    public function test_one_unwritable_record_does_not_stop_the_run(): void {
        global $DB;
        $this->resetAfterTest(true);

        $older = $this->queue_sent_record(1201);
        $newer = $this->queue_sent_record(1202);

        // Out of range for number(5,2), so the write throws on every supported
        // database (Moodle runs MySQL and MariaDB in strict mode).
        $this->run_poll(new \plagiarism_pchkorg_fake_transport([
            $this->checked_response('1.00', '1000.00'),
            $this->checked_response('2.00'),
        ]));

        $newrecord = $DB->get_record('plagiarism_pchkorg_files', ['id' => $newer]);
        $this->assertEquals(\plagiarism_pchkorg_state::LOCAL_SENT, $newrecord->state);
        $this->assertGreaterThan(0, (int) $newrecord->polled_at);
        $this->assertEquals(
            \plagiarism_pchkorg_state::LOCAL_CHECKED,
            $DB->get_field('plagiarism_pchkorg_files', 'state', ['id' => $older])
        );
        $this->assertStringContainsString('Text 1202: could not store the result', $this->trace->get_buffer());
    }

    /**
     * A poll that throws is reported and does not stop the run.
     */
    public function test_a_throwing_poll_does_not_stop_the_run(): void {
        global $DB;
        $this->resetAfterTest(true);

        $older = $this->queue_sent_record(1301);
        $this->queue_sent_record(1302);

        $transport = new \plagiarism_pchkorg_fake_transport([], function ($method, $url) {
            if (false !== strpos($url, '/1302')) {
                throw new \RuntimeException('connection reset');
            }
            return $this->checked_response('3.00');
        });
        $this->run_poll($transport);

        $this->assertEquals(
            \plagiarism_pchkorg_state::LOCAL_CHECKED,
            $DB->get_field('plagiarism_pchkorg_files', 'state', ['id' => $older])
        );
        $this->assertStringContainsString('Text 1302: poll failed: connection reset', $this->trace->get_buffer());
    }

    /**
     * Every run ends with a summary, so a run that resolves nothing is
     * visible in the task log rather than looking healthy.
     */
    public function test_run_is_summarised(): void {
        $this->resetAfterTest(true);

        $this->queue_sent_record(1401);
        $this->queue_sent_record(1402);
        $this->run_poll(new \plagiarism_pchkorg_fake_transport([
            $this->pending_response(),
            $this->checked_response('9.00'),
        ]));

        $this->assertStringContainsString(
            'Polled 2 of 2 sent records',
            $this->trace->get_buffer()
        );
        $this->assertStringContainsString('1 checked, 0 failed, 1 still processing, 0 unresolved', $this->trace->get_buffer());
    }

    /**
     * Insert one record in the sent state.
     *
     * @param int $textid
     * @return int The record id.
     */
    private function queue_sent_record($textid) {
        global $DB;

        if (!$DB->record_exists('plagiarism_pchkorg_config', ['cm' => 0, 'name' => 'pchkorg_use'])) {
            $DB->insert_record('plagiarism_pchkorg_config', (object) [
                'cm' => 0,
                'name' => 'pchkorg_use',
                'value' => '1',
            ]);
            \plagiarism_pchkorg_config_model::reset_caches();
        }

        return $DB->insert_record('plagiarism_pchkorg_files', (object) [
            'cm' => 1,
            'userid' => 2,
            'fileid' => null,
            'itemid' => 3,
            'signature' => sha1('content' . $textid),
            'textid' => $textid,
            'state' => \plagiarism_pchkorg_state::LOCAL_SENT,
            'score' => 0,
            'attempt' => 0,
            'created_at' => time(),
        ]);
    }

    /**
     * Run update_reports once.
     *
     * @param \plagiarism_pchkorg_fake_transport $transport
     * @param int|null $timebudget
     * @param string $token
     * @return void
     */
    private function run_poll($transport, $timebudget = null, $token = 'personal-token') {
        $provider = new \plagiarism_pchkorg_api_provider($token, 'https://service.example', $transport);
        $plugin = new \plagiarism_plugin_pchkorg();
        $plugin->cron_update_reports($provider, $this->trace, $timebudget);
    }

    /**
     * A response for a text the service is still checking.
     *
     * @return string
     */
    private function pending_response() {
        return json_encode([
            'success' => true,
            'data' => ['state' => \plagiarism_pchkorg_state::REMOTE_SUBMITTED, 'report' => null],
        ]);
    }

    /**
     * A finished-report response.
     *
     * @param string $percent
     * @param string|null $aipercent
     * @return string
     */
    private function checked_response($percent, $aipercent = null) {
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
