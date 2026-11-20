-- First-install baseline: schema, catalog seed data and CHECK constraints.
-- phpMyAdmin SQL Dump
-- version 5.2.3
-- https://www.phpmyadmin.net/
--
-- Värd: localhost:8889
-- Tid vid skapande: 11 nov 2026 kl 21:42
-- Serverversion: 8.0.44
-- PHP-version: 8.3.30

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Databas: `rune-cloud`
--

-- --------------------------------------------------------

--
-- Tabellstruktur `api_tokens`
--

CREATE TABLE `api_tokens` (
  `id` bigint UNSIGNED NOT NULL,
  `developer_id` bigint UNSIGNED NOT NULL,
  `token_hash` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `revoked_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Tabellstruktur `categories`
--

CREATE TABLE `categories` (
  `id` bigint UNSIGNED NOT NULL,
  `identifier` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text COLLATE utf8mb4_unicode_ci,
  `sort_order` int UNSIGNED NOT NULL DEFAULT '0',
  `display_limit` int UNSIGNED DEFAULT NULL
) ;

--
-- Dumpning av Data i tabell `categories`
--

INSERT INTO `categories` (`id`, `identifier`, `name`, `description`, `sort_order`, `display_limit`) VALUES
(1, 'co-op-adventures', 'Co-op Adventures', NULL, 1, NULL),
(2, 'multiplayer-mayhem', 'Multiplayer Mayhem', NULL, 2, NULL),
(3, 'solo-adventures', 'Solo Adventures', NULL, 3, NULL),
(4, 'arcade-rush', 'Arcade Rush', NULL, 4, NULL),
(5, 'think-it-through', 'Think It Through', NULL, 5, NULL),
(6, 'sports-and-racing', 'Sports & Racing', NULL, 6, NULL),
(7, 'explore-and-discover', 'Explore & Discover', NULL, 7, NULL),
(8, 'build-and-create', 'Build & Create', NULL, 8, NULL),
(9, 'simulation-and-management', 'Simulation & Management', NULL, 9, NULL),
(10, 'party-time', 'Party Time', NULL, 10, NULL),
(11, 'rhythm-and-music', 'Rhythm & Music', NULL, 11, NULL),
(12, 'score-chasers', 'Score Chasers', NULL, 12, NULL);

-- --------------------------------------------------------

--
-- Tabellstruktur `category_player_scores`
--

CREATE TABLE `category_player_scores` (
  `category_id` bigint UNSIGNED NOT NULL,
  `min_players` tinyint UNSIGNED NOT NULL,
  `score` decimal(5,2) NOT NULL
) ;

--
-- Dumpning av Data i tabell `category_player_scores`
--

INSERT INTO `category_player_scores` (`category_id`, `min_players`, `score`) VALUES
(1, 2, 40.00),
(1, 3, 70.00),
(1, 4, 100.00),
(2, 2, 40.00),
(2, 3, 70.00),
(2, 4, 100.00),
(3, 1, 100.00),
(3, 2, 0.00);

-- --------------------------------------------------------

--
-- Tabellstruktur `category_ranking`
--

CREATE TABLE `category_ranking` (
  `category_id` bigint UNSIGNED NOT NULL,
  `base_weight` tinyint UNSIGNED NOT NULL DEFAULT '0',
  `modifier_weight` tinyint UNSIGNED NOT NULL DEFAULT '0',
  `player_weight` tinyint UNSIGNED NOT NULL DEFAULT '0'
) ;

--
-- Dumpning av Data i tabell `category_ranking`
--

INSERT INTO `category_ranking` (`category_id`, `base_weight`, `modifier_weight`, `player_weight`) VALUES
(1, 60, 15, 25),
(2, 45, 30, 25),
(3, 60, 5, 35),
(4, 70, 30, 0),
(5, 65, 35, 0),
(6, 70, 30, 0),
(7, 65, 35, 0),
(8, 60, 40, 0),
(9, 65, 35, 0),
(10, 70, 30, 0),
(11, 70, 30, 0),
(12, 0, 100, 0);

-- --------------------------------------------------------

--
-- Tabellstruktur `category_ranking_tags`
--

CREATE TABLE `category_ranking_tags` (
  `category_id` bigint UNSIGNED NOT NULL,
  `tag_id` bigint UNSIGNED NOT NULL,
  `component` enum('base','modifier') COLLATE utf8mb4_unicode_ci NOT NULL,
  `weight` decimal(5,2) DEFAULT NULL
) ;

--
-- Dumpning av Data i tabell `category_ranking_tags`
--

INSERT INTO `category_ranking_tags` (`category_id`, `tag_id`, `component`, `weight`) VALUES
(1, 1, 'base', NULL),
(1, 2, 'base', NULL),
(1, 3, 'base', NULL),
(1, 4, 'base', NULL),
(1, 5, 'base', NULL),
(1, 6, 'base', NULL),
(1, 7, 'base', NULL),
(1, 8, 'base', NULL),
(1, 9, 'base', NULL),
(1, 10, 'base', NULL),
(1, 11, 'base', NULL),
(1, 12, 'base', NULL),
(1, 13, 'base', NULL),
(1, 14, 'base', NULL),
(1, 15, 'base', NULL),
(1, 23, 'modifier', 25.00),
(1, 25, 'modifier', 25.00),
(1, 27, 'modifier', 30.00),
(1, 34, 'modifier', 20.00),
(2, 1, 'base', NULL),
(2, 7, 'base', NULL),
(2, 8, 'base', NULL),
(2, 15, 'base', NULL),
(2, 16, 'modifier', 35.00),
(2, 18, 'modifier', 45.00),
(2, 25, 'modifier', 20.00),
(3, 1, 'base', NULL),
(3, 2, 'base', NULL),
(3, 3, 'base', NULL),
(3, 4, 'base', NULL),
(3, 5, 'base', NULL),
(3, 6, 'base', NULL),
(3, 7, 'base', NULL),
(3, 8, 'base', NULL),
(3, 9, 'base', NULL),
(3, 10, 'base', NULL),
(3, 11, 'base', NULL),
(3, 12, 'base', NULL),
(3, 13, 'base', NULL),
(3, 14, 'base', NULL),
(3, 15, 'base', NULL),
(3, 27, 'modifier', 40.00),
(3, 34, 'modifier', 35.00),
(3, 35, 'modifier', 25.00),
(4, 1, 'base', NULL),
(4, 2, 'base', NULL),
(4, 16, 'modifier', 25.00),
(4, 20, 'modifier', 20.00),
(4, 21, 'modifier', 10.00),
(4, 22, 'modifier', 15.00),
(4, 26, 'modifier', 30.00),
(5, 4, 'base', NULL),
(5, 5, 'base', NULL),
(5, 13, 'base', NULL),
(5, 21, 'modifier', 25.00),
(5, 23, 'modifier', 35.00),
(5, 24, 'modifier', 40.00),
(6, 7, 'base', NULL),
(6, 8, 'base', NULL),
(6, 16, 'modifier', 20.00),
(6, 20, 'modifier', 25.00),
(6, 21, 'modifier', 30.00),
(6, 22, 'modifier', 25.00),
(7, 3, 'base', NULL),
(7, 6, 'base', NULL),
(7, 27, 'modifier', 40.00),
(7, 28, 'modifier', 15.00),
(7, 34, 'modifier', 15.00),
(7, 35, 'modifier', 30.00),
(8, 11, 'base', NULL),
(8, 23, 'modifier', 10.00),
(8, 29, 'modifier', 40.00),
(8, 30, 'modifier', 20.00),
(8, 36, 'modifier', 30.00),
(9, 9, 'base', NULL),
(9, 10, 'base', NULL),
(9, 23, 'modifier', 30.00),
(9, 29, 'modifier', 15.00),
(9, 30, 'modifier', 40.00),
(9, 34, 'modifier', 15.00),
(10, 13, 'base', NULL),
(10, 14, 'base', NULL),
(10, 15, 'base', NULL),
(10, 16, 'modifier', 25.00),
(10, 18, 'modifier', 35.00),
(10, 20, 'modifier', 20.00),
(10, 22, 'modifier', 20.00),
(11, 12, 'base', NULL),
(11, 20, 'modifier', 20.00),
(11, 21, 'modifier', 30.00),
(11, 22, 'modifier', 50.00),
(12, 32, 'modifier', 60.00),
(12, 33, 'modifier', 40.00);

-- --------------------------------------------------------

--
-- Tabellstruktur `category_rules`
--

CREATE TABLE `category_rules` (
  `id` bigint UNSIGNED NOT NULL,
  `category_id` bigint UNSIGNED NOT NULL,
  `rule_type` enum('base_any','tags_any','required_mode','minimum_max_players','maximum_min_players','maximum_max_players') COLLATE utf8mb4_unicode_ci NOT NULL,
  `minimum_strength` decimal(5,2) DEFAULT NULL,
  `mode` enum('coop','versus') COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `player_count` tinyint UNSIGNED DEFAULT NULL
) ;

--
-- Dumpning av Data i tabell `category_rules`
--

INSERT INTO `category_rules` (`id`, `category_id`, `rule_type`, `minimum_strength`, `mode`, `player_count`) VALUES
(1, 1, 'required_mode', NULL, 'coop', NULL),
(2, 1, 'minimum_max_players', NULL, NULL, 2),
(3, 2, 'required_mode', NULL, 'versus', NULL),
(4, 2, 'minimum_max_players', NULL, NULL, 2),
(5, 2, 'tags_any', 40.00, NULL, NULL),
(6, 3, 'maximum_min_players', NULL, NULL, 1),
(7, 3, 'maximum_max_players', NULL, NULL, 1),
(8, 4, 'base_any', 50.00, NULL, NULL),
(9, 5, 'base_any', 50.00, NULL, NULL),
(10, 6, 'base_any', 50.00, NULL, NULL),
(11, 7, 'base_any', 50.00, NULL, NULL),
(12, 8, 'base_any', 50.00, NULL, NULL),
(13, 9, 'base_any', 50.00, NULL, NULL),
(14, 10, 'base_any', 50.00, NULL, NULL),
(15, 10, 'minimum_max_players', NULL, NULL, 2),
(16, 11, 'base_any', 50.00, NULL, NULL),
(17, 12, 'tags_any', 50.00, NULL, NULL);

-- --------------------------------------------------------

--
-- Tabellstruktur `category_rule_tags`
--

CREATE TABLE `category_rule_tags` (
  `rule_id` bigint UNSIGNED NOT NULL,
  `tag_id` bigint UNSIGNED NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumpning av Data i tabell `category_rule_tags`
--

INSERT INTO `category_rule_tags` (`rule_id`, `tag_id`) VALUES
(8, 1),
(8, 2),
(11, 3),
(9, 4),
(9, 5),
(11, 6),
(10, 7),
(10, 8),
(13, 9),
(13, 10),
(12, 11),
(16, 12),
(9, 13),
(14, 13),
(14, 14),
(14, 15),
(5, 16),
(5, 18),
(17, 32),
(17, 33);

-- --------------------------------------------------------

--
-- Tabellstruktur `developers`
--

CREATE TABLE `developers` (
  `id` bigint UNSIGNED NOT NULL,
  `identifier` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Tabellstruktur `file_contents`
--

CREATE TABLE `file_contents` (
  `id` bigint UNSIGNED NOT NULL,
  `content` longblob NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Tabellstruktur `games`
--

CREATE TABLE `games` (
  `id` bigint UNSIGNED NOT NULL,
  `identifier` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text COLLATE utf8mb4_unicode_ci,
  `developer` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `year` smallint UNSIGNED DEFAULT NULL,
  `promotion` tinyint UNSIGNED NOT NULL DEFAULT 0,
  `min_players` tinyint UNSIGNED DEFAULT NULL,
  `max_players` tinyint UNSIGNED DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `developer_id` bigint UNSIGNED DEFAULT NULL
) ;



-- --------------------------------------------------------

--
-- Tabellstruktur `game_inputs`
--

CREATE TABLE `game_inputs` (
  `game_id` bigint UNSIGNED NOT NULL,
  `input_type` enum('keyboard','gamepad') COLLATE utf8mb4_unicode_ci NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Tabellstruktur `game_modes`
--

CREATE TABLE `game_modes` (
  `game_id` bigint UNSIGNED NOT NULL,
  `mode` enum('coop','versus') COLLATE utf8mb4_unicode_ci NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Tabellstruktur `game_tags`
--

CREATE TABLE `game_tags` (
  `game_id` bigint UNSIGNED NOT NULL,
  `tag_id` bigint UNSIGNED NOT NULL,
  `strength` decimal(5,2) NOT NULL
) ;

-- --------------------------------------------------------

--
-- Tabellstruktur `game_versions`
--

CREATE TABLE `game_versions` (
  `id` bigint UNSIGNED NOT NULL,
  `game_id` bigint UNSIGNED NOT NULL,
  `version` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `stage` enum('alpha','beta','gold') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'gold',
  `published_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Tabellstruktur `library_revisions`
--

CREATE TABLE `library_revisions` (
  `id` bigint UNSIGNED NOT NULL,
  `revision` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `published_at` datetime DEFAULT NULL,
  `manifest_content_id` bigint UNSIGNED DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Tabellstruktur `library_revision_files`
--

CREATE TABLE `library_revision_files` (
  `library_revision_id` bigint UNSIGNED NOT NULL,
  `path` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  `content_id` bigint UNSIGNED NOT NULL,
  `checksum` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `size_bytes` bigint UNSIGNED NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
-- --------------------------------------------------------

--
-- Tabellstruktur `library_revision_releases`
--

CREATE TABLE `library_revision_releases` (
  `library_revision_id` bigint UNSIGNED NOT NULL,
  `release_id` bigint UNSIGNED NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Tabellstruktur `releases`
--

CREATE TABLE `releases` (
  `id` bigint UNSIGNED NOT NULL,
  `game_version_id` bigint UNSIGNED DEFAULT NULL,
  `screensaver_version_id` bigint UNSIGNED DEFAULT NULL,
  `package_path` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `checksum` char(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `size_bytes` bigint UNSIGNED DEFAULT NULL,
  `published_at` datetime DEFAULT NULL
) ;

-- --------------------------------------------------------

--
-- Tabellstruktur `release_files`
--

CREATE TABLE `release_files` (
  `id` bigint UNSIGNED NOT NULL,
  `release_id` bigint UNSIGNED NOT NULL,
  `content_id` bigint UNSIGNED DEFAULT NULL,
  `path` varchar(500) COLLATE utf8mb4_unicode_ci NOT NULL,
  `checksum` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `size_bytes` bigint UNSIGNED NOT NULL,
  `file_type` enum('game','icon') COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `uploaded_at` datetime DEFAULT NULL
) ;

-- --------------------------------------------------------

--
-- Tabellstruktur `screensavers`
--

CREATE TABLE `screensavers` (
  `id` bigint UNSIGNED NOT NULL,
  `identifier` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `author` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `year` smallint UNSIGNED DEFAULT NULL,
  `enabled` tinyint(1) NOT NULL DEFAULT '1',
  `required_files` json DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `developer_id` bigint UNSIGNED DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Tabellstruktur `screensaver_versions`
--

CREATE TABLE `screensaver_versions` (
  `id` bigint UNSIGNED NOT NULL,
  `screensaver_id` bigint UNSIGNED NOT NULL,
  `version` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `package_path` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `checksum` char(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `size_bytes` bigint UNSIGNED DEFAULT NULL,
  `published_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Tabellstruktur `tags`
--

CREATE TABLE `tags` (
  `id` bigint UNSIGNED NOT NULL,
  `identifier` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text COLLATE utf8mb4_unicode_ci,
  `kind` enum('base','modifier') COLLATE utf8mb4_unicode_ci NOT NULL,
  `group_id` bigint UNSIGNED DEFAULT NULL
) ;

--
-- Dumpning av Data i tabell `tags`
--

INSERT INTO `tags` (`id`, `identifier`, `name`, `description`, `kind`, `group_id`) VALUES
(1, 'action', 'Action', 'Shooting, fighting and real-time action.', 'base', NULL),
(2, 'platformer', 'Platformer', 'Jumping, movement and obstacle traversal.', 'base', NULL),
(3, 'adventure', 'Adventure', 'Exploration, narrative and discovery.', 'base', NULL),
(4, 'puzzle', 'Puzzle', 'Logic and problem solving.', 'base', NULL),
(5, 'strategy', 'Strategy', 'Tactics, planning and resource decisions.', 'base', NULL),
(6, 'role-playing', 'Role-playing', 'Character growth, roles and equipment.', 'base', NULL),
(7, 'sports', 'Sports', 'Sports and athletic activities.', 'base', NULL),
(8, 'racing', 'Racing', 'Races and competition over a course.', 'base', NULL),
(9, 'simulation', 'Simulation', 'Simulation of vehicles, jobs or systems.', 'base', NULL),
(10, 'management', 'Management', 'Operating and developing cities or organizations.', 'base', NULL),
(11, 'creative', 'Creative', 'Free creation, building and experimentation.', 'base', NULL),
(12, 'rhythm', 'Rhythm', 'Music and rhythmic play.', 'base', NULL),
(13, 'tabletop', 'Tabletop', 'Card, board and dice games.', 'base', NULL),
(14, 'trivia', 'Trivia', 'Knowledge, quizzes and guessing.', 'base', NULL),
(15, 'party', 'Party', 'Social minigames and group activities.', 'base', NULL),
(16, 'fast-paced', 'Fast-paced', 'High tempo and frequent action.', 'modifier', 1),
(17, 'relaxed', 'Relaxed', 'Calm play with little time pressure.', 'modifier', 1),
(18, 'chaotic', 'Chaotic', 'Unpredictable situations and simultaneous events.', 'modifier', 1),
(19, 'tense', 'Tense', 'Anticipation, uncertainty and pressure.', 'modifier', 1),
(20, 'reflex', 'Reflex', 'React quickly to changing situations.', 'modifier', 2),
(21, 'precision', 'Precision', 'Accurate and deliberate actions.', 'modifier', 2),
(22, 'timing', 'Timing', 'Act at the right moment.', 'modifier', 2),
(23, 'planning', 'Planning', 'Consider consequences and plan ahead.', 'modifier', 2),
(24, 'problem-solving', 'Problem-solving', 'Reason through challenges and find solutions.', 'modifier', 2),
(25, 'combat', 'Combat', 'Fight enemies or other players.', 'modifier', 3),
(26, 'avoidance', 'Avoidance', 'Avoid threats and obstacles.', 'modifier', 3),
(27, 'exploration', 'Exploration', 'Explore places, routes and secrets.', 'modifier', 3),
(28, 'collection', 'Collection', 'Gather items or resources.', 'modifier', 3),
(29, 'building', 'Building', 'Construct and shape structures.', 'modifier', 3),
(30, 'resource-management', 'Resource management', 'Allocate and manage limited resources.', 'modifier', 3),
(31, 'survival', 'Survival', 'Survive threats and sustained pressure.', 'modifier', 4),
(32, 'score-attack', 'Score attack', 'Pursue and improve high scores.', 'modifier', 4),
(33, 'time-trial', 'Time trial', 'Improve completion times.', 'modifier', 4),
(34, 'progression', 'Progression', 'Advance through goals, stages or upgrades.', 'modifier', 4),
(35, 'story-driven', 'Story-driven', 'Follow an unfolding narrative.', 'modifier', 4),
(36, 'open-ended', 'Open-ended', 'Choose goals without a fixed ending.', 'modifier', 4);

-- --------------------------------------------------------

--
-- Tabellstruktur `tag_groups`
--

CREATE TABLE `tag_groups` (
  `id` bigint UNSIGNED NOT NULL,
  `identifier` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL
) ;

--
-- Dumpning av Data i tabell `tag_groups`
--

INSERT INTO `tag_groups` (`id`, `identifier`, `name`) VALUES
(1, 'pace', 'Pace'),
(2, 'skill', 'Skill'),
(3, 'activity', 'Activity'),
(4, 'structure', 'Structure');

--
-- Index för dumpade tabeller
--

--
-- Index för tabell `api_tokens`
--
ALTER TABLE `api_tokens`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_api_tokens_hash` (`token_hash`),
  ADD KEY `idx_api_tokens_developer` (`developer_id`);

--
-- Index för tabell `categories`
--
ALTER TABLE `categories`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_categories_identifier` (`identifier`),
  ADD KEY `idx_categories_sort` (`sort_order`,`id`);

--
-- Index för tabell `category_player_scores`
--
ALTER TABLE `category_player_scores`
  ADD PRIMARY KEY (`category_id`,`min_players`);

--
-- Index för tabell `category_ranking`
--
ALTER TABLE `category_ranking`
  ADD PRIMARY KEY (`category_id`);

--
-- Index för tabell `category_ranking_tags`
--
ALTER TABLE `category_ranking_tags`
  ADD PRIMARY KEY (`category_id`,`tag_id`),
  ADD KEY `idx_category_ranking_tags_tag` (`tag_id`);

--
-- Index för tabell `category_rules`
--
ALTER TABLE `category_rules`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_category_rules_type` (`category_id`,`rule_type`);

--
-- Index för tabell `category_rule_tags`
--
ALTER TABLE `category_rule_tags`
  ADD PRIMARY KEY (`rule_id`,`tag_id`),
  ADD KEY `idx_category_rule_tags_tag` (`tag_id`);

--
-- Index för tabell `developers`
--
ALTER TABLE `developers`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_developers_identifier` (`identifier`);

--
-- Index för tabell `file_contents`
--
ALTER TABLE `file_contents`
  ADD PRIMARY KEY (`id`);

--
-- Index för tabell `games`
--
ALTER TABLE `games`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_games_identifier` (`identifier`),
  ADD KEY `idx_games_name` (`name`),
  ADD KEY `idx_games_developer` (`developer_id`);

--
-- Index för tabell `game_inputs`
--
ALTER TABLE `game_inputs`
  ADD PRIMARY KEY (`game_id`,`input_type`);

--
-- Index för tabell `game_modes`
--
ALTER TABLE `game_modes`
  ADD PRIMARY KEY (`game_id`,`mode`);

--
-- Index för tabell `game_tags`
--
ALTER TABLE `game_tags`
  ADD PRIMARY KEY (`game_id`,`tag_id`),
  ADD KEY `idx_game_tags_tag` (`tag_id`);

--
-- Index för tabell `game_versions`
--
ALTER TABLE `game_versions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_game_versions_game_version` (`game_id`,`version`),
  ADD KEY `idx_game_versions_stage` (`stage`);

--
-- Index för tabell `library_revisions`
--
ALTER TABLE `library_revisions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_library_revisions_revision` (`revision`),
  ADD KEY `idx_library_revisions_published` (`published_at`),
  ADD KEY `fk_library_revision_manifest` (`manifest_content_id`);

--
-- Index för tabell `library_revision_files`
--
ALTER TABLE `library_revision_files`
  ADD PRIMARY KEY (`library_revision_id`,`path`),
  ADD KEY `fk_revision_files_content` (`content_id`);

--
-- Index för tabell `library_revision_releases`
--
ALTER TABLE `library_revision_releases`
  ADD PRIMARY KEY (`library_revision_id`,`release_id`),
  ADD KEY `idx_library_revision_releases_release` (`release_id`);

--
-- Index för tabell `releases`
--
ALTER TABLE `releases`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_releases_version` (`game_version_id`),
  ADD KEY `idx_releases_screensaver_version` (`screensaver_version_id`);

--
-- Index för tabell `release_files`
--
ALTER TABLE `release_files`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_release_files_path` (`release_id`,`path`),
  ADD KEY `idx_release_files_content` (`content_id`);

--
-- Index för tabell `screensavers`
--
ALTER TABLE `screensavers`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_screensavers_identifier` (`identifier`),
  ADD KEY `idx_screensavers_enabled` (`enabled`),
  ADD KEY `idx_screensavers_name` (`name`),
  ADD KEY `idx_screensavers_developer` (`developer_id`);

--
-- Index för tabell `screensaver_versions`
--
ALTER TABLE `screensaver_versions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_screensaver_versions_saver_version` (`screensaver_id`,`version`),
  ADD KEY `idx_screensaver_versions_version` (`version`),
  ADD KEY `idx_screensaver_versions_published_at` (`published_at`);

--
-- Index för tabell `tags`
--
ALTER TABLE `tags`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_tags_identifier` (`identifier`),
  ADD KEY `idx_tags_kind` (`kind`),
  ADD KEY `idx_tags_group` (`group_id`);

--
-- Index för tabell `tag_groups`
--
ALTER TABLE `tag_groups`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_tag_groups_identifier` (`identifier`);

--
-- AUTO_INCREMENT för dumpade tabeller
--

--
-- AUTO_INCREMENT för tabell `api_tokens`
--
ALTER TABLE `api_tokens`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT för tabell `categories`
--
ALTER TABLE `categories`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT för tabell `category_rules`
--
ALTER TABLE `category_rules`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT för tabell `developers`
--
ALTER TABLE `developers`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT för tabell `file_contents`
--
ALTER TABLE `file_contents`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT för tabell `games`
--
ALTER TABLE `games`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT för tabell `game_versions`
--
ALTER TABLE `game_versions`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT för tabell `library_revisions`
--
ALTER TABLE `library_revisions`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT för tabell `releases`
--
ALTER TABLE `releases`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT för tabell `release_files`
--
ALTER TABLE `release_files`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT för tabell `screensavers`
--
ALTER TABLE `screensavers`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT för tabell `screensaver_versions`
--
ALTER TABLE `screensaver_versions`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT för tabell `tags`
--
ALTER TABLE `tags`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT för tabell `tag_groups`
--
ALTER TABLE `tag_groups`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

CREATE TABLE `highscores` (
  `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
  `game_id` bigint UNSIGNED NOT NULL,
  `table_index` smallint UNSIGNED NOT NULL,
  `name` varchar(255) COLLATE utf8mb4_bin NOT NULL,
  `score` double NOT NULL,
  `score_date` double NOT NULL,
  `fingerprint` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_highscore_entry` (`game_id`,`table_index`,`fingerprint`),
  KEY `idx_highscore_ranking` (`game_id`,`table_index`,`score` DESC,`score_date`,`fingerprint`),
  CONSTRAINT `fk_highscores_game` FOREIGN KEY (`game_id`) REFERENCES `games` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

--
-- Restriktioner för dumpade tabeller
--

--
-- Restriktioner för tabell `api_tokens`
--
ALTER TABLE `api_tokens`
  ADD CONSTRAINT `fk_api_tokens_developer` FOREIGN KEY (`developer_id`) REFERENCES `developers` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT;

--
-- Restriktioner för tabell `category_player_scores`
--
ALTER TABLE `category_player_scores`
  ADD CONSTRAINT `fk_category_player_scores_ranking` FOREIGN KEY (`category_id`) REFERENCES `category_ranking` (`category_id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Restriktioner för tabell `category_ranking`
--
ALTER TABLE `category_ranking`
  ADD CONSTRAINT `fk_category_ranking_category` FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Restriktioner för tabell `category_ranking_tags`
--
ALTER TABLE `category_ranking_tags`
  ADD CONSTRAINT `fk_category_ranking_tags_ranking` FOREIGN KEY (`category_id`) REFERENCES `category_ranking` (`category_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_category_ranking_tags_tag` FOREIGN KEY (`tag_id`) REFERENCES `tags` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE;

--
-- Restriktioner för tabell `category_rules`
--
ALTER TABLE `category_rules`
  ADD CONSTRAINT `fk_category_rules_category` FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Restriktioner för tabell `category_rule_tags`
--
ALTER TABLE `category_rule_tags`
  ADD CONSTRAINT `fk_category_rule_tags_rule` FOREIGN KEY (`rule_id`) REFERENCES `category_rules` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_category_rule_tags_tag` FOREIGN KEY (`tag_id`) REFERENCES `tags` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE;

--
-- Restriktioner för tabell `games`
--
ALTER TABLE `games`
  ADD CONSTRAINT `fk_games_developer` FOREIGN KEY (`developer_id`) REFERENCES `developers` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT;

--
-- Restriktioner för tabell `game_inputs`
--
ALTER TABLE `game_inputs`
  ADD CONSTRAINT `fk_game_inputs_game` FOREIGN KEY (`game_id`) REFERENCES `games` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Restriktioner för tabell `game_modes`
--
ALTER TABLE `game_modes`
  ADD CONSTRAINT `fk_game_modes_game` FOREIGN KEY (`game_id`) REFERENCES `games` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Restriktioner för tabell `game_tags`
--
ALTER TABLE `game_tags`
  ADD CONSTRAINT `fk_game_tags_game` FOREIGN KEY (`game_id`) REFERENCES `games` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_game_tags_tag` FOREIGN KEY (`tag_id`) REFERENCES `tags` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE;

--
-- Restriktioner för tabell `game_versions`
--
ALTER TABLE `game_versions`
  ADD CONSTRAINT `fk_game_versions_game` FOREIGN KEY (`game_id`) REFERENCES `games` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Restriktioner för tabell `library_revisions`
--
ALTER TABLE `library_revisions`
  ADD CONSTRAINT `fk_library_revision_manifest` FOREIGN KEY (`manifest_content_id`) REFERENCES `file_contents` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT;

--
-- Restriktioner för tabell `library_revision_files`
--
ALTER TABLE `library_revision_files`
  ADD CONSTRAINT `fk_revision_files_content` FOREIGN KEY (`content_id`) REFERENCES `file_contents` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  ADD CONSTRAINT `fk_revision_files_revision` FOREIGN KEY (`library_revision_id`) REFERENCES `library_revisions` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT;

--
-- Restriktioner för tabell `library_revision_releases`
--
ALTER TABLE `library_revision_releases`
  ADD CONSTRAINT `fk_library_revision_releases_release` FOREIGN KEY (`release_id`) REFERENCES `releases` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_library_revision_releases_revision` FOREIGN KEY (`library_revision_id`) REFERENCES `library_revisions` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Restriktioner för tabell `releases`
--
ALTER TABLE `releases`
  ADD CONSTRAINT `fk_releases_game_version_restrict` FOREIGN KEY (`game_version_id`) REFERENCES `game_versions` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  ADD CONSTRAINT `fk_releases_screensaver_version` FOREIGN KEY (`screensaver_version_id`) REFERENCES `screensaver_versions` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT;

--
-- Restriktioner för tabell `release_files`
--
ALTER TABLE `release_files`
  ADD CONSTRAINT `fk_release_files_content` FOREIGN KEY (`content_id`) REFERENCES `file_contents` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  ADD CONSTRAINT `fk_release_files_release` FOREIGN KEY (`release_id`) REFERENCES `releases` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Restriktioner för tabell `screensavers`
--
ALTER TABLE `screensavers`
  ADD CONSTRAINT `fk_screensavers_developer` FOREIGN KEY (`developer_id`) REFERENCES `developers` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT;

--
-- Restriktioner för tabell `screensaver_versions`
--
ALTER TABLE `screensaver_versions`
  ADD CONSTRAINT `fk_screensaver_versions_screensaver` FOREIGN KEY (`screensaver_id`) REFERENCES `screensavers` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Restriktioner för tabell `tags`
--
ALTER TABLE `tags`
  ADD CONSTRAINT `fk_tags_group` FOREIGN KEY (`group_id`) REFERENCES `tag_groups` (`id`) ON DELETE SET NULL ON UPDATE CASCADE;
--
-- Integrity constraints for the first-install baseline.
-- Validated after catalog seed data has been loaded.
--

ALTER TABLE `categories`
  ADD CONSTRAINT `chk_categories_identifier` CHECK (regexp_like(`identifier`,_utf8mb4'^[a-z][a-z0-9-]*$')),
  ADD CONSTRAINT `chk_categories_limit` CHECK (((`display_limit` is null) or (`display_limit` > 0)));

ALTER TABLE `category_player_scores`
  ADD CONSTRAINT `chk_category_player_scores` CHECK (((`min_players` > 0) and (`score` between 0 and 100)));

ALTER TABLE `category_ranking`
  ADD CONSTRAINT `chk_category_ranking_weights` CHECK (((`base_weight` <= 100) and (`modifier_weight` <= 100) and (`player_weight` <= 100) and (((`base_weight` + `modifier_weight`) + `player_weight`) = 100)));

ALTER TABLE `category_ranking_tags`
  ADD CONSTRAINT `chk_category_ranking_tags_weight` CHECK ((((`component` = _utf8mb4'base') and (`weight` is null)) or ((`component` = _utf8mb4'modifier') and (`weight` > 0) and (`weight` <= 100))));

ALTER TABLE `category_rules`
  ADD CONSTRAINT `chk_category_rules_shape` CHECK ((((`rule_type` in (_utf8mb4'base_any',_utf8mb4'tags_any')) and (`minimum_strength` > 0) and (`minimum_strength` <= 100) and (`mode` is null) and (`player_count` is null)) or ((`rule_type` = _utf8mb4'required_mode') and (`minimum_strength` is null) and (`mode` is not null) and (`player_count` is null)) or ((`rule_type` in (_utf8mb4'minimum_max_players',_utf8mb4'maximum_min_players',_utf8mb4'maximum_max_players')) and (`minimum_strength` is null) and (`mode` is null) and (`player_count` > 0))));

ALTER TABLE `game_tags`
  ADD CONSTRAINT `chk_game_tags_strength` CHECK ((`strength` between 0 and 100));

ALTER TABLE `games`
  ADD CONSTRAINT `chk_games_identifier` CHECK (regexp_like(`identifier`,_utf8mb4'^[a-z]{2,3}[.][a-z0-9-]{1,50}[.][a-zA-Z0-9_-]+$',_utf8mb4'c')),
  ADD CONSTRAINT `chk_games_promotion` CHECK (`promotion` between 0 and 100),
  ADD CONSTRAINT `chk_games_players` CHECK ((((`min_players` is null) and (`max_players` is null)) or ((`min_players` between 1 and 255) and (`max_players` between 1 and 255) and (`min_players` <= `max_players`))));

ALTER TABLE `library_revision_files`
  ADD CONSTRAINT `chk_revision_files_checksum` CHECK (regexp_like(`checksum`,_utf8mb4'^[0-9a-f]{64}$'));

ALTER TABLE `release_files`
  ADD CONSTRAINT `chk_release_files_checksum` CHECK (regexp_like(`checksum`,_ascii'^[0-9a-fA-F]{64}$'));

ALTER TABLE `releases`
  ADD CONSTRAINT `chk_releases_checksum` CHECK (regexp_like(`checksum`,_ascii'^[0-9a-fA-F]{64}$')),
  ADD CONSTRAINT `chk_releases_single_version` CHECK ((((`game_version_id` is not null) and (`screensaver_version_id` is null)) or ((`game_version_id` is null) and (`screensaver_version_id` is not null))));

ALTER TABLE `tag_groups`
  ADD CONSTRAINT `chk_tag_groups_identifier` CHECK (regexp_like(`identifier`,_utf8mb4'^[a-z][a-z0-9-]*$'));

ALTER TABLE `tags`
  ADD CONSTRAINT `chk_tags_identifier` CHECK (regexp_like(`identifier`,_utf8mb4'^[a-z][a-z0-9-]*$'));

COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
