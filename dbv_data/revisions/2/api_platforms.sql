CREATE TABLE IF NOT EXISTS `api_platforms` (
`ID` bigint(20) NOT NULL,
  `private_key` varchar(1000) NOT NULL,
  `name` varchar(50) NOT NULL
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=latin1;

ALTER TABLE `api_platforms`
 ADD PRIMARY KEY (`ID`), ADD KEY `name` (`name`);

ALTER TABLE `api_platforms`
MODIFY `ID` bigint(20) NOT NULL AUTO_INCREMENT,AUTO_INCREMENT=2;
