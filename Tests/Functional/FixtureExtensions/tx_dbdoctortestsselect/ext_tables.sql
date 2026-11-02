CREATE TABLE tx_dbdoctortestsselect_item
(
    title tinytext NOT NULL,
    record_type varchar(255) DEFAULT '' NOT NULL,
    relations text,
    relation_single int(11) DEFAULT '0' NOT NULL,
    category_single int(11) DEFAULT '0' NOT NULL,
    category_multi text
);
