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

/**
 * Tests for locating submission content per activity type.
 *
 * @package    plagiarism_pchkorg
 * @category   test
 * @copyright  PlagiarismCheck.org, https://plagiarismcheck.org/
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class resolvers_test extends \advanced_testcase {
    /**
     * Each supported activity has a resolver, and nothing else does.
     */
    public function test_resolver_selection(): void {
        $this->assertInstanceOf(
            '\plagiarism_pchkorg_assign_resolver',
            \plagiarism_pchkorg_sender::resolver_for('assign')
        );
        $this->assertInstanceOf(
            '\plagiarism_pchkorg_quiz_resolver',
            \plagiarism_pchkorg_sender::resolver_for('quiz')
        );
        $this->assertInstanceOf(
            '\plagiarism_pchkorg_forum_resolver',
            \plagiarism_pchkorg_sender::resolver_for('forum')
        );
        $this->assertNull(\plagiarism_pchkorg_sender::resolver_for('workshop'));
        $this->assertNull(\plagiarism_pchkorg_sender::resolver_for(''));
    }

    /**
     * A forum post is matched on the signature of its raw message.
     */
    public function test_forum_post_matched_on_raw_signature(): void {
        $this->resetAfterTest(true);

        $message = '<p>A post about turtles.</p>';
        $post = $this->create_forum_post($message);

        $filedb = $this->queue_record($post->id, sha1($message));
        $payload = (new \plagiarism_pchkorg_forum_resolver())->resolve_text($filedb, new \stdClass());

        $this->assertNotNull($payload);
        $this->assertSame('text/plain', $payload->mime);
        $this->assertStringContainsString('turtles', $payload->content);
        $this->assertSame(sprintf('%s-forum.txt', $post->id), $payload->filename);
    }

    /**
     * Records queued from the stripped message body still match, because both
     * forms have been used when queueing.
     */
    public function test_forum_post_matched_on_stripped_signature(): void {
        $this->resetAfterTest(true);

        $message = '<p>A post about turtles.</p>';
        $post = $this->create_forum_post($message);

        $filedb = $this->queue_record($post->id, sha1(trim(strip_tags($message))));
        $payload = (new \plagiarism_pchkorg_forum_resolver())->resolve_text($filedb, new \stdClass());

        $this->assertNotNull($payload);
        $this->assertStringContainsString('turtles', $payload->content);
    }

    /**
     * An edited post no longer matches, so the record is left for a retry
     * rather than sending content the queue never saw.
     */
    public function test_forum_post_not_matched_returns_null(): void {
        $this->resetAfterTest(true);

        $post = $this->create_forum_post('<p>Edited since queueing.</p>');

        $filedb = $this->queue_record($post->id, sha1('something else entirely'));
        $payload = (new \plagiarism_pchkorg_forum_resolver())->resolve_text($filedb, new \stdClass());

        $this->assertNull($payload);
    }

    /**
     * A deleted post resolves to nothing rather than fataling.
     */
    public function test_missing_forum_post_returns_null(): void {
        $this->resetAfterTest(true);

        $filedb = $this->queue_record(999999, sha1('anything'));
        $payload = (new \plagiarism_pchkorg_forum_resolver())->resolve_text($filedb, new \stdClass());

        $this->assertNull($payload);
    }

    /**
     * An assignment submission removed since queueing resolves to nothing.
     * Previously this dereferenced a false record and fataled the whole batch.
     */
    public function test_missing_assign_submission_returns_null(): void {
        $this->resetAfterTest(true);

        $cm = new \stdClass();
        $cm->instance = 999999;

        $filedb = $this->queue_record(999999, sha1('anything'));
        $filedb->userid = 999999;

        $this->assertNull(
            \plagiarism_pchkorg_assign_resolver::assign_submission_id($filedb, $cm)
        );
    }

    /**
     * Create forum post.
     *
     * @param string $message
     * @return \stdClass The created post.
     */
    private function create_forum_post($message) {
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $forum = $generator->create_module('forum', ['course' => $course->id]);
        $user = $generator->create_user();
        $generator->enrol_user($user->id, $course->id, 'student');

        $forumgenerator = $generator->get_plugin_generator('mod_forum');
        $discussion = $forumgenerator->create_discussion([
            'course' => $course->id,
            'forum' => $forum->id,
            'userid' => $user->id,
            'message' => $message,
        ]);

        global $DB;

        return $DB->get_record('forum_posts', ['discussion' => $discussion->id], '*', MUST_EXIST);
    }

    /**
     * Queue record.
     *
     * @param int $itemid
     * @param string $signature
     * @return \stdClass
     */
    private function queue_record($itemid, $signature) {
        $filedb = new \stdClass();
        $filedb->id = 1;
        $filedb->itemid = $itemid;
        $filedb->signature = $signature;
        $filedb->fileid = null;
        $filedb->userid = 0;
        $filedb->attempt = 0;

        return $filedb;
    }
}
