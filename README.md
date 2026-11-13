# ☁️ Rune Cloud

### Your little corner of the internet. Now with games.

**Built with PHP, MySQL, and a healthy dose of 1999.**

*Remember when the web was weird, games were tiny, and publishing something online felt like magic? Yeah. That.*

---

## Welcome to the cloud. The old-school kind.

Back when Macromedia Flash was everywhere, websites had guestbooks, and every other button made a sound, putting a game on the internet was an adventure.

**Rune Cloud** is a lightweight backend compatible with **[Rune OS](https://github.com/VectorPanic/rune-os)**, a game and screensaver platform with a soft spot for that era. Developers can register, upload JavaScript games or screensavers, organize their releases, and publish content for Rune OS to discover.

No giant framework. No build pipeline. No mysterious package manager ritual. Just PHP, MySQL, and a server doing server things.

> **UNDER CONSTRUCTION** — Like any respectable website from the late '90s, Rune Cloud is a work in progress. Expect changes while the API takes shape.

## What's in the box?

- **Developer accounts** — register and manage your own content using a developer token.
- **Games & screensavers** — create, update, and delete your projects.
- **Versioned uploads** — upload JavaScript files and game icons, then publish releases.
- **One cloud, many consoles** — synchronize a shared game library across multiple Rune OS installations and clients.
- **A shared library** — publish a versioned library manifest that Rune OS clients can sync with.
- **Tags & categories** — organize content and help players discover something fun.
- **Admin tools** — manage the catalog and publish library updates.
- **No mandatory dependencies** — no Composer packages or JavaScript build step required by the API.

All permanent published content is stored in MySQL. Flash Player is **not** required. The nostalgia is purely aesthetic.

## System requirements

| Ingredient     | Requirement                                        |
| -------------- | -------------------------------------------------- |
| PHP            | 8.2 or newer                                       |
| MySQL          | 8.0.16 or newer                                    |
| PHP extensions | `mysqli`, `fileinfo`                               |
| Web server     | Apache with `mod_rewrite` (or equivalent routing)  |
| Attitude       | At least one fond memory of the old web (optional) |

## Installation — It's like uploading your site over FTP, but nicer

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

## The API arcade

A few ways to play with the backend:

| Method | Endpoint                                    | What it does                                     |
| ------ | ------------------------------------------- | ------------------------------------------------ |
| `POST` | `/v1/developers`                            | Register a developer; the token is returned once |
| `GET`  | `/v1/developer`                             | Check your developer token                       |
| `GET`  | `/v1/developer/games`                       | List your games                                  |
| `GET`  | `/v1/developer/screensavers`                | List your screensavers                           |
| `POST` | `/v1/games`                                 | Create a game record                             |
| `POST` | `/v1/screensavers`                          | Create a screensaver record                      |
| `POST` | `/v1/games/{id}/versions`                   | Create a game version                            |
| `POST` | `/v1/games/{id}/versions/{version}/files`   | Upload game files                                |
| `POST` | `/v1/games/{id}/versions/{version}/publish` | Publish a game version                           |
| `GET`  | `/v1/library`                               | Get the published Rune OS library manifest       |

Screensavers have corresponding version, upload, and publish endpoints. Developer operations require a developer Bearer token; administrative catalog operations use a separate admin token.

**Uploads:** games use multipart field `game` for a `.js` file and optional `icon` for a **442 × 884 PNG**. Screensavers use multipart field `file` for a `.js` file.

Publishing a game version is not the same as publishing the shared Rune OS library manifest: catalog changes must also be published by an administrator before clients receive an updated library. Until the first library publication, `GET /v1/library` returns `503`.

This table is a quick tour, not a complete API reference.

## One cloud. Many Rune OS machines.

Remember copying games from one computer to another on a stack of floppy disks? This is the same idea, minus the floppy disks (and the inevitable disk read error).

Rune Cloud provides a central game catalog for **[Rune OS](https://github.com/VectorPanic/rune-os)**. Host the API once, then point multiple Rune OS installations or clients at the same server to synchronize their game libraries. When a developer publishes a new game or release and the shared library is published, clients can fetch the updated library manifest and the corresponding files.

That makes Rune Cloud useful for keeping a collection of Rune OS machines in sync — whether they're on different desks, in different rooms, or somewhere across the internet.

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

## How the magic happens

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

## A word about safety

This is an API, not a public dumping ground for secrets.

- Keep database and admin credentials in local, ignored configuration files.
- Use HTTPS and protect developer/admin Bearer tokens.
- Restrict administrative and reset functionality in deployed environments.
- Test destructive operations against a **dedicated development database**, never production.
- Review your repository history before its first public release.

## Current status

**Early development / pre-release.** The project is intended to evolve along with Rune OS. Interfaces and database structures may change before a stable release.

The original project documentation refers to PHP and HTTP test scripts. If they are not included in a given checkout, those commands are not runnable from that checkout; automated tests are not claimed as part of this release.

## One last thing...

Rune Cloud isn't trying to reinvent the internet. It's here to make it fun to create, upload, find, and play little games again.

**Made for Rune OS. Inspired by the web we grew up with.**

*Best viewed with curiosity. Screen resolution: whatever you like.*

---

*Rune Cloud is not affiliated with Adobe or Macromedia. Flash is mentioned for nostalgia, not as a technical dependency.*