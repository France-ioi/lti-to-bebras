CREATE TABLE IF NOT EXISTS `api_users_tasks` (
  `idUser` bigint(20) NOT NULL,
  `sTaskTextId` varchar(255) NOT NULL,
  `nbHintsGiven` smallint(2) NOT NULL DEFAULT '0',
  `nbSubmissions` smallint(3) NOT NULL DEFAULT '0',
  `bAccessSolution` tinyint(1) NOT NULL DEFAULT '0'
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

ALTER TABLE `api_users_tasks`
 ADD PRIMARY KEY (`idUser`,`sTaskTextId`);