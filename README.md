# Rune Cloud

### A backend for publishing Rune OS games and screensavers.

Built with PHP and MySQL.

---

## About

**Rune Cloud** is a lightweight backend for **[Rune OS](https://github.com/VectorPanic/rune-os)**. Developers can register, upload JavaScript games and screensavers, manage versions, and publish content for Rune OS installations to discover.

Rune OS supports small games that can evoke the Flash era, while Rune Cloud provides the modern API and storage needed to distribute them. The API runs on PHP and MySQL without a JavaScript build step or Composer packages.

> **Under development:** The API and database structure may change.

## What's in the box?

- **Developer accounts** — register and manage your own content using a developer token.
- **Games & screensavers** — create, update, and delete your projects.
- **Versioned uploads** — upload JavaScript files and game icons, then publish releases.
- **Multiple installations** — synchronize a shared game library across Rune OS installations and clients.
- **A shared library** — publish a versioned library manifest that Rune OS clients can sync with.
- **Tags & categories** — organize content and help players discover games.
- **Highscores** — merge game highscore tables across participating installations.
- **Admin tools** — manage the catalog and publish library updates.
- **No mandatory dependencies** — no Composer packages or JavaScript build step required by the API.

Published content is stored in MySQL. Games run as JavaScript and do not require Flash Player.

## System requirements

| Component      | Requirement                                        |
| -------------- | -------------------------------------------------- |
| PHP            | 8.2 or newer                                       |
| MySQL          | 8.0.16 or newer                                    |
| PHP extensions | `mysqli`, `fileinfo`                               |
| Web server     | Apache with `mod_rewrite` (or equivalent routing)  |

## Installation

### 1. Get the source

Clone the repository or download it as a ZIP and extract it into your web server's project directory.

### 2. Prepare the database

Create an empty MySQL database and import:

```text
src/sql/rune-cloud.sql
```

This is the current baseline schema. It includes the initial tags, groups, categories and scoring rules. Do not apply older migrations on top of it.

### 3. Configure the connection

Create `config/database.php` using the following structure (or copy `config/database.example.php` if your checkout includes it):

```php
<?php

declare(strict_types=1);

return [
    'host'     => '127.0.0.1',
    'username' => 'your_username',
    'password' => 'your_password',
    'database' => 'rune-cloud',
    'port'     => 3306,
];
```

**Keep real credentials out of Git.** Ignore `config/database.php` and commit only the example configuration. If credentials were ever committed, check the Git history before making the repository public.

### 4. Configure uploads and the server

Check `config/uploads.php` and the matching PHP/web-server upload size limits.

For Apache, enable `mod_rewrite` and allow the project's `.htaccess` rules. Requests, including existing paths, should be routed through `index.php`. Forward the `Authorization` header to PHP.

For local development, you can use PHP's built-in server:

```sh
php -S 127.0.0.1:8080 index.php
```

Use **HTTPS** for deployed installations.

### 5. Optional: Configure administration

Create a local `config/admin.php` when administrative access is needed:

```php
<?php

return [
    'token' => 'REPLACE_WITH_A_LONG_RANDOM_ADMIN_TOKEN',
    'resetPasscode' => '',
];
```

An empty admin token disables administrative routes. Keep `resetPasscode` empty except in a deliberate test setup. Never commit actual admin credentials.

## API reference

Paths below are relative to the deployment's API root. For example, a server mounted at `http://localhost:8888/api` serves `/v1/games` at `http://localhost:8888/api/v1/games`. URL-encode individual path parameters.

### Authentication and responses

- **Public:** no credentials required.
- **Developer:** send `Authorization: Bearer <developer-token>`. Content-changing requests must use the owning developer's token.
- **Admin:** send `Authorization: Bearer <admin-token>`. This is a separate credential configured in `config/admin.php`.
- **Optional developer:** omit credentials for published versions, or send the owner's token to include their drafts. An invalid supplied token is rejected.

JSON requests require `Content-Type: application/json`, a JSON object at the root and a maximum body size of 64 KiB. Upload endpoints use `multipart/form-data`. JSON failures have the form `{"error":"error_code"}`; typical statuses include `400` for invalid JSON, `401` for missing or invalid credentials, `403` for denied access, `404` for missing or inaccessible resources, `409` for conflicts, `413` for oversized input, `415` for an unsupported content type, `422` for invalid input and `503` for an unavailable operation. File download endpoints return file bytes rather than JSON.

### Developers and tokens

| Method | Endpoint | Access | Operation |
| --- | --- | --- | --- |
| `POST` | `/v1/developers` | Public | Register a developer and issue the initial token. |
| `GET` | `/v1/developer` | Developer | Return the authenticated developer. |
| `DELETE` | `/v1/developer` | Developer | Delete the account and its owned content. |
| `DELETE` | `/v1/developer/tokens/{id}` | Developer | Revoke an owned numeric token ID, including the current token. |
| `GET` | `/v1/developer/games` | Developer | List the developer's games. |
| `GET` | `/v1/developer/screensavers` | Developer | List the developer's screensavers. |

Developer registration accepts `{"identifier":"my-studio","name":"My Studio"}`. The identifier starts with a lowercase letter and contains lowercase letters, digits and hyphens, up to 100 characters. The `201` response includes `developer`, `token_id` and `token`; save the plaintext token when it is issued.

### Games

| Method | Endpoint | Access | Operation |
| --- | --- | --- | --- |
| `GET` | `/v1/games` | Public | List games and publication status. |
| `POST` | `/v1/games` | Developer | Create an owned game. |
| `GET` | `/v1/games/{id}` | Public | Read game metadata and publication status. |
| `PUT` | `/v1/games/{id}` | Developer | Update submitted metadata fields on an owned game. |
| `DELETE` | `/v1/games/{id}` | Developer | Delete an owned game and reconcile the published library. |
| `GET` | `/v1/games/{id}/versions` | Optional developer | List published versions, or all versions for the owner. |
| `POST` | `/v1/games/{id}/versions` | Developer | Create an owned draft version. |
| `GET` | `/v1/games/{id}/versions/{version}` | Optional developer | Read a published version or the owner's draft. |
| `DELETE` | `/v1/games/{id}/versions/{version}` | Developer | Delete an owned version and reconcile the library. |
| `POST` | `/v1/games/{id}/versions/{version}/files` | Developer | Upload draft game files. |
| `POST` | `/v1/games/{id}/versions/{version}/publish` | Developer | Publish or reactivate a version and return the new library manifest. |

Create a game with `{"id":"com.vectorpanic.demo","name":"Demo"}`. The ID must match the Rune SDK application ID exactly, is limited to 100 characters and follows `^[a-z]{2,3}\.[a-z0-9-]{1,50}\.[a-zA-Z0-9_-]+$`. Lookups preserve case; two IDs differing only in case cannot both be registered. Optional create and update fields are `description`, `year`, `input`, `players`, `baseTags`, `tags` and `modes`; `name` can also be updated. `input` is a nonempty list of `keyboard` and/or `gamepad`; `players` contains integer `min` and `max`; `baseTags` and `tags` map tag IDs to strengths from 0 to 100; `modes` may contain boolean `coop` and `versus` fields. Publication requires player and input metadata.

Create a game version with `{"version":"1.0.0","stage":"gold"}`. `stage` is `alpha`, `beta` or `gold` and defaults to `alpha`. Version identifiers start with a letter or digit, followed by letters, digits, dots, underscores or hyphens, up to 50 characters. Upload multipart field `game` for the `.js` executable and optional `icon` for a **442 × 884 PNG**. Publishing requires an executable and has no request body. Published versions cannot be modified by uploading files; create another draft instead.

### Screensavers

| Method | Endpoint | Access | Operation |
| --- | --- | --- | --- |
| `GET` | `/v1/screensavers` | Public | List screensavers. |
| `POST` | `/v1/screensavers` | Developer | Create an owned screensaver. |
| `GET` | `/v1/screensavers/{id}` | Public | Read screensaver metadata. |
| `PUT` | `/v1/screensavers/{id}` | Developer | Update submitted fields on an owned screensaver. |
| `DELETE` | `/v1/screensavers/{id}` | Developer | Delete an owned screensaver and reconcile the library. |
| `GET` | `/v1/screensavers/{id}/versions` | Optional developer | List published versions, or all versions for the owner. |
| `POST` | `/v1/screensavers/{id}/versions` | Developer | Create an owned draft version. |
| `GET` | `/v1/screensavers/{id}/versions/{version}` | Optional developer | Read a published version or the owner's draft. |
| `DELETE` | `/v1/screensavers/{id}/versions/{version}` | Developer | Delete an owned version and reconcile the library. |
| `POST` | `/v1/screensavers/{id}/versions/{version}/files` | Developer | Upload the draft executable. |
| `POST` | `/v1/screensavers/{id}/versions/{version}/publish` | Developer | Publish or reactivate a version and return the new library manifest. |

Create a screensaver with `{"id":"my-saver","name":"My Saver"}`. Its ID uses lowercase letters, digits and hyphens and starts with a letter. Optional metadata fields are `author`, `year` and `enabled`. Updates accept those fields and `name`. Create a version with `{"version":"1.0.0"}`; the same version format as games applies. Upload its `.js` executable as multipart field `file`. Publishing has no request body.

### Tags and categories

| Method | Endpoint | Access | Operation |
| --- | --- | --- | --- |
| `GET` | `/v1/tags` | Public | List the current tag dictionary. |
| `GET` | `/v1/tags/{id}` | Public | Read one tag. |
| `POST` | `/v1/tags` | Admin | Create a tag. |
| `PUT` | `/v1/tags/{id}` | Admin | Replace editable tag metadata. |
| `DELETE` | `/v1/tags/{id}` | Admin | Delete an unused tag. |
| `GET` | `/v1/categories` | Public | List current categories. |
| `GET` | `/v1/categories/{id}` | Public | Read one category. |
| `POST` | `/v1/categories` | Admin | Create a category. |
| `PUT` | `/v1/categories/{id}` | Admin | Replace a category with a complete editable document. |
| `DELETE` | `/v1/categories/{id}` | Admin | Delete a category. |

Tag documents use `id`, `name`, `kind` (`base` or `modifier`), optional `description` and optional `group` for modifier tags. Category documents use `id`, `name`, optional `description`, `sortOrder`, `limit` and `eligibility`, plus a required `ranking` object. Ranking weights (`baseWeight`, `modifierWeight`, `playerWeight`) total 100; enabled components require their corresponding tag or player scoring data. Category tag references must exist with the appropriate kind. Catalog changes do not rewrite already published library snapshots; republish the library to distribute them.

### Highscores

| Method | Endpoint | Access | Operation |
| --- | --- | --- | --- |
| `POST` | `/v1/highscores/sync` | Public | Merge a registered game's SDK highscore tables and return the result. |
| `DELETE` | `/v1/games/{id}/highscores` | Developer | Delete all global highscore tables for an owned game. |

Example request:

```json
{
  "appId": "com.vectorpanic.demo",
  "tables": [[
    {"name": "Ada", "score": 1200, "date": 1730000000000},
    {"name": "Rune", "score": 0, "date": 1730000000001}
  ]]
}
```

The response is a JSON object with `tables` containing the same number of tables and places. `appId` must exactly match a registered game; an unknown game returns `404 game_not_found`. Results are ordered by descending score, ascending timestamp and then a stable fingerprint. Repeated submissions of the same name, score and timestamp for a game and table are deduplicated. Entries named `Rune` with score `0` are empty places and are not stored as results. Timestamps are client-provided and are not verified.

Requests allow 1–32 tables, 1–100 places per table and at most 1,000 places total, within the 64 KiB JSON limit. Each entry must contain only `name`, `score` and `date`; names are at most 255 Unicode characters and 1,020 bytes, and score and date are finite numbers from 0 through 9,007,199,254,740,991. The endpoint does not authenticate players, verify scores or enforce a rate limit.

Deleting a game's highscores returns `{"deleted":true,"id":"com.vectorpanic.demo"}`. It clears only the server's results; games may submit their locally stored scores again on the next synchronization. Clear those local tables separately when resetting test data. A synchronization that completes after the deletion can also submit results again.

### Library and administration

| Method | Endpoint | Access | Operation |
| --- | --- | --- | --- |
| `GET` | `/v1/library` | Public | Return the current published manifest, or `503` before the first publication. |
| `GET` | `/v1/library/releases/{revision}/{path...}` | Public | Download a file belonging to a published revision. |
| `POST` | `/v1/library/publish` | Admin | Publish a new snapshot of the current library; no request body. |
| `POST` | `/v1/reset` | Admin | Reset stored content after confirmation. |

Publishing a game or screensaver version produces a new complete library manifest. Administrative library publication can be used to publish current catalog changes. Reset requires a nonempty configured `resetPasscode` and a JSON body `{"passcode":"..."}` in addition to the admin token. It removes developers, tokens, games, screensavers, versions, releases, highscores and library data, while preserving the category and tag catalog.

## One cloud. Many Rune OS machines.

Rune Cloud provides a central game catalog for **[Rune OS](https://github.com/VectorPanic/rune-os)**. Host the API once, then point multiple Rune OS installations or clients at the same server to synchronize their game libraries. When a developer publishes a new game or release and the shared library is published, clients can fetch the updated library manifest and the corresponding files.

This keeps multiple Rune OS installations synchronized over a local network or the internet.

```text
                        Rune Cloud API
                       (PHP + MySQL)
                              |
                   Published game library
                              |
              +---------------+---------------+
              |               |               |
              v               v               v
         Rune OS #1      Rune OS #2      Rune OS #3
         Desktop         Living room     Another client
```

**Note:** Library synchronization is about published catalog metadata and game/release files; it does not imply synchronization of local saves, settings, or player progress.

## Architecture

```text
Rune OS clients
   |
   | fetch published library manifest & release files
   v
Rune Cloud API
   |
   +-- Developers & authentication
   +-- Games & screensavers
   +-- Versions & uploads
   +-- Tags & categories
   +-- Library publication
   |
   v
MySQL
```

The request path starts at `index.php` and moves through the system setup, routes, controllers, and models before returning JSON or a file response.

## Security

- Keep database and admin credentials in local, ignored configuration files.
- Use HTTPS and protect developer/admin Bearer tokens.
- Restrict administrative and reset functionality in deployed environments.
- Test destructive operations against a **dedicated development database**, never production.
- Review your repository history before its first public release.

## Current status

**Early development / pre-release.** The project is intended to evolve along with Rune OS. Interfaces and database structures may change before a stable release.

The original project documentation refers to PHP and HTTP test scripts. If they are not included in a given checkout, those commands are not runnable from that checkout; automated tests are not claimed as part of this release.

## About the project

Rune Cloud is built for Rune OS and the developers who publish games and screensavers for it.
