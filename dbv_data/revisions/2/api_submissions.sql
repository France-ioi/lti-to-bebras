CREATE TABLE IF NOT EXISTS `api_submissions` (
`ID` bigint(20) NOT NULL,
  `idUser` bigint(20) NOT NULL,
  `sTaskTextId` varchar(255) NOT NULL,
  `sAnswer` varchar(255) NOT NULL,
  `score` smallint(6) NOT NULL,
  `message` varchar(255) NOT NULL,
  `state` enum('validated','evaluated','evaluationFailed','') NOT NULL DEFAULT 'validated'
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

ALTER TABLE `api_submissions`
 ADD PRIMARY KEY (`ID`), ADD KEY `idUser` (`idUser`);

ALTER TABLE `api_submissions`
MODIFY `ID` bigint(20) NOT NULL AUTO_INCREMENT;
