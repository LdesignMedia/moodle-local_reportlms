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
 * Proves this site's open connect attempt to Report LMS.
 *
 * Report LMS fetches this while an admin is connecting, to confirm that the
 * site claiming an address really is that address. Only whoever operates this
 * site can make it serve a chosen value.
 *
 * Deliberately public, with NO_MOODLE_COOKIES: a site with forcelogin enabled
 * serves no public HTML at all, so a meta tag on the front page would be
 * invisible to us and the check would fail for exactly the corporate sites we
 * are aiming at.
 *
 * The page never serves the token. Report LMS sends a fresh nonce and gets
 * back an HMAC of it keyed by the token. Anybody can call this page, but a
 * proof for their own nonce is no use for Report LMS's nonce. Answers only
 * while a connect attempt is open.
 *
 * @package    local_reportlms
 * @copyright  2026 Luuk Verhoeven, ldesignmedia.nl <info@ldesignmedia.nl>
 * @author     Hamza Tamyachte
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('NO_MOODLE_COOKIES', true);
define('NO_UPGRADE_CHECK', true);

require(__DIR__ . '/../../config.php');

use local_reportlms\local\connect_manager;

// Report LMS sends 32 hex characters. Anything else gets no proof.
$nonce = optional_param('nonce', '', PARAM_ALPHANUM);
$nonce = preg_match('/^[0-9a-f]{32,64}$/', $nonce) ? $nonce : '';

header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('X-Robots-Tag: noindex, nofollow');

if ($nonce === '') {
    header('HTTP/1.1 400 Bad Request');
    echo 'nonce required';
    exit;
}

$proof = connect_manager::verification_proof($nonce);

if ($proof === '') {
    // No connect attempt is open, so there is nothing to verify against.
    header('HTTP/1.1 404 Not Found');
    echo 'no pending verification';
    exit;
}

echo $proof;
