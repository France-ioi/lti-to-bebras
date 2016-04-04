ALTER TABLE `api_users_tasks` ADD `sState` TEXT NULL DEFAULT NULL ;
ALTER TABLE `api_submissions` ADD `sDate` DATETIME NULL DEFAULT NULL ;
ALTER TABLE `api_task_platforms` ADD `bUsesTokens` TINYINT(1) NOT NULL DEFAULT '1' , ADD `bAppendIdToUrl` TINYINT(1) NOT NULL DEFAULT '0' ;
ALTER TABLE `api_task_platforms` CHANGE `public_key` `public_key` VARCHAR(500) CHARACTER SET latin1 COLLATE latin1_swedish_ci NULL DEFAULT NULL;
ALTER TABLE `api_platforms` ADD `public_key` VARCHAR(500) NULL DEFAULT NULL AFTER `private_key`;