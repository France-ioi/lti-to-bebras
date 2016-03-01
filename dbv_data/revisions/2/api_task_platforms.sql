CREATE TABLE IF NOT EXISTS `api_task_platforms` (
`ID` bigint(20) NOT NULL,
  `public_key` varchar(500) NOT NULL,
  `name` varchar(50) NOT NULL,
  `url` varchar(150) DEFAULT NULL
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=latin1;

ALTER TABLE `api_task_platforms`
 ADD PRIMARY KEY (`ID`);

ALTER TABLE `api_task_platforms`
MODIFY `ID` bigint(20) NOT NULL AUTO_INCREMENT,AUTO_INCREMENT=2;