CREATE TABLE descriptions (
	id int NOT NULL AUTO_INCREMENT,
	item char(10) NOT NULL,
	lang char(10) NOT NULL,
	random int NOT NULL,
	status ENUM('DONE', 'REPLACED', 'DELETED', 'NO'),
	description varchar(255) NOT NULL,
	PRIMARY KEY (id)
);

CREATE INDEX item_lang ON descriptions (item, lang);