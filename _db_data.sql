CREATE DATABASE IF NOT EXISTS `microservice`;
USE `microservice`;

CREATE TABLE `tasks` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `device` varchar(1024) NOT NULL,
  `path` varchar(1024) NOT NULL,
  `method` varchar(16) NOT NULL,
  `datum` varchar(4096) DEFAULT NULL,
  `added_timestamp` timestamp NOT NULL DEFAULT current_timestamp(),
  `process_at_timestamp` timestamp NOT NULL DEFAULT current_timestamp(),
  `in_process` enum('true','false') DEFAULT 'false',
  `in_process_since_timestamp` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `device` (`device`(22))
) ENGINE=memory max_rows=1000000 DEFAULT CHARSET=utf8;

CREATE TABLE IF NOT EXISTS `data` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `device` varchar(1024) NOT NULL,
  `path` varchar(1024) NOT NULL,
  `datum` varchar(4096) DEFAULT NULL,
  `no_refresh` enum('true','false') DEFAULT 'false',
  `last_queried_timestamp` timestamp NOT NULL DEFAULT current_timestamp(),
  `last_refreshed_timestamp` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `main` (`device`(22),`path`(22))
) ENGINE=memory max_rows=1000000 DEFAULT CHARSET=utf8;

CREATE TABLE `errors` (
  `device` varchar(1024) NOT NULL,
  `message` varchar(4096) DEFAULT NULL,
  `added_timestamp` timestamp NOT NULL DEFAULT current_timestamp(),
  KEY `device` (`device`(22))
) ENGINE=memory max_rows=1000000 DEFAULT CHARSET=utf8;