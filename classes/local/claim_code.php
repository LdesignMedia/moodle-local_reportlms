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
 * The claim code an administrator copies from a Report LMS account page.
 *
 * @package    local_reportlms
 * @copyright  2026 Luuk Verhoeven, ldesignmedia.nl <info@ldesignmedia.nl>
 * @author     Hamza Tamyachte
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_reportlms\local;

/**
 * Sends a claim code to Report LMS and says what came back.
 *
 * Connecting proves this site controls its address. It says nothing about
 * which Report LMS account the site belongs to, and an account needs that
 * before it may buy, move or cancel this site's licence online. The code is
 * shown on the account page; typing it in here is the proof.
 *
 * Nothing about the code is kept on this site. It is sent once and forgotten.
 */
class claim_code {
    /** @var int The longest code worth sending, once spaces and dashes are gone. */
    public const MAX_LENGTH = 32;

    /**
     * The one form a code is sent in: upper case, letters and digits only.
     *
     * K7QF-29XM, k7qf 29xm and K7QF29XM are the same code, so a code copied
     * with a space or typed in lower case still works.
     *
     * @param string $code What the administrator typed.
     * @return string
     */
    public static function normalise(string $code): string {
        return strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $code));
    }

    /**
     * Send a claim code and say what Report LMS answered.
     *
     * Never throws for an answer: a refusal is something to show the
     * administrator, in the words Report LMS chose for it.
     *
     * @param string $code What the administrator typed.
     * @param api_client|null $client Optional client override for tests.
     * @return array{success: bool, message: string}
     */
    public static function send(string $code, ?api_client $client = null): array {
        $code = self::normalise($code);

        if ($code === '' || strlen($code) > self::MAX_LENGTH) {
            return ['success' => false, 'message' => get_string('claim:invalid', 'local_reportlms')];
        }

        // The call is keyed and signed, so there is nothing to send it with
        // before the site is connected.
        if (!connect_manager::is_connected()) {
            return ['success' => false, 'message' => get_string('claim:notconnected', 'local_reportlms')];
        }

        try {
            $client = $client ?? new api_client(config::baseurl());
            $answer = $client->claim(
                trim((string) get_config('local_reportlms', 'apikey')),
                trim((string) get_config('local_reportlms', 'signingsecret')),
                $code
            );
        } catch (\moodle_exception $exception) {
            return ['success' => false, 'message' => $exception->getMessage()];
        }

        $message = is_string($answer['message'] ?? null) ? trim($answer['message']) : '';

        return [
            'success' => true,
            'message' => $message !== '' ? $message : get_string('claim:success', 'local_reportlms'),
        ];
    }
}
