# Report LMS (local_reportlms)

Moodle plugin that connects a site to the Report LMS reporting service and pushes pseudonymised
learning data to it.

The receiving service is the `reportlms` Laravel application. The wire format both ends build
against is `reportlms/docs/push-contract-v1.md`.

## Status

Connect flow only. The push itself, the pseudonymiser, the collectors and the scheduled task are
not built yet. See ClickUp 86cay1v14 and 86cay1v1d.

## Requirements

- Moodle 3.9 or later
- PHP 8.0 or later

## Installation

Copy into `local/reportlms` and run the Moodle upgrade.

## Connecting a site

The site must already be registered in the Report LMS licence system.

*Site administration > Plugins > Local plugins > Report LMS*, then press **Connect to Report LMS**.

What happens:

1. The plugin generates a random verification token and publishes it on `/local/reportlms/verify.php`.
2. It calls `POST /api/v1/connect` on the Report LMS host with the site URL and that token.
3. Report LMS checks the licence for this site, then fetches the token back off the site to confirm
   the caller really operates it.
4. Report LMS returns an API key and a signing secret, which the plugin stores. The key expires with
   the licence.

`verify.php` is deliberately public and uses `NO_MOODLE_COOKIES`. A site with `forcelogin` enabled
serves no public HTML at all, so a verification meta tag on the front page would be invisible to
Report LMS and the check would fail for exactly the sites this plugin targets. The endpoint answers
only while a connect attempt is open, and the token is single use.

**Reconnect** re-runs the same verification. It does not need the existing key, so a site whose key
expired or was lost can still reconnect.

**Disconnect** clears the keys and the site stops sending data. The pseudonymisation salt is
deliberately kept: regenerating it would change every coded id, so a later reconnect would look like
a completely new population and orphan every fact already stored.

## Settings

The settings page shows the connection status, the Disconnect button and the front page block
button; connecting happens from the report page, where the setup sequence lives. The API key, the
signing secret and the salt are written by the connect flow and are never rendered, so they cannot
be hand-edited into a broken state.

The Report LMS URL and push path live behind advanced mode, reached from the Advanced settings
button (shown to holders of `local/reportlms:manage`) or directly:

```
/admin/settings.php?section=local_reportlms&mode=advanced
```

The base URL can be pinned in `config.php`:

```php
$CFG->forced_plugin_settings['local_reportlms']['baseurl'] = 'https://reportlms.example.com';
```

## Privacy

The plugin currently stores no personal data and sends none, so it declares a null privacy provider.
That has to change when the push lands: from then on it transmits coded learner identifiers and
numeric results to an external service, which must be declared as an external location even though
it carries no names or addresses.
