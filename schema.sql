CREATE TABLE `action` (
  `channel_id` int(11) NOT NULL DEFAULT '0',
  `nick` varchar(255) NOT NULL DEFAULT '',
  `total` int(11) NOT NULL DEFAULT '0',
  PRIMARY KEY (`channel_id`,`nick`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

CREATE TABLE `active_times` (
  `channel_id` int(11) NOT NULL DEFAULT '0',
  `nick` varchar(255) NOT NULL DEFAULT '',
  `hour` tinyint(2) NOT NULL DEFAULT '0',
  `total` int(11) NOT NULL DEFAULT '0',
  PRIMARY KEY (`channel_id`,`nick`,`hour`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

CREATE TABLE `caps` (
  `channel_id` int(11) NOT NULL DEFAULT '0',
  `nick` varchar(255) NOT NULL DEFAULT '',
  `total` int(11) NOT NULL DEFAULT '0',
  PRIMARY KEY (`channel_id`,`nick`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

CREATE TABLE `channels` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `channel` varchar(255) NOT NULL DEFAULT '',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=latin1;

CREATE TABLE `characters` (
  `channel_id` int(11) NOT NULL DEFAULT '0',
  `nick` varchar(255) NOT NULL DEFAULT '',
  `total` int(11) NOT NULL DEFAULT '0',
  PRIMARY KEY (`channel_id`,`nick`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

CREATE TABLE `donated_ops` (
  `channel_id` int(11) NOT NULL DEFAULT '0',
  `nick` varchar(255) NOT NULL DEFAULT '',
  `total` int(11) NOT NULL DEFAULT '0',
  PRIMARY KEY (`channel_id`,`nick`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

CREATE TABLE `frown` (
  `channel_id` int(11) NOT NULL DEFAULT '0',
  `nick` varchar(255) NOT NULL DEFAULT '',
  `total` int(11) NOT NULL DEFAULT '0',
  PRIMARY KEY (`channel_id`,`nick`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

CREATE TABLE `ignore_nick` (
  `nick` varchar(255) NOT NULL DEFAULT '',
  PRIMARY KEY (`nick`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

CREATE TABLE `joins` (
  `channel_id` int(11) NOT NULL DEFAULT '0',
  `nick` varchar(255) NOT NULL DEFAULT '',
  `total` int(11) NOT NULL DEFAULT '0',
  PRIMARY KEY (`channel_id`,`nick`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

CREATE TABLE `kick_op` (
  `channel_id` int(11) NOT NULL DEFAULT '0',
  `nick` varchar(255) NOT NULL DEFAULT '',
  `total` int(11) NOT NULL DEFAULT '0',
  PRIMARY KEY (`channel_id`,`nick`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

CREATE TABLE `kick_victim` (
  `channel_id` int(11) NOT NULL DEFAULT '0',
  `nick` varchar(255) NOT NULL DEFAULT '',
  `total` int(11) NOT NULL DEFAULT '0',
  PRIMARY KEY (`channel_id`,`nick`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

CREATE TABLE `latest_quote` (
  `channel_id` int(11) NOT NULL DEFAULT '0',
  `nick` varchar(255) NOT NULL DEFAULT '',
  `quote` varchar(512) NOT NULL DEFAULT '',
  PRIMARY KEY (`channel_id`,`nick`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

CREATE TABLE `linecounts` (
  `channel_id` int(11) NOT NULL DEFAULT '0',
  `nick` varchar(255) NOT NULL DEFAULT '',
  `total` int(11) NOT NULL DEFAULT '0',
  PRIMARY KEY (`channel_id`,`nick`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

CREATE TABLE `monologue` (
  `channel_id` int(11) NOT NULL DEFAULT '0',
  `nick` varchar(255) NOT NULL DEFAULT '',
  `total` int(11) NOT NULL DEFAULT '0',
  PRIMARY KEY (`channel_id`,`nick`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

CREATE TABLE `profanity` (
  `channel_id` int(11) NOT NULL DEFAULT '0',
  `nick` varchar(255) NOT NULL DEFAULT '',
  `total` int(11) NOT NULL DEFAULT '0',
  PRIMARY KEY (`channel_id`,`nick`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

CREATE TABLE `question` (
  `channel_id` int(11) NOT NULL DEFAULT '0',
  `nick` varchar(255) NOT NULL DEFAULT '',
  `total` int(11) NOT NULL DEFAULT '0',
  PRIMARY KEY (`channel_id`,`nick`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

CREATE TABLE `reporting_times` (
  `type` char(1) NOT NULL DEFAULT 'C',
  `channel_id` int(11) NOT NULL DEFAULT '0',
  `time` int(11) NOT NULL DEFAULT '0',
  PRIMARY KEY (`channel_id`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

CREATE TABLE `revoked_ops` (
  `channel_id` int(11) NOT NULL DEFAULT '0',
  `nick` varchar(255) NOT NULL DEFAULT '',
  `total` int(11) NOT NULL DEFAULT '0',
  PRIMARY KEY (`channel_id`,`nick`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

CREATE TABLE `shout` (
  `channel_id` int(11) NOT NULL DEFAULT '0',
  `nick` varchar(255) NOT NULL DEFAULT '',
  `total` int(11) NOT NULL DEFAULT '0',
  PRIMARY KEY (`channel_id`,`nick`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

CREATE TABLE `smile` (
  `channel_id` int(11) NOT NULL DEFAULT '0',
  `nick` varchar(255) NOT NULL DEFAULT '',
  `total` int(11) NOT NULL DEFAULT '0',
  PRIMARY KEY (`channel_id`,`nick`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

CREATE TABLE `textstats` (
  `channel_id` int(11) NOT NULL DEFAULT '0',
  `nick` varchar(255) NOT NULL DEFAULT '',
  `messages` int(11) NOT NULL DEFAULT '0',
  `words` int(11) NOT NULL DEFAULT '0',
  `chars` int(11) NOT NULL DEFAULT '0',
  `avg_words` decimal(5,2) NOT NULL DEFAULT '0.00',
  `avg_chars` decimal(5,2) NOT NULL DEFAULT '0.00',
  PRIMARY KEY (`channel_id`,`nick`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

CREATE TABLE `violent` (
  `channel_id` int(11) NOT NULL DEFAULT '0',
  `nick` varchar(255) NOT NULL DEFAULT '',
  `total` int(11) NOT NULL DEFAULT '0',
  PRIMARY KEY (`channel_id`,`nick`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

CREATE TABLE `words` (
  `channel_id` int(11) NOT NULL DEFAULT '0',
  `nick` varchar(255) NOT NULL DEFAULT '',
  `total` int(11) NOT NULL DEFAULT '0',
  PRIMARY KEY (`channel_id`,`nick`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1;
