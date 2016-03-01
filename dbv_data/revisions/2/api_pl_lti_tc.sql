CREATE TABLE IF NOT EXISTS `api_pl_lti_tc` (
`ID` bigint(20) NOT NULL,
  `idPlatform` bigint(20) NOT NULL,
  `lti_consumer_key` varchar(255) NOT NULL
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=latin1;


ALTER TABLE `api_pl_lti_tc`
 ADD PRIMARY KEY (`ID`), ADD UNIQUE KEY `lti_consumer_key` (`lti_consumer_key`);


ALTER TABLE `api_pl_lti_tc`
MODIFY `ID` bigint(20) NOT NULL AUTO_INCREMENT,AUTO_INCREMENT=2;
