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
 * Tests for the proof verify.php serves.
 *
 * @package    local_reportlms
 * @copyright  2026 Luuk Verhoeven, ldesignmedia.nl <info@ldesignmedia.nl>
 * @author     Hamza Tamyachte
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_reportlms;

use local_reportlms\local\connect_manager;

/**
 * The public verify page answers with a proof of the token, never the token.
 *
 * @covers \local_reportlms\local\connect_manager
 */
final class verification_proof_test extends \advanced_testcase {
    /** @var string A connect token as connect() stores it. */
    private const TOKEN = 'abcdefghijklmnopqrstuvwxyz0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ01';

    /**
     * Open a connect attempt the way connect() does.
     *
     * @param int $expires When the attempt expires.
     */
    private function open_attempt(int $expires): void {
        set_config('verifytoken', self::TOKEN, 'local_reportlms');
        set_config('verifyexpires', $expires, 'local_reportlms');
    }

    /**
     * The proof is the HMAC Report LMS computes for its nonce, and does not hold the token.
     */
    public function test_the_proof_is_an_hmac_of_the_nonce_and_not_the_token(): void {
        $this->resetAfterTest();
        $this->open_attempt(time() + 600);
        $nonce = str_repeat('0f', 16);

        $proof = connect_manager::verification_proof($nonce);

        $this->assertSame(hash_hmac('sha256', 'reportlms-verify:' . $nonce, self::TOKEN), $proof);
        $this->assertStringNotContainsString(self::TOKEN, $proof);
    }

    /**
     * A proof for one nonce does not match another nonce.
     */
    public function test_each_nonce_gets_its_own_proof(): void {
        $this->resetAfterTest();
        $this->open_attempt(time() + 600);

        $this->assertNotSame(
            connect_manager::verification_proof(str_repeat('a', 32)),
            connect_manager::verification_proof(str_repeat('b', 32))
        );
    }

    /**
     * No open or a lapsed attempt, or no nonce: no proof at all.
     */
    public function test_no_proof_without_an_open_attempt_or_a_nonce(): void {
        $this->resetAfterTest();

        $this->assertSame('', connect_manager::verification_proof(str_repeat('a', 32)));

        $this->open_attempt(time() - 1);
        $this->assertSame('', connect_manager::verification_proof(str_repeat('a', 32)));

        $this->open_attempt(time() + 600);
        $this->assertSame('', connect_manager::verification_proof(''));
    }
}
