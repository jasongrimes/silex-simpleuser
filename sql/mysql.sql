CREATE TABLE `users` (
  `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `email` VARCHAR(100) NOT NULL DEFAULT '',
  `password` VARCHAR(255) DEFAULT NULL,
  `salt` VARCHAR(255) NOT NULL DEFAULT '',
  `roles` VARCHAR(255) NOT NULL DEFAULT '',
  `name` VARCHAR(100) NOT NULL DEFAULT '',
  `time_created` INT(11) UNSIGNED NOT NULL DEFAULT 0,
  `username` VARCHAR(100),
  `isEnabled` TINYINT(1) NOT NULL DEFAULT 1,
  `confirmationToken` VARCHAR(100),
  `timePasswordResetRequested` INT(11) UNSIGNED,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_email` (`email`),
  UNIQUE KEY `username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;


CREATE TABLE `user_custom_fields` (
  user_id INT(11) UNSIGNED NOT NULL,
  attribute VARCHAR(50) NOT NULL DEFAULT '',
  value VARCHAR(255) DEFAULT NULL,
  PRIMARY KEY (user_id, attribute)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;
