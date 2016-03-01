CREATE TABLE IF NOT EXISTS `api_users` (
`ID` bigint(20) NOT NULL,
  `lti_consumer_key` varchar(255) NOT NULL,
  `lti_context_id` varchar(255) NOT NULL,
  `lti_user_id` varchar(255) NOT NULL,
  `loginID` bigint(20) DEFAULT NULL,
  `firstName` varchar(50) DEFAULT NULL,
  `lastName` varchar(50) DEFAULT NULL,
  `email` varchar(50) DEFAULT NULL,
  `lis_return_url` varchar(255) DEFAULT NULL
) ENGINE=InnoDB AUTO_INCREMENT=34 DEFAULT CHARSET=latin1;

ALTER TABLE `api_users`
 ADD PRIMARY KEY (`ID`), ADD UNIQUE KEY `lti_user` (`lti_consumer_key`,`lti_context_id`,`lti_user_id`);

ALTER TABLE `api_users`
MODIFY `ID` bigint(20) NOT NULL AUTO_INCREMENT,AUTO_INCREMENT=34;