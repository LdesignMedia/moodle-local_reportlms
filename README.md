<p align="center">
  <a href="https://reportlms.com">
    <img src=".github/assets/dashboard.png" alt="Report LMS: training dashboards for your Moodle site" width="772">
  </a>
</p>

# Report LMS for Moodle

[![CI](https://github.com/LdesignMedia/moodle-local_reportlms/actions/workflows/ci.yml/badge.svg?branch=main)](https://github.com/LdesignMedia/moodle-local_reportlms/actions/workflows/ci.yml)

Connect your Moodle site to [Report LMS](https://reportlms.com) for clear training
dashboards: completion, overdue training, attempts, time spent and certificates,
per department.

Learner data leaves your site as codes and numbers only. Names never do: the
dashboard adds them in the manager's browser, straight from your own Moodle.

## Features

- Dashboard inside Moodle with completion, overdue, attempts and certificate figures.
- Progress per training, attempts to pass, time spent and overdue per department.
- Filters on training, department, profile fields and status.
- PDF export for audits and management reports.
- Organisation structure with departments, units and a manager per unit.
- Managers only see the people in their own part of the organisation.
- Certificate expiry tracking for Moodle Workplace and `mod_customcert`.
- One-click connection with clear status, reconnect and disconnect controls.
- Optional front page block that links to the dashboard.

## Requirements

- Moodle **3.9+** and PHP **8.0+**, using a PHP version supported by your Moodle release.
- A Report LMS subscription, outbound HTTPS access and working Moodle cron.

## Install and connect

1. Install through **Site administration → Plugins → Install plugins**.
2. Visit **Site administration** to complete the installation or upgrade.
3. Open **Plugins → Local plugins → Report LMS** and follow the three setup steps:
   - **Request a licence** for your site address.
   - **Read what leaves your site**: the statement shows exactly which data is sent
     and which never is.
   - **Press connect**. The plugin proves the site is yours and receives its keys,
     there is nothing to copy or paste.
4. Set up your organisation: add departments and place people in them.
5. Give managers access by naming them as manager of a unit, or grant
   `local/reportlms:view` to a role.

<p align="center">
  <img src=".github/assets/setup.png" alt="Report LMS setup: request a licence, read the statement, connect" width="600">
</p>

For manual installation, place the plugin in `local/reportlms` on Moodle 3.9–5.0,
or `public/local/reportlms` on Moodle 5.1 and later.

Data is sent by cron. **Disconnect** stops all sending. **Reconnect** verifies the
site again, also when the previous key expired or was lost.

## Privacy

Report LMS never receives names, email addresses or other free text. Learner ids
are coded with a key that stays on your Moodle site, so only your site can link a
code back to a person. Results are sent as numbers and dates.

Everything the plugin stores and sends is declared through the Moodle Privacy API.

## Support and license

Report problems through [GitHub issues](https://github.com/LdesignMedia/moodle-local_reportlms/issues).
Learn more at [reportlms.com](https://reportlms.com).

Licensed under [GNU GPL v3 or later](LICENSE).
